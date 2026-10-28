<?php

namespace App\Livewire\Inbox;

use App\Actions\Inbox\ClaimTicket;
use App\Actions\Inbox\CloseTicket;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketMessageJob;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Atendimento')]
#[Layout('layouts::inbox')]
class Inbox extends Component
{
    public ?int $ticketId = null;

    #[Url]
    public string $tab = 'todos';

    public string $draft = '';

    public ?string $notice = null;

    public function select(int $ticketId): void
    {
        $this->authorize('view', Ticket::findOrFail($ticketId));

        $this->ticketId = $ticketId;
        $this->notice = null;
    }

    public function claim(): void
    {
        // Sem o filtro de visibilidade: quem perde a corrida ainda precisa receber o aviso, não um 404.
        $ticket = Ticket::findOrFail($this->ticketId);

        if (! $this->user()->can('claim', $ticket) || ! (new ClaimTicket)($ticket, $this->user())) {
            $this->notice = 'Outra pessoa assumiu este ticket antes de você.';
        }
    }

    public function close(): void
    {
        $ticket = $this->selected();
        $this->authorize('close', $ticket);

        (new CloseTicket)($ticket, $this->user());
    }

    public function send(): void
    {
        $ticket = $this->selected();
        $this->authorize('reply', $ticket);

        $this->draft = trim($this->draft);
        $this->validate(['draft' => ['required', 'string', 'max:4096']], [
            'draft.required' => 'Escreva uma mensagem.',
            'draft.max' => 'A mensagem passa de 4096 caracteres.',
        ]);

        SendTicketMessageJob::dispatch($ticket->id, $this->user()->id, $this->draft);

        $this->reset('draft');
    }

    /**
     * Qualquer evento do inbox só pede nova leitura do banco; o texto nunca viaja pelo websocket.
     */
    #[On('echo-private:inbox,.ticket.updated')]
    #[On('echo-private:inbox,.message.created')]
    #[On('echo-private:inbox,.message.status')]
    public function refresh(): void {}

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }

    /**
     * O ticket aberto na tela, desde que o usuário ainda possa vê-lo (outro atendente pode ter assumido).
     */
    private function selected(): Ticket
    {
        return Ticket::query()->visibleTo($this->user())->findOrFail($this->ticketId);
    }

    private function find(): ?Ticket
    {
        return $this->ticketId === null
            ? null
            : Ticket::query()->visibleTo($this->user())->with(['contact', 'channel', 'assignee'])->find($this->ticketId);
    }

    public function render(): View
    {
        $user = $this->user();
        $ticket = $this->find();

        $list = Ticket::query()
            ->open()
            ->visibleTo($user)
            ->with('contact')
            ->addSelect(['preview' => Message::query()
                ->select('body')->whereColumn('ticket_id', 'tickets.id')->latest('id')->limit(1)])
            ->when($this->tab === 'aguardando', fn (Builder $q) => $q->where('status', TicketStatus::AGUARDANDO->value))
            ->when($this->tab === 'meus', fn (Builder $q) => $q->where('assigned_user_id', $user->id))
            ->orderBy('last_inbound_at')
            ->get();

        return view('livewire.inbox.inbox', [
            'waiting' => $list->where('status', TicketStatus::AGUARDANDO),
            'active' => $list->where('status', TicketStatus::EM_ATENDIMENTO),
            'ticket' => $ticket,
            'messages' => $ticket?->messages()->latest('id')->limit(200)->get()->reverse(),
            'canClaim' => $ticket !== null && $user->can('claim', $ticket),
            'canClose' => $ticket !== null && $user->can('close', $ticket),
            'canReply' => $ticket !== null && $user->can('reply', $ticket),
        ]);
    }
}
