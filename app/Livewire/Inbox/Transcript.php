<?php

namespace App\Livewire\Inbox;

use App\Actions\Contacts\CreateContact;
use App\Actions\Inbox\MarkTicketSeenByAgent;
use App\Events\TicketUpdated;
use App\Jobs\MarkTicketSeenJob;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Inbox\SpecialContent;
use App\Support\PhoneFormat;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * As mensagens do ticket aberto. Vive fora do `Inbox` para que só um evento de mensagem (ou o `transcript-refresh`
 * que o `Inbox` dispara depois de enviar) o renderize: digitar, abrir painéis e eventos da lista não o tocam.
 */
class Transcript extends Component
{
    private const LIMIT = 200;

    /** Quanto tempo o "Desfazer" de uma nota apagada vale. */
    private const UNDO_SECONDS = 60;

    #[Locked]
    public int $ticketId;

    /** A aba está visível? O navegador atualiza (resources/js/transcript-visibility.js): em segundo plano a mensagem fica não lida. */
    public bool $visible = true;

    /** @param  array<string, mixed>  $payload */
    #[On('echo-private:inbox,.message.created')]
    #[On('echo-private:inbox,.message.status')]
    #[On('echo-private:inbox,.ticket.updated')]
    public function refreshForEvent(array $payload = []): void
    {
        $id = $payload['ticket_id'] ?? null;

        if (! is_numeric($id) || (int) $id !== $this->ticketId) {
            $this->skipRender();

            return;
        }

        // Só `message.created` traz `direction`. Mensagem do cliente no ticket que está na tela: o atendente a vê, o cliente recebe o "visto".
        if (($payload['direction'] ?? null) === 'in') {
            MarkTicketSeenJob::dispatch($this->ticketId);

            if ($this->visible) {
                $this->markSeenByAgent();
            }
        }
    }

    /** A aba voltou a ficar visível: o que chegou em segundo plano conta como visto agora. */
    #[Renderless]
    public function seen(): void
    {
        $this->markSeenByAgent();
    }

    /** Só vale para quem pode ver o ticket (e, dentro da ação, só para o responsável ou em ticket sem responsável). */
    private function markSeenByAgent(): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $ticket = Ticket::query()->readableBy($user)->find($this->ticketId);

        if ($ticket !== null) {
            app(MarkTicketSeenByAgent::class)($ticket, $user);
        }
    }

    #[On('transcript-refresh')]
    #[On('realtime-reconnected')]
    public function refresh(): void {}

    /**
     * Só o autor apaga a própria nota; fica o rastro na auditoria do ticket. Sem confirmação: o aviso oferece "Desfazer" por 60 s
     * (a nota fica guardada no cache do usuário sob um token aleatório).
     */
    public function deleteNote(int $noteId): void
    {
        $note = TicketNote::query()->where('ticket_id', $this->ticketId)->find($noteId);

        if ($note === null) {
            return;
        }

        $this->authorize('delete', $note);

        $token = Str::random(32);
        Cache::put(self::undoKey($token), ['ticket_id' => $note->ticket_id, 'user_id' => $note->user_id, 'origin' => $note->origin->value, 'body' => $note->body, 'created_at' => $note->created_at->toIso8601String()], self::UNDO_SECONDS);

        $note->delete();
        TicketEvent::create(['ticket_id' => $this->ticketId, 'type' => 'note_deleted', 'actor_user_id' => auth()->id(), 'payload' => ['note_id' => $noteId]]);
        $this->touchTicket();

        Flux::toast(text: 'Nota apagada.', duration: 8000, action: ['label' => 'Desfazer', 'event' => 'undo-note-delete', 'params' => ['token' => $token]]);
    }

    /** O "Desfazer" do aviso: recria a nota idêntica (mesmo autor, origem e horário), uma vez só, e só neste ticket. */
    #[On('undo-note-delete')]
    public function restoreNote(string $token): void
    {
        $saved = Cache::pull(self::undoKey($token));

        $createdAt = is_array($saved) ? ($saved['created_at'] ?? null) : null;

        if (! is_array($saved) || ($saved['ticket_id'] ?? null) !== $this->ticketId || ! is_string($createdAt)) {
            return;
        }

        $note = new TicketNote(['ticket_id' => $this->ticketId, 'user_id' => $saved['user_id'], 'origin' => $saved['origin'], 'body' => $saved['body']]);
        $note->created_at = CarbonImmutable::parse($createdAt);
        $note->save();

        TicketEvent::create(['ticket_id' => $this->ticketId, 'type' => 'note_restored', 'actor_user_id' => auth()->id(), 'payload' => ['note_id' => $note->id]]);
        $this->touchTicket();
    }

    /** Por usuário: o token de outra pessoa não acha nada. */
    private static function undoKey(string $token): string
    {
        return 'undo-note:'.auth()->id().':'.$token;
    }

    private function touchTicket(): void
    {
        $ticket = Ticket::find($this->ticketId);

        if ($ticket !== null) {
            TicketUpdated::dispatch($ticket);
        }
    }

    /** "Adicionar aos contatos" no cartão de contato recebido: cria o contato (ou reaproveita o que já existe) sem duplicar. */
    public function addContact(int $messageId, int $index): void
    {
        $this->contactFromCard($messageId, $index);
    }

    /** "Conversar" no cartão: garante o contato e abre a janela Nova conversa com ele. */
    public function chatWith(int $messageId, int $index): void
    {
        $contact = $this->contactFromCard($messageId, $index);

        if ($contact !== null) {
            $this->dispatch('start-conversation', contactId: $contact->id);
        }
    }

    /** O cartão `$index` de uma mensagem de contato DESTE ticket (o id vem do navegador), já como contato cadastrado. */
    private function contactFromCard(int $messageId, int $index): ?Contact
    {
        $message = Message::query()->where('ticket_id', $this->ticketId)->where('type', 'contact')->find($messageId);
        $card = $message === null ? null : (SpecialContent::contacts($message->body)[$index] ?? null);
        $phone = $card['phones'][0] ?? '';

        if ($card === null || PhoneFormat::toWaId($phone) === null) {
            return null;
        }

        return app(CreateContact::class)($phone, $card['name'])['contact'];
    }

    public function render(): View
    {
        $user = auth()->user();
        assert($user instanceof User);

        // O filho não confia no pai: confere de novo se o usuário pode ver este ticket.
        $ticket = Ticket::query()->readableBy($user)->find($this->ticketId);

        $messages = $this->recent($ticket);

        // Só as notas desde a mensagem mais antiga da página, para a conversa não ganhar notas soltas do passado.
        $oldest = $messages->first()?->sent_at;
        $notes = $ticket === null ? collect() : TicketNote::query()->where('ticket_id', $ticket->id)->with('author')
            ->when($oldest !== null, fn ($query) => $query->where('created_at', '>=', $oldest))
            ->orderBy('created_at')->limit(self::LIMIT)->get();

        return view('livewire.inbox.messages', ['messages' => $messages, 'notes' => $notes]);
    }

    /**
     * As últimas mensagens do ticket, da mais antiga para a mais nova.
     *
     * @return Collection<int, Message>
     */
    private function recent(?Ticket $ticket): Collection
    {
        if ($ticket === null) {
            return new Collection;
        }

        return $ticket->messages()->with(['reactions', 'edits', 'ticket.channel', 'contact'])->orderByDesc('sent_at')->orderByDesc('id')->limit(self::LIMIT)->get()->reverse()->values();
    }
}
