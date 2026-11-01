<?php

namespace App\Livewire\Inbox;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Faixa "Outros tickets deste contato" no cabeçalho do ticket: o mesmo cliente pode ter ticket aberto no outro número, ou
 * ter falado ali há pouco. Vive fora do `Inbox` para só renderizar com `ticket.updated` (digitar não a toca). Quem não pode
 * abrir o outro ticket vê só protocolo, número e estado: nunca setor, atendente ou texto.
 */
class ContactTickets extends Component
{
    private const LIMIT = 5;

    private const RECENT_DAYS = 30;

    #[Locked]
    public int $ticketId;

    #[On('echo-private:inbox,.ticket.updated')]
    public function refresh(): void {}

    public function render(): View
    {
        $user = auth()->user();
        assert($user instanceof User);

        // O filho não confia no pai: sem acesso ao ticket aberto, não mostra nada.
        $current = Ticket::query()->readableBy($user)->find($this->ticketId);

        if ($current === null) {
            return view('livewire.inbox.contact-tickets', ['items' => []]);
        }

        $others = Ticket::query()
            ->where('contact_id', $current->contact_id)
            ->whereKeyNot($current->id)
            ->where(fn (Builder $q) => $q
                ->where('status', '<>', TicketStatus::ENCERRADO->value)
                ->orWhere('closed_at', '>=', now()->subDays(self::RECENT_DAYS)))
            ->with('channel')
            ->orderByRaw("case when status = 'encerrado' then 1 else 0 end")
            ->orderByDesc('opened_at')
            ->limit(self::LIMIT)
            ->get();

        $openable = Ticket::query()->readableBy($user)->whereIn('id', $others->modelKeys())->pluck('id')->all();

        return view('livewire.inbox.contact-tickets', [
            'items' => $others->map(fn (Ticket $other): array => [
                'id' => $other->id,
                'protocol' => $other->protocol ?? '#'.$other->id,
                'open' => $other->status !== TicketStatus::ENCERRADO,
                'status' => $other->status->label(),
                'closedAt' => $other->closed_at,
                'otherNumber' => $other->channel_id !== $current->channel_id,
                'number' => $other->channel?->phoneLabel() ?? $other->channel?->name ?? '',
                'link' => in_array($other->id, $openable, true),
            ])->all(),
        ]);
    }
}
