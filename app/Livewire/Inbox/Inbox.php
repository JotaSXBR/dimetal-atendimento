<?php

namespace App\Livewire\Inbox;

use App\Actions\Inbox\ClaimTicket;
use App\Actions\Inbox\CloseTicket;
use App\Actions\Inbox\ReassignTicket;
use App\Actions\Inbox\TakeoverTicket;
use App\Actions\Inbox\TransferTicket;
use App\Enums\ClosedReason;
use App\Enums\CloseOutcome;
use App\Enums\SectorStatus;
use App\Enums\TicketStatus;
use App\Jobs\MarkTicketSeenJob;
use App\Livewire\Inbox\Concerns\ComposesMessages;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\ResendTicketMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
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
    use ComposesMessages;

    /** Prévia da lista: o texto ou, quando não há, o nome do tipo de conteúdo. */
    private const PREVIEW_SQL = "case when type = 'location' then 'Localização' when type = 'contact' then 'Contato' "
        ."when body <> '' then body when type = 'view_once' then 'Visualização única' "
        ."when media_mime = 'image/webp' then 'Figurinha' when media_mime like 'image/%' then 'Foto' "
        ."when media_mime like 'audio/%' then 'Áudio' when media_mime like 'video/%' then 'Vídeo' "
        ."when type = 'media' then 'Arquivo' else '['||type||']' end";

    /** A aba "Encerrados" mostra só o recente; a busca completa fica no Histórico. */
    private const CLOSED_TAB_DAYS = 7;

    private const CLOSED_TAB_LIMIT = 50;

    private const ALREADY_CLOSED = 'Este ticket já foi encerrado por outra pessoa.';

    public ?int $ticketId = null;

    /** O ticket que estava aberto antes do atual, para o botão "Voltar". */
    public ?int $previousTicketId = null;

    #[Url]
    public string $tab = 'todos';

    public ?string $notice = null;

    /** Painel aberto sobre a conversa: `transfer`, `reassign` ou nenhum. */
    public ?string $panel = null;

    public ?int $transferSectorId = null;

    public string $transferNote = '';

    public ?int $reassignUserId = null;

    public string $closeOutcome = '';

    public string $closeSummary = '';

    /** Link de outra tela (`/inbox?ticket=ID`): abre o ticket, mas só se o usuário puder vê-lo. */
    public function mount(): void
    {
        $id = request()->integer('ticket');

        if ($id > 0 && Ticket::query()->readableBy($this->user())->whereKey($id)->exists()) {
            $this->select($id);
        }
    }

    public function select(int $ticketId): void
    {
        $ticket = Ticket::findOrFail($ticketId);
        $this->authorize('view', $ticket);

        if ($ticket->status !== TicketStatus::ENCERRADO) {
            MarkTicketSeenJob::dispatch($ticket->id);
        }

        // Abrir outro ticket (um encerrado, por exemplo) não pode fazer o atual sumir sem volta.
        if ($this->ticketId !== null && $this->ticketId !== $ticketId) {
            $this->previousTicketId = $this->ticketId;
        }

        $this->ticketId = $ticketId;
        $this->notice = null;
        $this->discardAttachments();
        $this->closePanel();
    }

    /** Reabre o ticket que estava na tela antes; o id vem do navegador, então só abre o que o usuário ainda pode ver. */
    public function goBack(): void
    {
        $id = $this->previousTicketId;
        $this->previousTicketId = null;

        if ($id !== null && Ticket::query()->readableBy($this->user())->whereKey($id)->exists()) {
            $this->select($id);
            $this->previousTicketId = null;
        }
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
        $panel = in_array($panel, ['reassign', 'close'], true) ? $panel : 'transfer';
        $this->authorize($panel, $ticket);

        $this->panel = $panel;
        $this->resetErrorBag();
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'transferSectorId', 'transferNote', 'reassignUserId', 'closeOutcome', 'closeSummary');
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

        // Outra pessoa encerrou enquanto o painel estava aberto: aviso, não erro 403.
        if ($ticket->status === TicketStatus::ENCERRADO) {
            $this->notice = self::ALREADY_CLOSED;
            $this->closePanel();

            return;
        }

        $this->authorize('close', $ticket);

        $this->closeSummary = trim($this->closeSummary);
        $this->validate([
            'closeOutcome' => ['required', Rule::enum(CloseOutcome::class)],
            'closeSummary' => ['required', 'string', 'min:3', 'max:500'],
        ], [
            'closeOutcome.required' => 'Escolha o motivo do encerramento.',
            'closeOutcome.enum' => 'Escolha o motivo do encerramento.',
            'closeSummary.required' => 'Escreva um resumo do atendimento.',
            'closeSummary.min' => 'O resumo precisa de ao menos 3 caracteres.',
            'closeSummary.max' => 'O resumo passa de 500 caracteres.',
        ]);

        $closed = (new CloseTicket)($ticket, $this->user(), ClosedReason::MANUAL, CloseOutcome::from($this->closeOutcome), $this->closeSummary);

        $this->notice = $closed ? null : self::ALREADY_CLOSED;
        $this->closePanel();
    }

    /**
     * Reenvia, na mesma linha, uma mensagem que falhou. Falha que pode ter saído exige confirmação (o botão pede e chama de
     * novo com `$confirmed`); o servidor confere de novo, porque a tela sozinha não protege contra mensagem em dobro.
     */
    public function resend(int $messageId, bool $confirmed = false): void
    {
        $ticket = $this->selected();
        $this->authorize('reply', $ticket);

        $message = Message::query()->where('ticket_id', $ticket->id)->findOrFail($messageId);

        $result = (new ResendTicketMessage)($message, $this->user(), $confirmed);

        $this->notice = match ($result) {
            ResendTicketMessage::CONFIRM => 'Confirme antes de reenviar: a mensagem pode já ter sido enviada.',
            ResendTicketMessage::NONE => 'Esta mensagem não pode ser reenviada.',
            default => null,
        };

        if ($result === 'sent') {
            $this->dispatch('transcript-refresh');
        }
    }

    /**
     * Qualquer evento do inbox só pede nova leitura do banco; o texto nunca viaja pelo websocket.
     */
    #[On('echo-private:inbox,.ticket.updated')]
    #[On('echo-private:inbox,.message.created')]
    public function refresh(): void {}

    /** Só o protocolo, e só se o usuário ainda pode ver o ticket (ele pode ter sido transferido enquanto isso). */
    private function previousTicket(User $user): ?Ticket
    {
        return $this->previousTicketId === null
            ? null
            : Ticket::query()->readableBy($user)->select(['id', 'protocol'])->find($this->previousTicketId);
    }

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
        return Ticket::query()->readableBy($this->user())->findOrFail($this->ticketId);
    }

    private function find(): ?Ticket
    {
        return $this->ticketId === null
            ? null
            : Ticket::query()->readableBy($this->user())->with(['contact', 'channel', 'assignee', 'sector'])->find($this->ticketId);
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

    /**
     * Os encerrados recentes que o usuário pode ver, para a aba "Encerrados"; vazio nas outras abas.
     *
     * @return EloquentCollection<int, Ticket>
     */
    private function recentlyClosed(User $user): EloquentCollection
    {
        if ($this->tab !== 'encerrados') {
            return new EloquentCollection;
        }

        return Ticket::query()->inHistoryOf($user)->where('closed_at', '>=', now()->subDays(self::CLOSED_TAB_DAYS))
            ->with('contact')->orderByDesc('closed_at')->orderByDesc('id')->limit(self::CLOSED_TAB_LIMIT)->get();
    }

    public function render(): View
    {
        $user = $this->user();
        $ticket = $this->find();

        $list = Ticket::query()
            ->open()
            ->visibleTo($user)
            ->with(['contact', 'channel'])
            ->addSelect(['preview' => Message::query()
                ->selectRaw(self::PREVIEW_SQL)->whereColumn('ticket_id', 'tickets.id')->latest('id')->limit(1)])
            ->when($this->tab === 'aguardando', fn (Builder $q) => $q->where('status', TicketStatus::AGUARDANDO->value))
            ->when($this->tab === 'meus', fn (Builder $q) => $q->where('assigned_user_id', $user->id))
            ->orderBy('last_inbound_at')
            ->get();

        return view('livewire.inbox.inbox', [
            'waiting' => $list->where('status', TicketStatus::AGUARDANDO),
            'active' => $list->where('status', TicketStatus::EM_ATENDIMENTO),
            'closed' => $this->recentlyClosed($user),
            'tabCounts' => [
                'aguardando' => Ticket::query()->open()->visibleTo($user)->where('status', TicketStatus::AGUARDANDO->value)->count(),
                'meus' => Ticket::query()->open()->where('assigned_user_id', $user->id)->count(),
            ],
            // O selo do número só ajuda quando há mais de um número ativo.
            'showChannel' => Channel::query()->where('active', true)->count() >= 2,
            'ticket' => $ticket,
            'previous' => $this->previousTicket($user),
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
