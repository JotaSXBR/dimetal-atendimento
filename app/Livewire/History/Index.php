<?php

namespace App\Livewire\History;

use App\Enums\CloseOutcome;
use App\Enums\TicketStatus;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Inbox\HistorySearch;
use App\Support\TicketTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Busca e leitura dos tickets encerrados que o usuário pode ver. Só leitura: nada aqui altera ticket. */
#[Title('Histórico')]
#[Layout('layouts::inbox')]
class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 25;

    private const DEFAULT_DAYS = 30;

    /** A transcrição mostra as últimas mensagens, como o Inbox. */
    private const MESSAGE_LIMIT = 200;

    #[Url(as: 'q')]
    public string $q = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public ?int $sectorId = null;

    #[Url]
    public ?int $userId = null;

    #[Url]
    public string $outcome = '';

    #[Url]
    public ?int $contactId = null;

    #[Url]
    public ?int $ticketId = null;

    public function mount(): void
    {
        // Vindo do "Ver todos" de um contato, o que interessa são os antigos: sem a janela padrão.
        if ($this->from === '' && $this->contactId === null) {
            $this->from = CarbonImmutable::now(config()->string('app.display_timezone'))->subDays(self::DEFAULT_DAYS)->toDateString();
        }
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['q', 'from', 'to', 'sectorId', 'userId', 'outcome', 'contactId'], true)) {
            $this->resetPage();
        }
    }

    public function select(int $ticketId): void
    {
        $ticket = Ticket::query()->findOrFail($ticketId);

        $this->authorize('view', $ticket);
        abort_unless($ticket->status === TicketStatus::ENCERRADO, 404);

        $this->ticketId = $ticket->id;
    }

    public function render(): View
    {
        $user = auth()->user();
        assert($user instanceof User);

        $tickets = (new HistorySearch)
            ->query($user, $this->q, $this->from ?: null, $this->to ?: null, $this->sectorId, $this->userId, $this->outcome ?: null, $this->contactId)
            ->with(['contact', 'sector', 'assignee'])
            ->simplePaginate(self::PER_PAGE);

        $ticket = $this->ticketId === null
            ? null
            : Ticket::query()->inHistoryOf($user)->with(['contact', 'channel', 'assignee', 'sector'])->find($this->ticketId);

        $messages = collect();
        $hasMore = false;
        $events = new EloquentCollection;
        $closer = null;

        if ($ticket !== null) {
            $latest = $ticket->messages()->with(['reactions', 'edits'])->orderByDesc('sent_at')->orderByDesc('id')->limit(self::MESSAGE_LIMIT + 1)->get();
            $hasMore = $latest->count() > self::MESSAGE_LIMIT;
            $messages = $latest->take(self::MESSAGE_LIMIT)->reverse()->values();
            $events = $ticket->events()->with('actor')->orderBy('created_at')->orderBy('id')->get();
            $closer = $events->last(fn (TicketEvent $event) => $event->type === 'closed')?->actor?->name;
        }

        return view('livewire.history.index', [
            'tickets' => $tickets,
            'ticket' => $ticket,
            'messages' => $messages,
            'hasMore' => $hasMore,
            'limit' => self::MESSAGE_LIMIT,
            'events' => TicketTimeline::changes($events),
            'timeline' => new TicketTimeline($events),
            'closer' => $closer,
            // Setor apagado que já teve ticket continua filtrável; o que nunca teve não polui a lista.
            'sectors' => Sector::withTrashed()
                ->where(fn ($query) => $query->whereNull('deleted_at')->orWhereIn('id', Ticket::query()->select('sector_id')->whereNotNull('sector_id')))
                ->orderBy('name')->get(['id', 'name', 'deleted_at']),
            'agents' => $user->canSeeAllSectors() ? User::query()->orderBy('name')->get(['id', 'name']) : null,
            'outcomes' => CloseOutcome::cases(),
            'filteredContact' => $this->contactId === null ? null : Contact::query()->find($this->contactId)?->label(),
        ]);
    }
}
