<?php

namespace App\Services\Bot;

use App\Models\BotSession;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WorkflowVersion;
use App\Services\Bot\Effects\LiveEffects;
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

/**
 * Conduz a conversa do cliente pelo grafo publicado. Uma sessão por ticket; o ticket fica `pendente` até o bot
 * entregá-lo à fila de um setor (`aguardando`) ou encerrá-lo. Quem chama garante que só uma execução por ticket roda
 * por vez (ver `AdvanceBotSessionJob`). Tudo que sai do motor passa pelos efeitos do `BotRun`: o simulador do editor usa
 * este mesmo motor com efeitos que só registram.
 */
class BotEngine
{
    /** Teto de blocos por execução: protege de um laço que passou despercebido. */
    public const MAX_STEPS = 50;

    public function start(Ticket $ticket, WorkflowVersion $version): BotSession
    {
        $flow = FlowGraph::fromArray($version->graph);

        $session = BotSession::create([
            'ticket_id' => $ticket->id,
            'workflow_version_id' => $version->id,
            'node_id' => $flow->start(),
            'vars' => [],
            'state' => BotSession::ACTIVE,
        ]);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'bot_started', 'payload' => ['workflow_version_id' => $version->id]]);

        $this->begin(new BotRun($this, $session, $flow, new LiveEffects($ticket)));

        return $session;
    }

    /** A resposta do cliente ao bloco em que a sessão está esperando. */
    public function receive(BotSession $session, Message $message): void
    {
        $this->reply($this->liveRun($session), $message->type === 'text' ? trim($message->body) : '');
    }

    /** Roda a conversa a partir do Início. */
    public function begin(BotRun $run): void
    {
        $this->run($run, $run->flow->start());
    }

    /** A resposta do cliente ao bloco em que a sessão está esperando; "#sair" leva à fila em qualquer bloco. */
    public function reply(BotRun $run, string $text): void
    {
        if ($run->session->state !== BotSession::ACTIVE) {
            return;
        }

        if (ChoiceReply::wantsOut($text)) {
            $this->deliver($run, 'sair');

            return;
        }

        $id = $run->session->node_id;
        $type = $id === null ? null : $run->flow->type($id);

        if ($id === null || $type === null || ! $type->waits()) {
            return;
        }

        $this->advance($run, $id, $this->node($type)->answer($run, $run->flow->data($id), $text));
    }

    /**
     * Entrega o ticket à fila: o setor pedido, se ainda recebe tickets, senão o setor padrão (o primeiro do menu); sem
     * nenhum setor, a fila geral (ou ela de propósito, com `$generalQueue`). Só vale uma vez por sessão.
     */
    public function handoff(BotSession $session, string $reason, ?Sector $to = null, ?User $assignTo = null, bool $generalQueue = false): void
    {
        $this->deliver($this->liveRun($session), $reason, $to, $assignTo, $generalQueue);
    }

    /** O mesmo que `handoff`, para uma execução já montada (a de produção ou a do simulador). */
    public function deliver(BotRun $run, string $reason, ?Sector $to = null, ?User $assignTo = null, bool $generalQueue = false): void
    {
        $run->fx->exclusive($run->session, function () use ($run, $reason, $to, $assignTo, $generalQueue): void {
            $sector = match (true) {
                $generalQueue => null,
                $to !== null && $to->status->receivesTickets() => $to,
                default => $this->defaultSector(),
            };

            if ($reason !== 'fluxo') {
                $vars = [...$run->vars(), 'setor' => $sector !== null ? $sector->name : ''];

                $run->fx->say(Vars::render(BotMessages::text($run->flow, $sector !== null ? 'encaminhamento_setor' : 'encaminhamento_geral'), $vars));
            }

            $assigned = $run->fx->enterQueue($sector, $assignTo);

            $run->session->fill(['state' => BotSession::TRANSFERRED, 'waiting' => null, 'expires_at' => null]);
            $run->fx->save($run->session);
            $run->fx->logHandoff($reason, $sector, $assigned);
        });
    }

    /** O canal deixou de ter workflow antes de a conversa começar: o ticket vai direto à fila geral. */
    public function release(Ticket $ticket): void
    {
        (new LiveEffects($ticket))->enterQueue(null, null);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'bot_handoff', 'payload' => ['reason' => 'sem_workflow']]);
    }

    /** O primeiro setor, na ordem do menu, que recebe tickets. */
    public function defaultSector(): ?Sector
    {
        return Sector::query()->receivingTickets()->orderBy('menu_order')->orderBy('id')->first();
    }

    private function liveRun(BotSession $session): BotRun
    {
        return new BotRun(
            $this,
            $session,
            FlowGraph::fromArray($session->version()->firstOrFail()->graph),
            new LiveEffects($session->ticket()->firstOrFail()),
        );
    }

    private function run(BotRun $run, ?string $id): void
    {
        for ($steps = 0; $steps < self::MAX_STEPS; $steps++) {
            $type = $id === null ? null : $run->flow->type($id);

            if ($id === null || $type === null) {
                $this->deliver($run, 'erros');

                return;
            }

            $run->fx->entered($id);
            $run->session->attempts = 0;
            $step = $this->node($type)->enter($run, $run->flow->data($id));

            if ($step->kind !== Step::NEXT) {
                $this->settle($run, $id, $step);

                return;
            }

            $id = $run->flow->next($id, $step->port);
        }

        $this->deliver($run, 'erros');
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
            Step::WAIT => $this->wait($run, $id, $step),
            Step::LEAVE => $this->deliver($run, $step->reason),
            default => null,
        };
    }

    private function wait(BotRun $run, string $id, Step $step): void
    {
        $run->session->fill([
            'node_id' => $id,
            'waiting' => $step->waiting,
            'expires_at' => now()->addMinutes($step->minutes),
        ]);
        $run->fx->save($run->session);
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
