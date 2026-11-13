<?php

namespace App\Livewire\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** A auditoria do sistema (acesso, usuários, backup e restauração, configurações), só para o admin. A de tickets é `Audit\Index`. */
#[Title('Auditoria do sistema')]
class SystemLog extends Component
{
    use WithPagination;

    private const PER_PAGE = 25;

    #[Url]
    public string $period = '7';

    #[Url]
    public string $group = '';

    #[Url]
    public ?int $userId = null;

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    public function updatedGroup(): void
    {
        $this->resetPage();
    }

    public function updatedUserId(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $now = CarbonImmutable::now(config()->string('app.display_timezone'));
        $period = array_key_exists($this->period, Index::PERIODS) ? $this->period : '7';
        $since = $period === 'hoje' ? $now->startOfDay() : $now->subDays((int) $period)->startOfDay();

        $actions = in_array($this->group, AuditAction::groups(), true)
            ? array_map(fn (AuditAction $action): string => $action->value, array_filter(AuditAction::cases(), fn (AuditAction $action): bool => $action->group() === $this->group))
            : null;

        return view('livewire.audit.system-log', [
            'logs' => AuditLog::query()
                ->where('created_at', '>=', $since->setTimezone(config()->string('app.timezone')))
                ->when($actions !== null, fn ($query) => $query->whereIn('action', $actions))
                ->when($this->userId !== null, fn ($query) => $query->where('actor_user_id', $this->userId))
                ->latest('created_at')->latest('id')
                ->paginate(self::PER_PAGE),
            'groups' => AuditAction::groups(),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
