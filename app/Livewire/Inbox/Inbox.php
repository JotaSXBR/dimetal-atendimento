<?php

namespace App\Livewire\Inbox;

use App\Actions\Inbox\ClaimTicket;
use App\Actions\Inbox\CloseTicket;
use App\Actions\Inbox\ReassignTicket;
use App\Actions\Inbox\TakeoverTicket;
use App\Actions\Inbox\TransferTicket;
use App\Enums\SectorStatus;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketMessageJob;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
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

    /** Painel aberto sobre a conversa: `transfer`, `reassign` ou nenhum. */
    public ?string $panel = null;

    public ?int $transferSectorId = null;

    public string $transferNote = '';

    public ?int $reassignUserId = null;

    public function select(int $ticketId): void
    {
        $this->authorize('view', Ticket::findOrFail($ticketId));

        $this->ticketId = $ticketId;
        $this->notice = null;
        $this->closePanel();
    }

    public function claim(): void
    {
        // Sem o filtro de visibilidade: quem perde a corrida ainda precisa receber o aviso, não um 404.
        $ticket = Ticket::findOrFail($this->ticketId);

        if (! $this->user()->can('claim', $ticket) || ! (new ClaimTicket)($ticket, $this->user())) {
            $this->notice = 'Outra pessoa assumiu este ticket antes de você.';
        }
    }

    /**
     * `$seenAssignee` é o responsável que estava na tela: se o ticket mudou de mão no meio do caminho, a troca
     * não acontece e o usuário recebe o aviso.
     */
    public function takeover(int $seenAssignee): void
    {
        $ticket = $this->selected();
        $this->authorize('takeover', $ticket);

        $this->notice = (new TakeoverTicket)($ticket, $this->user(), $seenAssignee)
            ? null
            : 'Este ticket mudou de responsável antes de você tomá-lo.';
    }

    public function openPanel(string $panel): void
    {
        $ticket = $this->selected();
        $this->authorize($panel === 'reassign' ? 'reassign' : 'transfer', $ticket);

        $this->panel = $panel === 'reassign' ? 'reassign' : 'transfer';
        $this->resetErrorBag();
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'transferSectorId', 'transferNote', 'reassignUserId');
        $this->resetErrorBag();
    }

    public function transfer(): void
    {
        $ticket = $this->selected();
        $this->authorize('transfer', $ticket);

        $this->validate([
            'transferSectorId' => ['required', 'integer', Rule::exists('sectors', 'id')->whereIn('status', $this->receivingStatuses())],
            'transferNote' => ['nullable', 'string', 'max:500'],
        ], [
            'transferSectorId.required' => 'Escolha o setor de destino.',
            'transferSectorId.exists' => 'Esse setor não recebe tickets.',
            'transferNote.max' => 'A nota passa de 500 caracteres.',
        ]);

        $moved = (new TransferTicket)($ticket, $this->user(), Sector::findOrFail($this->transferSectorId), $this->transferNote);

        $this->notice = $moved ? null : 'Não foi possível transferir: o ticket já está nesse setor ou foi encerrado.';
        $this->closePanel();
    }

    public function reassign(): void
    {
        $ticket = $this->selected();
        $this->authorize('reassign', $ticket);

        $this->validate(
            ['reassignUserId' => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)]],
            ['reassignUserId.required' => 'Escolha quem fica com o ticket.'],
        );

        $reassigned = (new ReassignTicket)($ticket, $this->user(), User::findOrFail($this->reassignUserId));

        $this->notice = $reassigned ? null : 'Não foi possível reatribuir: essa pessoa não atende este setor ou já está com o ticket.';
        $this->closePanel();
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

        // Freio contra conta comprometida ou laço no navegador. Só o envio conta; indicadores de digitação, que são
        // bem mais frequentes, terão limite próprio quando existirem.
        $allowed = RateLimiter::attempt('send-message:'.$this->user()->id, config()->integer('security.send_message_per_minute'), fn () => true, 60);

        if (! $allowed) {
            $this->addError('draft', 'Você está enviando mensagens rápido demais. Aguarde alguns segundos.');

            return;
        }

        SendTicketMessageJob::dispatch(app(SendTicketMessage::class)->create($ticket, $this->user(), $this->draft)->id);

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
            : Ticket::query()->visibleTo($this->user())->with(['contact', 'channel', 'assignee', 'sector'])->find($this->ticketId);
    }

    /**
     * @return list<string>
     */
    private function receivingStatuses(): array
    {
        return array_values(array_map(
            fn (SectorStatus $status): string => $status->value,
            array_filter(SectorStatus::cases(), fn (SectorStatus $status): bool => $status->receivesTickets()),
        ));
    }

    /**
     * Quem pode receber o ticket numa reatribuição: usuários ativos do setor dele (ou todos, se não tem setor).
     *
     * @return Collection<int, User>
     */
    private function reassignCandidates(Ticket $ticket): Collection
    {
        return User::query()
            ->where('active', true)
            ->when($ticket->sector_id !== null, fn (Builder $q) => $q->whereHas('sectors', fn (Builder $s) => $s->whereKey($ticket->sector_id)))
            ->when($ticket->assigned_user_id !== null, fn (Builder $q) => $q->whereKeyNot($ticket->assigned_user_id))
            ->orderBy('name')
            ->get();
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
            'canTransfer' => $ticket !== null && $user->can('transfer', $ticket),
            'canReassign' => $ticket !== null && $user->can('reassign', $ticket),
            'canTakeover' => $ticket !== null && $user->can('takeover', $ticket),
            'sectors' => $this->panel === 'transfer' ? Sector::query()->receivingTickets()->orderBy('menu_order')->get() : collect(),
            'candidates' => $ticket !== null && $this->panel === 'reassign' ? $this->reassignCandidates($ticket) : collect(),
        ]);
    }
}
