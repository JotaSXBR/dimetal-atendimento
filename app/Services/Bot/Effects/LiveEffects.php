<?php

namespace App\Services\Bot\Effects;

use App\Actions\Contacts\SaveContact;
use App\Actions\Inbox\ClaimTicket;
use App\Actions\Inbox\CloseTicket;
use App\Actions\Inbox\TransferTicket;
use App\Enums\ClosedReason;
use App\Enums\MessageOrigin;
use App\Enums\NoteOrigin;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Jobs\SendTicketMessageJob;
use App\Models\BotSession;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Inbox\AttachmentRules;
use App\Services\Inbox\BusinessHours;
use App\Services\Inbox\Presence;
use App\Services\Inbox\SendTicketMessage;
use App\Services\Waha\WahaClient;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/** O bot de verdade: grava, envia e muda o ticket. */
final class LiveEffects implements BotEffects
{
    public function __construct(private readonly Ticket $ticket) {}

    /** Grava e despacha uma mensagem do bot; visto e "digitando" saem em `SendTicketMessage::deliver`, como no envio humano. */
    public function say(string $text): void
    {
        if (trim($text) === '') {
            return;
        }

        $message = app(SendTicketMessage::class)->create($this->ticket, null, $text, MessageOrigin::BOT);
        SendTicketMessageJob::dispatch($message->id)->afterCommit();
    }

    /** Arquivo que sumiu é registrado no log e o fluxo segue. */
    public function media(string $path, string $caption): void
    {
        $disk = Storage::disk('local');

        // O caminho vem do grafo (dado não confiável): só vale dentro do storage, sem subir de diretório.
        if ($path === '' || str_contains($path, '..') || ! $disk->exists($path)) {
            Log::warning('Bot: arquivo do bloco Enviar mídia não encontrado', ['ticket' => $this->ticket->id, 'path' => $path]);

            return;
        }

        $mime = $disk->mimeType($path);
        $copy = $this->copyToMediaDisk($disk, $path);

        if ($copy === null) {
            Log::warning('Bot: não foi possível copiar o arquivo do bloco Enviar mídia', ['ticket' => $this->ticket->id, 'path' => $path]);

            return;
        }

        $message = app(SendTicketMessage::class)->record(
            $this->ticket,
            null,
            'media',
            $caption,
            MessageOrigin::BOT,
            [
                'media_mime' => is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream',
                'media_name' => AttachmentRules::safeName(basename($path)),
                'media_size' => $disk->size($path),
                'media_path' => $copy,
            ],
        );
        SendTicketMessageJob::dispatch($message->id)->afterCommit();
    }

    /** A presença é melhor esforço: se o WAHA falhar, só a pausa fica. */
    public function wait(int $seconds): void
    {
        $contact = $this->ticket->contact()->firstOrFail();
        $chatId = $contact->wa_id ?? (string) $contact->lid;
        $client = new WahaClient($this->ticket->channel()->firstOrFail());

        try {
            $client->startTyping($chatId);
        } catch (Throwable $e) {
            Log::warning('Bot: "digitando" falhou e foi ignorado', ['erro' => $e->getMessage()]);
        }

        Sleep::for($seconds)->seconds();

        try {
            $client->stopTyping($chatId);
        } catch (Throwable $e) {
            Log::warning('Bot: parar "digitando" falhou e foi ignorado', ['erro' => $e->getMessage()]);
        }

        app(Presence::class)->botTyped($this->ticket);
    }

    public function saveContactField(string $field, string $value): void
    {
        (new SaveContact)($this->ticket->contact()->firstOrFail(), [$field => $value]);
    }

    public function tag(Tag $tag): void
    {
        $this->ticket->tags()->syncWithoutDetaching([$tag->id]);
    }

    public function note(string $body): void
    {
        TicketNote::create(['ticket_id' => $this->ticket->id, 'origin' => NoteOrigin::BOT, 'body' => $body]);
    }

    public function close(): void
    {
        (new CloseTicket)($this->ticket, null, ClosedReason::BOT);
    }

    /**
     * Entra na fila e, se a pessoa escolhida pode, passa o ticket a ela; devolve o id de quem assumiu. Com atribuição possível a
     * fila não toca o som; se a atribuição falhar, ela é avisada em seguida.
     */
    public function enterQueue(?Sector $sector, ?User $assignTo): ?int
    {
        $canAssign = $assignTo !== null && $assignTo->active && $assignTo->canHandleSector($sector?->id);

        $this->queue($sector, announce: ! $canAssign);
        $assigned = $canAssign ? $this->assign($assignTo) : null;

        if ($canAssign && $assigned === null) {
            app(AlertDispatcher::class)->queued($this->ticket->refresh());
        }

        return $assigned;
    }

    public function logHandoff(string $reason, ?Sector $sector, ?int $assignedUserId): void
    {
        TicketEvent::create(['ticket_id' => $this->ticket->id, 'type' => 'bot_handoff', 'payload' => ['reason' => $reason, 'sector_id' => $sector?->id, ...($assignedUserId !== null ? ['assigned_user_id' => $assignedUserId] : [])]]);
    }

    public function exclusive(BotSession $session, Closure $work): void
    {
        DB::transaction(function () use ($session, $work): void {
            $locked = BotSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if ($locked === null || $locked->state !== BotSession::ACTIVE) {
                return;
            }

            $this->ticket->refresh();
            $work();
        });

        $session->refresh();
    }

    public function save(BotSession $session): void
    {
        $session->save();
    }

    public function entered(string $nodeId): void {}

    public function isOpen(): bool
    {
        return app(BusinessHours::class)->isOpen(now());
    }

    public function contactName(): string
    {
        return trim((string) $this->ticket->contact()->first()?->name);
    }

    public function returning(): bool
    {
        return Ticket::query()->where('contact_id', $this->ticket->contact_id)->whereKeyNot($this->ticket->id)->exists();
    }

    public function protocol(): string
    {
        return (string) $this->ticket->protocol;
    }

    /** Passa o ticket (já na fila) à pessoa; devolve o id dela, ou nulo se ele já tinha dono ou foi assumido antes. */
    private function assign(User $user): ?int
    {
        if (! (new ClaimTicket)($this->ticket, $user)) {
            return null;
        }

        app(AlertDispatcher::class)->assigned($this->ticket, $user, null);

        return $user->id;
    }

    private function queue(?Sector $sector, bool $announce): void
    {
        if ($sector !== null) {
            (new TransferTicket)($this->ticket, null, $sector, announce: $announce);

            return;
        }

        // Nenhum setor recebe tickets: vai para a fila geral, que o atendente sem setor e os supervisores veem.
        $moved = Ticket::query()->whereKey($this->ticket->id)->where('status', TicketStatus::PENDENTE->value)->update([
            'status' => TicketStatus::AGUARDANDO->value,
            'waiting_since' => now(),
            'sla_warn_at' => null,
            'sla_due_at' => null,
            'sla_warned_at' => null,
            'sla_breached_at' => null,
            'updated_at' => now(),
        ]) === 1;

        if ($moved) {
            TicketEvent::create(['ticket_id' => $this->ticket->id, 'type' => 'transferred', 'payload' => []]);
            $this->ticket->refresh();
            TicketUpdated::dispatch($this->ticket);
            if ($announce) {
                app(AlertDispatcher::class)->queued($this->ticket);
            }
        }
    }

    /**
     * O arquivo do bloco é a fonte (disco `local`, pasta `bot/`); o anexo é uma cópia no disco de mídia, que a purga de 90 dias
     * pode apagar sem tocar na fonte.
     */
    private function copyToMediaDisk(Filesystem $source, string $path): ?string
    {
        $stream = $source->readStream($path);
        $key = 'outbound/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.pathinfo($path, PATHINFO_EXTENSION);

        if ($stream === null || Message::mediaDisk()->writeStream($key, $stream) === false) {
            return null;
        }

        return $key;
    }
}
