<?php

namespace App\Services\Bot;

use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Jobs\AdvanceBotSessionJob;
use App\Models\BotSession;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\WorkflowVersion;
use Carbon\CarbonInterface;

/**
 * A porta de entrada do pré-atendimento: decide se uma mensagem do cliente pertence ao bot e entrega ao motor. É o único
 * ponto que sabe qual condutor fala com o cliente (hoje o workflow; um LLM pode entrar aqui no futuro).
 */
class BotEntry
{
    /** Mensagem mais velha que isto é histórico reprocessado, não cliente esperando. */
    private const FRESH_SECONDS = 300;

    public function __construct(private readonly BotEngine $engine) {}

    /** A versão publicada do workflow ativo do canal, ou nulo quando o canal não usa bot. */
    public function versionFor(Channel $channel): ?WorkflowVersion
    {
        return $channel->workflow()->first()?->latestVersion();
    }

    /** Um ticket novo nasce `pendente` quando o canal tem workflow e o cliente escreveu agora. */
    public function shouldStart(Channel $channel, CarbonInterface $sentAt): bool
    {
        return abs(now()->diffInSeconds($sentAt)) <= self::FRESH_SECONDS && $this->versionFor($channel) !== null;
    }

    /** Chamado depois do commit da mensagem do cliente. */
    public function handle(Message $incoming): void
    {
        $status = Ticket::query()->whereKey($incoming->ticket_id)->value('status');

        if ($status === TicketStatus::PENDENTE->value || $status === TicketStatus::PENDENTE) {
            AdvanceBotSessionJob::dispatch($incoming->ticket_id, $incoming->id)->afterCommit();
        }
    }

    /** Roda no job, sob o lock do ticket: inicia a sessão ou entrega a resposta a ela. */
    public function advance(int $ticketId, int $messageId): void
    {
        $ticket = Ticket::query()->find($ticketId);
        $message = Message::query()->find($messageId);

        if ($ticket === null || $message === null || $ticket->status !== TicketStatus::PENDENTE) {
            return;
        }

        $session = $ticket->botSession()->first();

        if ($session === null) {
            $this->begin($ticket, $message);
        } elseif ($this->isAnswer($session, $message)) {
            $session->update(['last_message_id' => $message->id]);
            $this->engine->receive($session, $message);
        }
    }

    /** Primeira mensagem do ticket: abre a sessão na versão publicada (ou, sem workflow, libera o ticket para a fila). */
    private function begin(Ticket $ticket, Message $message): void
    {
        $version = $this->versionFor($ticket->channel()->firstOrFail());

        if ($version === null) {
            $this->engine->release($ticket);

            return;
        }

        $this->engine->start($ticket, $version)->update(['last_message_id' => $message->id]);
    }

    /**
     * A mensagem responde ao bot: a sessão segue ativa, a mensagem é nova e não foi escrita antes de a conversa começar
     * (cliente que mandou "oi" duas vezes: a segunda não é resposta ao menu).
     */
    private function isAnswer(BotSession $session, Message $message): bool
    {
        return $session->state === BotSession::ACTIVE
            && ($session->last_message_id === null || $message->id > $session->last_message_id)
            && ($session->created_at === null || ! $message->sent_at->lt($session->created_at));
    }

    /** Alguém respondeu pelo celular: a triagem automática acaba e o ticket segue com humanos. */
    public function humanTookOver(Ticket $ticket): void
    {
        $changed = Ticket::query()->whereKey($ticket->id)->where('status', TicketStatus::PENDENTE->value)->update([
            'status' => TicketStatus::EM_ATENDIMENTO->value,
            'updated_at' => now(),
        ]) === 1;

        if (! $changed) {
            return;
        }

        BotSession::query()->where('ticket_id', $ticket->id)->active()->update(['state' => BotSession::ABANDONED, 'waiting' => null, 'expires_at' => null, 'updated_at' => now()]);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'bot_abandoned']);
        TicketUpdated::dispatch($ticket->refresh());
    }
}
