<?php

namespace App\Services\Bot;

use App\Actions\Inbox\ClaimTicket;
use App\Actions\Inbox\TransferTicket;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Jobs\SendTicketMessageJob;
use App\Models\BotSession;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WorkflowVersion;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Bot\Flow\FlowGraph;
use App\Services\Bot\Flow\NodeType;
use App\Services\Bot\Nodes\CondicaoNode;
use App\Services\Bot\Nodes\EncerrarNode;
use App\Services\Bot\Nodes\EsperarNode;
use App\Services\Bot\Nodes\EtiquetarNode;
use App\Services\Bot\Nodes\InicioNode;
use App\Services\Bot\Nodes\MensagemNode;
use App\Services\Bot\Nodes\MenuNode;
use App\Services\Bot\Nodes\MenuSetoresNode;
use App\Services\Bot\Nodes\MidiaNode;
use App\Services\Bot\Nodes\Node;
use App\Services\Bot\Nodes\NotaNode;
use App\Services\Bot\Nodes\PerguntaNode;
use App\Services\Bot\Nodes\SalvarContatoNode;
use App\Services\Bot\Nodes\TransferirNode;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Support\Facades\DB;

/**
 * Conduz a conversa do cliente pelo grafo publicado. Uma sessão por ticket; o ticket fica `pendente` até o bot
 * entregá-lo à fila de um setor (`aguardando`) ou encerrá-lo. Quem chama garante que só uma execução por ticket roda
 * por vez (ver `AdvanceBotSessionJob`).
 */
class BotEngine
{
    /** Teto de blocos por execução: protege de um laço que passou despercebido. */
    public const MAX_STEPS = 50;

    public function __construct(private readonly SendTicketMessage $send) {}

    public function start(Ticket $ticket, WorkflowVersion $version): BotSession
    {
        $flow = FlowGraph::fromArray($version->graph);
        $start = $flow->start();

        $session = BotSession::create([
            'ticket_id' => $ticket->id,
            'workflow_version_id' => $version->id,
            'node_id' => $start,
            'vars' => [],
            'state' => BotSession::ACTIVE,
        ]);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'bot_started', 'payload' => ['workflow_version_id' => $version->id]]);

        $this->run(new BotRun($this, $session, $ticket, $flow), $start);

        return $session;
    }

    /** A resposta do cliente ao bloco em que a sessão está esperando. */
    public function receive(BotSession $session, Message $message): void
    {
        if ($session->state !== BotSession::ACTIVE) {
            return;
        }

        $text = $message->type === 'text' ? trim($message->body) : '';

        if (ChoiceReply::wantsOut($text)) {
            $this->handoff($session, 'sair');

            return;
        }

        $ticket = $session->ticket()->firstOrFail();
        $flow = FlowGraph::fromArray($session->version()->firstOrFail()->graph);
        $id = $session->node_id;
        $type = $id === null ? null : $flow->type($id);

        if ($id === null || $type === null || ! $type->waits()) {
            return;
        }

        $run = new BotRun($this, $session, $ticket, $flow);
        $this->advance($run, $id, $this->node($type)->answer($run, $flow->data($id), $text));
    }

    /**
     * Entrega o ticket à fila: o setor pedido, se ainda recebe tickets, senão o setor padrão (o primeiro do menu); sem
     * nenhum setor, a fila geral (ou ela de propósito, com `$generalQueue`). Só vale uma vez por sessão.
     */
    public function handoff(BotSession $session, string $reason, ?Sector $to = null, ?User $assignTo = null, bool $generalQueue = false): void
    {
        DB::transaction(function () use ($session, $reason, $to, $assignTo, $generalQueue): void {
            $locked = BotSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if ($locked === null || $locked->state !== BotSession::ACTIVE) {
                return;
            }

            $ticket = Ticket::query()->findOrFail($locked->ticket_id);
            $sector = match (true) {
                $generalQueue => null,
                $to !== null && $to->status->receivesTickets() => $to,
                default => $this->defaultSector(),
            };

            if ($reason !== 'fluxo') {
                $flow = FlowGraph::fromArray($locked->version()->firstOrFail()->graph);
                $vars = [...(new BotRun($this, $locked, $ticket, $flow))->vars(), 'setor' => $sector !== null ? $sector->name : ''];

                $this->say($ticket, Vars::render(BotMessages::text($flow, $sector !== null ? 'encaminhamento_setor' : 'encaminhamento_geral'), $vars));
            }

            $assigned = $this->queueAndAssign($ticket, $sector, $assignTo);

            $locked->update(['state' => BotSession::TRANSFERRED, 'waiting' => null, 'expires_at' => null]);
            TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'bot_handoff', 'payload' => ['reason' => $reason, 'sector_id' => $sector?->id, ...($assigned !== null ? ['assigned_user_id' => $assigned] : [])]]);
        });

        $session->refresh();
    }

    /** O canal deixou de ter workflow antes de a conversa começar: o ticket vai direto à fila geral. */
    public function release(Ticket $ticket): void
    {
        $this->enterQueue($ticket, null);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'bot_handoff', 'payload' => ['reason' => 'sem_workflow']]);
    }

    /** O primeiro setor, na ordem do menu, que recebe tickets. */
    public function defaultSector(): ?Sector
    {
        return Sector::query()->receivingTickets()->orderBy('menu_order')->orderBy('id')->first();
    }

    /** Grava e despacha uma mensagem do bot; visto e "digitando" saem em `SendTicketMessage::deliver`, como no envio humano. */
    public function say(Ticket $ticket, string $text): void
    {
        if (trim($text) === '') {
            return;
        }

        $message = $this->send->create($ticket, null, $text, MessageOrigin::BOT);
        SendTicketMessageJob::dispatch($message->id)->afterCommit();
    }

    private function run(BotRun $run, ?string $id): void
    {
        for ($steps = 0; $steps < self::MAX_STEPS; $steps++) {
            $type = $id === null ? null : $run->flow->type($id);

            if ($id === null || $type === null) {
                $this->handoff($run->session, 'erros');

                return;
            }

            $run->session->attempts = 0;
            $step = $this->node($type)->enter($run, $run->flow->data($id));

            if ($step->kind !== Step::NEXT) {
                $this->settle($run, $id, $step);

                return;
            }

            $id = $run->flow->next($id, $step->port);
        }

        $this->handoff($run->session, 'erros');
    }

    private function advance(BotRun $run, string $from, Step $step): void
    {
        if ($step->kind === Step::NEXT) {
            $run->session->attempts = 0;
            $this->run($run, $run->flow->next($from, $step->port));

            return;
        }

        $this->settle($run, $from, $step);
    }

    private function settle(BotRun $run, string $id, Step $step): void
    {
        match ($step->kind) {
            Step::WAIT => $run->session->update([
                'node_id' => $id,
                'waiting' => $step->waiting,
                'expires_at' => now()->addMinutes($step->minutes),
            ]),
            Step::LEAVE => $this->handoff($run->session, $step->reason),
            default => null,
        };
    }

    /** Passa o ticket (já na fila) à pessoa; devolve o id dela, ou nulo se ele já tinha dono ou foi assumido antes. */
    private function assign(Ticket $ticket, ?User $user): ?int
    {
        if ($user === null || ! (new ClaimTicket)($ticket, $user)) {
            return null;
        }

        app(AlertDispatcher::class)->assigned($ticket, $user, null);

        return $user->id;
    }

    /**
     * Entra na fila e, se a pessoa escolhida pode, passa o ticket a ela; devolve o id de quem assumiu. Com atribuição possível a
     * fila não toca o som; se a atribuição falhar, ela é avisada em seguida.
     */
    private function queueAndAssign(Ticket $ticket, ?Sector $sector, ?User $assignTo): ?int
    {
        $canAssign = $this->canAssign($assignTo, $sector);

        $this->enterQueue($ticket, $sector, announce: ! $canAssign);
        $assigned = $canAssign ? $this->assign($ticket, $assignTo) : null;

        if ($canAssign && $assigned === null) {
            app(AlertDispatcher::class)->queued($ticket->refresh());
        }

        return $assigned;
    }

    /** A pessoa existe, está ativa e atende o setor (ticket sem setor é de todos). */
    private function canAssign(?User $user, ?Sector $sector): bool
    {
        return $user !== null && $user->active && $user->canHandleSector($sector?->id);
    }

    private function enterQueue(Ticket $ticket, ?Sector $sector, bool $announce = true): void
    {
        if ($sector !== null) {
            (new TransferTicket)($ticket, null, $sector, announce: $announce);

            return;
        }

        // Nenhum setor recebe tickets: vai para a fila geral, que o atendente sem setor e os supervisores veem.
        $moved = Ticket::query()->whereKey($ticket->id)->where('status', TicketStatus::PENDENTE->value)->update([
            'status' => TicketStatus::AGUARDANDO->value,
            'waiting_since' => now(),
            'sla_warn_at' => null,
            'sla_due_at' => null,
            'sla_warned_at' => null,
            'sla_breached_at' => null,
            'updated_at' => now(),
        ]) === 1;

        if ($moved) {
            TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'transferred', 'payload' => []]);
            $ticket->refresh();
            TicketUpdated::dispatch($ticket);
            if ($announce) {
                app(AlertDispatcher::class)->queued($ticket);
            }
        }
    }

    private function node(NodeType $type): Node
    {
        return match ($type) {
            NodeType::Inicio => new InicioNode,
            NodeType::Mensagem => new MensagemNode,
            NodeType::MenuSetores => new MenuSetoresNode,
            NodeType::Menu => new MenuNode,
            NodeType::Transferir => new TransferirNode,
            NodeType::Encerrar => new EncerrarNode,
            NodeType::Pergunta => new PerguntaNode,
            NodeType::Condicao => new CondicaoNode,
            NodeType::Esperar => new EsperarNode,
            NodeType::Midia => new MidiaNode,
            NodeType::SalvarContato => new SalvarContatoNode,
            NodeType::Etiquetar => new EtiquetarNode,
            NodeType::Nota => new NotaNode,
        };
    }
}
