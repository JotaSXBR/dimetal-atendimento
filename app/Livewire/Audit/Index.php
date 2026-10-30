<?php

namespace App\Livewire\Audit;

use App\Enums\CloseOutcome;
use App\Models\TicketEvent;
use App\Models\User;
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

    private const TYPES = [
        'opened' => 'Ticket aberto',
        'claimed' => 'Assumido',
        'closed' => 'Encerrado',
        'transferred' => 'Transferido',
        'reassigned' => 'Reatribuído',
        'taken_over' => 'Tomado',
        'merged' => 'Mesclado',
        'message_out' => 'Mensagem enviada',
        'after_hours_reply' => 'Resposta fora do horário',
        'media_purged' => 'Mídia apagada',
    ];

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
        return self::TYPES[$type] ?? $type;
    }

    /** O que aconteceu, em texto: motivo e resumo do encerramento; nos demais, o conteúdo bruto do evento. */
    public static function detail(TicketEvent $event): string
    {
        $payload = $event->payload ?? [];

        if ($event->type === 'closed') {
            $outcome = is_string($payload['outcome'] ?? null) ? CloseOutcome::tryFrom($payload['outcome'])?->label() : null;
            $reason = isset($payload['outcome']) || ! is_string($payload['reason'] ?? null) ? null : $payload['reason'];
            $summary = is_string($payload['summary'] ?? null) ? $payload['summary'] : null;

            return implode(' · ', array_filter([$outcome, $summary, $reason]));
        }

        return $payload === [] ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
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
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'types' => self::TYPES,
        ]);
    }
}
