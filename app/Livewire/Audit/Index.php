<?php

namespace App\Livewire\Audit;

use App\Models\TicketEvent;
use App\Models\User;
use App\Support\TicketEventText;
use App\Support\TicketTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Auditoria')]
class Index extends Component
{
    use WithPagination;

    public const PERIODS = ['hoje' => 'Hoje', '7' => '7 dias', '30' => '30 dias'];

    private const PER_PAGE = 25;

    #[Url]
    public string $period = '7';

    #[Url]
    public ?int $userId = null;

    #[Url]
    public string $type = '';

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    public function updatedUserId(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public static function typeLabel(string $type): string
    {
        return TicketEventText::label($type);
    }

    public function render(): View
    {
        $now = CarbonImmutable::now(config()->string('app.display_timezone'));
        $period = array_key_exists($this->period, self::PERIODS) ? $this->period : '7';
        $since = $period === 'hoje' ? $now->startOfDay() : $now->subDays((int) $period)->startOfDay();

        $events = TicketEvent::query()
            ->with(['ticket', 'actor'])
            ->where('created_at', '>=', $since->setTimezone(config()->string('app.timezone')))
            ->when($this->userId !== null, fn ($query) => $query->where('actor_user_id', $this->userId))
            ->when($this->type !== '', fn ($query) => $query->where('type', $this->type))
            ->latest('created_at')->latest('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.audit.index', [
            'events' => $events,
            'timeline' => new TicketTimeline($events->getCollection()),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'types' => TicketEventText::TYPES,
        ]);
    }
}
