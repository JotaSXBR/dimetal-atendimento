<?php

namespace App\Services\Inbox;

use App\Enums\ClosedReason;
use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Events\MessageCreated;
use App\Events\TicketUpdated;
use App\Jobs\ResolveMessageMediaJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\WahaEvent;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Bot\BotEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Transforma um evento `message`/`message.any` do WAHA em contato + ticket + mensagem.
 *
 * Idempotente: o mesmo `wa_message_id` no mesmo canal nunca gera segunda linha.
 */
class IngestMessage
{
    /** Janela para casar o eco `fromMe` com a mensagem que o painel gravou antes de enviar. */
    private const ECHO_WINDOW_SECONDS = 60;

    /** A resposta automática tem texto fixo e ninguém a digita no celular: casa por mais tempo, para o eco tardio não virar "fora do painel" e parar o relógio de SLA. */
    private const BOT_ECHO_WINDOW_SECONDS = 600;

    /** Até quanto atraso uma mensagem ao vivo ainda conta como cliente esperando (queda do número, reentrega do WhatsApp). */
    private const LATE_LIVE_SECONDS = 1800;

    public function __construct(private readonly ResolveContact $resolveContact) {}

    public function __invoke(WahaEvent $event): ?Message
    {
        $channel = $event->channel;
        $incoming = $channel === null ? null : IncomingMessage::fromEvent($event);

        if ($channel === null || $incoming === null) {
            return null;
        }

        if (($existing = $this->find($channel, $incoming->waId)) !== null) {
            return $existing;
        }

        $contact = ($this->resolveContact)($incoming->peer, $incoming->alternateJid, $incoming->pushName);
        $mode = IngestMode::of($event);

        // Já há ticket aberto do contato que começou antes desta mensagem: ela pertence a ele, não ao histórico.
        if ($mode === IngestMode::IMPORTED && $this->hasOpenTicketBefore($channel, $contact, $incoming)) {
            $mode = IngestMode::RECOVERED;
        }

        if ($incoming->fromMe && in_array($incoming->type, ['text', 'media'], true) && ($pending = $this->matchPending($channel, $contact, $incoming)) !== null) {
            return $this->adoptEcho($pending, $incoming);
        }

        $freshAt = $this->freshAt($event, $incoming, $mode);

        return $this->storeOnce($channel, $contact, $incoming, $mode, $freshAt, $event->received_at);
    }

    /**
     * A hora que o bot e a resposta automática chamam de "recente". Ao vivo e com até 30 min de atraso, é a hora em que a
     * mensagem chegou: o cliente que escreveu com o número offline e foi reentregue na volta está esperando agora. Mais velha
     * que isso, ou recuperada e importada, vale a hora de envio (é histórico reprocessado, não cliente esperando).
     */
    private function freshAt(WahaEvent $event, IncomingMessage $incoming, IngestMode $mode): CarbonInterface
    {
        $late = $event->received_at->diffInSeconds($incoming->sentAt, true) <= self::LATE_LIVE_SECONDS;

        return $mode === IngestMode::LIVE && $late ? $event->received_at : $incoming->sentAt;
    }

    private function find(Channel $channel, string $waId): ?Message
    {
        return Message::query()->where('channel_id', $channel->id)->where('wa_message_id', $waId)->first();
    }

    /**
     * O eco da mensagem do painel chegou: a linha local ganha o id do WhatsApp em vez de virar uma segunda.
     */
    private function adoptEcho(Message $pending, IncomingMessage $incoming): Message
    {
        $ticket = $pending->ticket()->firstOrFail();

        // O eco prova que saiu: uma linha que tinha falhado (ex.: timeout sem resposta) volta a "enviada".
        $pending->update([
            'wa_message_id' => $incoming->waId,
            'delivery_status' => $pending->delivery_status === DeliveryStatus::FAILED ? DeliveryStatus::SENT : $pending->delivery_status,
            'failure_reason' => null,
            'failure_code' => null,
        ]);
        // O eco da resposta automática não é trabalho humano (ver `SendTicketMessage::deliver`).
        if ($pending->origin !== MessageOrigin::BOT) {
            $ticket->recordOutbound($incoming->sentAt);
        }

        TicketUpdated::dispatch($ticket);

        return $pending;
    }

    private function storeOnce(Channel $channel, Contact $contact, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): Message
    {
        try {
            return DB::transaction(fn () => $this->store($channel, $contact, $incoming, $mode, $freshAt, $arrivedAt));
        } catch (UniqueConstraintViolationException $e) {
            // Corrida com outro job da mesma mensagem: a linha dele vale, a nossa é descartada.
            return $this->find($channel, $incoming->waId) ?? throw $e;
        }
    }

    /**
     * A linha do painel que este eco `fromMe` representa. Anexo casa por tipo e legenda, não pelo tipo do arquivo: o
     * WhatsApp recomprime imagens e converte a voz, então o `mimetype` do eco não é o que enviamos.
     */
    private function matchPending(Channel $channel, Contact $contact, IncomingMessage $incoming): ?Message
    {
        return Message::query()
            ->where('channel_id', $channel->id)
            ->where('contact_id', $contact->id)
            ->where('direction', MessageDirection::OUT->value)
            ->where(fn ($q) => $q
                ->where(fn ($panel) => $panel->where('origin', MessageOrigin::PAINEL->value)->where('updated_at', '>=', now()->subSeconds(self::ECHO_WINDOW_SECONDS)))
                ->orWhere(fn ($bot) => $bot->where('origin', MessageOrigin::BOT->value)->where('updated_at', '>=', now()->subSeconds(self::BOT_ECHO_WINDOW_SECONDS))))
            ->whereNull('wa_message_id')
            ->where(fn ($q) => $q->whereNull('delivery_status')
                // Só a falha "sem resposta" pode ter saído; as recusadas pelo WAHA não são engolidas pelo eco do celular.
                ->orWhere(fn ($failed) => $failed->where('delivery_status', DeliveryStatus::FAILED->value)->where('failure_code', SendFailure::UNKNOWN)))
            ->where('type', $incoming->type)
            ->where('body', $incoming->body)
            ->oldest('id')
            ->first();
    }

    private function store(Channel $channel, Contact $contact, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): Message
    {
        $ticket = $this->ticketFor($channel, $contact, $incoming, $mode, $freshAt, $arrivedAt);
        // Importada é histórico: não abre nem fecha espera de resposta, não gera evento de linha do tempo nem tempo real.
        $imported = $mode === IngestMode::IMPORTED;

        if (! $imported) {
            $this->recordActivity($ticket, $incoming);
        }

        $message = Message::create($this->attributes($ticket, $contact, $incoming));

        if (! $imported) {
            $this->announce($message, $ticket, $incoming, $mode, $freshAt, $arrivedAt);
        }

        if ($message->hasMedia()) {
            ResolveMessageMediaJob::dispatch($message->id, $mode === IngestMode::LIVE ? 'inbound' : 'media')->afterCommit();
        }

        return $message;
    }

    private function recordActivity(Ticket $ticket, IncomingMessage $incoming): void
    {
        $incoming->fromMe ? $ticket->recordOutbound($incoming->sentAt) : $ticket->recordInbound($incoming->sentAt);
    }

    /** @return array<string, mixed> */
    private function attributes(Ticket $ticket, Contact $contact, IncomingMessage $incoming): array
    {
        return [
            'ticket_id' => $ticket->id,
            'channel_id' => $ticket->channel_id,
            'contact_id' => $contact->id,
            'wa_message_id' => $incoming->waId,
            'direction' => $incoming->fromMe ? MessageDirection::OUT : MessageDirection::IN,
            'type' => $incoming->type,
            'body' => $incoming->body,
            'media_ref' => $incoming->media?->ref,
            'media_mime' => $incoming->media?->mime,
            'media_name' => $incoming->media?->name,
            'media_size' => $incoming->media?->size,
            'origin' => $incoming->fromMe ? MessageOrigin::FORA_DO_PAINEL : MessageOrigin::CONTATO,
            'sent_at' => $incoming->sentAt,
            'delivery_status' => $incoming->fromMe ? (DeliveryStatus::fromAck($incoming->ack) ?? DeliveryStatus::SENT) : null,
        ];
    }

    /** Linha do tempo, tempo real e, para mensagem do cliente, as respostas automáticas (só depois do commit). */
    private function announce(Message $message, Ticket $ticket, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): void
    {
        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'type' => $incoming->fromMe ? 'message_out' : 'message_in',
            'payload' => ['message_id' => $message->id],
        ]);

        // Recuperada não toca o som de "mensagem nova" nem manda "visto" ao cliente por mensagem antiga: só a lista e o ticket se atualizam.
        if ($mode === IngestMode::LIVE) {
            MessageCreated::dispatch($message);
        }

        TicketUpdated::dispatch($ticket);

        // Ao vivo, o bot e a resposta fora do horário valem mesmo que o job tenha atrasado; recuperada só se o cliente ainda espera.
        if (! $incoming->fromMe && ($mode === IngestMode::LIVE || app(BotEntry::class)->isFresh($freshAt, $arrivedAt))) {
            // Depois do commit: só uma mensagem realmente gravada pode gerar resposta (a que perdeu a corrida é descartada).
            DB::afterCommit(function () use ($message, $freshAt): void {
                app(AfterHoursReply::class)($message, $freshAt);
                app(BotEntry::class)->handle($message);
            });
        }
    }

    /** O ticket da mensagem; resposta pelo celular a uma conversa em triagem automática tira o ticket das mãos do bot. */
    private function ticketFor(Channel $channel, Contact $contact, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): Ticket
    {
        $ticket = $mode === IngestMode::IMPORTED
            ? $this->importedTicketFor($channel, $contact, $incoming)
            : $this->openTicketFor($channel, $contact, $incoming, $mode, $freshAt, $arrivedAt);

        if ($incoming->fromMe && $ticket->status === TicketStatus::PENDENTE) {
            app(BotEntry::class)->humanTookOver($ticket);
            $ticket->refresh();
        }

        return $ticket;
    }

    private function hasOpenTicketBefore(Channel $channel, Contact $contact, IncomingMessage $incoming): bool
    {
        return Ticket::query()->open()
            ->where('contact_id', $contact->id)
            ->where('channel_id', $channel->id)
            ->where('opened_at', '<=', $incoming->sentAt)
            ->exists();
    }

    /** O ticket encerrado de importação do contato neste canal (um só), com a abertura e o fechamento acompanhando as mensagens. */
    private function importedTicketFor(Channel $channel, Contact $contact, IncomingMessage $incoming): Ticket
    {
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['ticket:'.$contact->id.':'.$channel->id]);

        $ticket = Ticket::query()
            ->where('contact_id', $contact->id)
            ->where('channel_id', $channel->id)
            ->where('closed_reason', ClosedReason::IMPORTADO->value)
            ->first();

        if ($ticket === null) {
            return Ticket::create([
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'status' => TicketStatus::ENCERRADO,
                'opened_at' => $incoming->sentAt,
                'closed_at' => $incoming->sentAt,
                'closed_reason' => ClosedReason::IMPORTADO,
            ]);
        }

        $ticket->opened_at = $ticket->opened_at->min($incoming->sentAt);
        $ticket->closed_at = $ticket->closed_at === null ? $incoming->sentAt : $ticket->closed_at->max($incoming->sentAt);
        $ticket->save();

        return $ticket;
    }

    private function openTicketFor(Channel $channel, Contact $contact, IncomingMessage $incoming, IngestMode $mode, CarbonInterface $freshAt, CarbonInterface $arrivedAt): Ticket
    {
        // Serializa a abertura de ticket por contato e canal: duas mensagens simultâneas do mesmo cliente
        // não disputam o índice único (o job perdedor perderia a mensagem, pois o WAHA não reenvia).
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['ticket:'.$contact->id.':'.$channel->id]);

        $ticket = Ticket::query()
            ->open()
            ->where('contact_id', $contact->id)
            ->where('channel_id', $channel->id)
            ->first();

        if ($ticket !== null) {
            return $ticket;
        }

        $ticket = Ticket::create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'status' => match (true) {
                $incoming->fromMe => TicketStatus::EM_ATENDIMENTO,
                // Canal com workflow: o bot conduz primeiro e só então o ticket entra na fila de um setor.
                app(BotEntry::class)->shouldStart($channel, $freshAt, $arrivedAt) => TicketStatus::PENDENTE,
                default => TicketStatus::AGUARDANDO,
            },
            'opened_at' => $incoming->sentAt,
        ]);

        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'opened']);

        // Recuperação não toca o som de fila por ticket: o aviso é um só, no fim (ver `RecoverMessagesJob`).
        if ($ticket->status === TicketStatus::AGUARDANDO && $mode === IngestMode::LIVE) {
            app(AlertDispatcher::class)->queued($ticket);
        }

        return $ticket;
    }
}
