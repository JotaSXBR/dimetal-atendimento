<?php

namespace App\Livewire\Inbox;

use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Etiquetas da conversa no painel de detalhes: quem vê o ticket aberto aplica e tira (inclusive as que o bot pôs).
 * Cada mudança fica na auditoria do ticket e avisa a Fila. Ticket encerrado e histórico são só leitura.
 */
class TicketTags extends Component
{
    #[Locked]
    public int $ticketId;

    #[Locked]
    public bool $readonly = false;

    /** O valor do seletor: ao escolher, aplica e volta a vazio. */
    public string $addTagId = '';

    public function mount(int $ticketId, bool $readonly = false): void
    {
        $this->ticketId = $ticketId;
        $this->readonly = $readonly;
        $this->ticket();
    }

    public function updatedAddTagId(string $value): void
    {
        $this->addTagId = '';

        if (ctype_digit($value)) {
            $this->addTag((int) $value);
        }
    }

    /** Só etiqueta do escopo `conversa`; repetir não grava evento. */
    public function addTag(int $tagId): void
    {
        $ticket = $this->writable();
        $tag = Tag::query()->ofTicket()->find($tagId);

        if ($tag === null || $ticket->tags()->whereKey($tag->id)->exists()) {
            return;
        }

        $ticket->tags()->attach($tag->id);
        $this->record($ticket, 'tag_added', $tag);
    }

    public function removeTag(int $tagId): void
    {
        $ticket = $this->writable();
        $tag = $ticket->tags()->whereKey($tagId)->first();

        if ($tag === null) {
            return;
        }

        $ticket->tags()->detach($tag->id);
        $this->record($ticket, 'tag_removed', $tag);
    }

    private function record(Ticket $ticket, string $type, Tag $tag): void
    {
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => $type, 'actor_user_id' => auth()->id(), 'payload' => ['tag_id' => $tag->id, 'tag' => $tag->name]]);
        TicketUpdated::dispatch($ticket);
    }

    /** O filho não confia no pai: confere de novo se o usuário vê o ticket. */
    private function ticket(): Ticket
    {
        $user = auth()->user();
        assert($user instanceof User);

        return Ticket::query()->readableBy($user)->find($this->ticketId) ?? abort(404);
    }

    private function writable(): Ticket
    {
        abort_if($this->readonly, 403);

        $ticket = $this->ticket();
        abort_if($ticket->status === TicketStatus::ENCERRADO, 403);

        return $ticket;
    }

    public function render(): View
    {
        $applied = Tag::sortedByName($this->ticket()->tags()->get());

        return view('livewire.inbox.ticket-tags', [
            'applied' => $applied,
            'available' => Tag::sortedByName(Tag::query()->ofTicket()->whereNotIn('id', $applied->pluck('id'))->get()),
            'editable' => ! $this->readonly && $this->ticket()->status !== TicketStatus::ENCERRADO,
        ]);
    }
}
