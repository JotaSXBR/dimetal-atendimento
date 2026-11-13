<?php

namespace App\Livewire\Inbox;

use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
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

    /** Outra pessoa mudou o ticket: as etiquetas se atualizam sem esperar uma interação. */
    #[On('echo-private:inbox,.ticket.updated')]
    #[On('realtime-reconnected')]
    public function refresh(): void {}

    public function updatedAddTagId(string $value): void
    {
        $this->addTagId = '';

        if (ctype_digit($value)) {
            $this->addTag((int) $value);
        }
    }

    /** Só etiqueta do escopo `conversa`. O evento só nasce se esta chamada de fato aplicou (duas pessoas ao mesmo tempo não duplicam). */
    public function addTag(int $tagId): void
    {
        $this->change($tagId, 'tag_added', Tag::query()->ofTicket(), fn (Ticket $ticket, Tag $tag): bool => $ticket->tags()->syncWithoutDetaching([$tag->id])['attached'] !== []);
    }

    public function removeTag(int $tagId): void
    {
        $this->change($tagId, 'tag_removed', Tag::query(), fn (Ticket $ticket, Tag $tag): bool => $ticket->tags()->detach($tag->id) > 0);
    }

    /**
     * Aplica ou tira na mesma transação do evento de auditoria, com o ticket travado para um encerramento no meio não deixar
     * etiqueta nova em ticket encerrado.
     *
     * @param  Builder<Tag>  $tags
     * @param  callable(Ticket, Tag): bool  $apply  true se mudou algo
     */
    private function change(int $tagId, string $type, Builder $tags, callable $apply): void
    {
        abort_if($this->readonly, 403);

        $tag = $tags->find($tagId);

        if ($tag === null) {
            return;
        }

        $ticket = DB::transaction(function () use ($tag, $type, $apply): ?Ticket {
            $ticket = $this->ticket(lock: true);
            abort_if($ticket->status === TicketStatus::ENCERRADO, 403);

            if (! $apply($ticket, $tag)) {
                return null;
            }

            TicketEvent::create(['ticket_id' => $ticket->id, 'type' => $type, 'actor_user_id' => auth()->id(), 'payload' => ['tag_id' => $tag->id, 'tag' => $tag->name]]);

            return $ticket;
        });

        if ($ticket !== null) {
            TicketUpdated::dispatch($ticket);
        }
    }

    /** O filho não confia no pai: confere de novo se o usuário vê o ticket. */
    private function ticket(bool $lock = false): Ticket
    {
        $user = auth()->user();
        assert($user instanceof User);

        return Ticket::query()->readableBy($user)->when($lock, fn ($query) => $query->lockForUpdate())->find($this->ticketId) ?? abort(404);
    }

    public function render(): View
    {
        $ticket = $this->ticket();
        $applied = Tag::sortedByName($ticket->tags()->get());

        return view('livewire.inbox.ticket-tags', [
            'applied' => $applied,
            'available' => Tag::sortedByName(Tag::query()->ofTicket()->whereNotIn('id', $applied->pluck('id'))->get()),
            'editable' => ! $this->readonly && $ticket->status !== TicketStatus::ENCERRADO,
        ]);
    }
}
