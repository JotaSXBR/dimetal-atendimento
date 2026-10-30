<?php

namespace App\Services\Reports;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Números de atendimento e SLA de primeira resposta, calculados no banco sobre os tickets abertos no período, e a lista de quem
 * espera resposta agora (perto de estourar ou estourado).
 *
 * @phpstan-type Agent array{name: string, assigned: int, closed: int, median_seconds: int|null}
 * @phpstan-type Waiting array{contact: string, sector: string|null, agent: string|null, since: CarbonImmutable, due: CarbonImmutable, state: string}
 */
class AttendanceReport
{
    /** A lista de espera mostra os mais atrasados primeiro e para em 50. */
    private const WAITING_LIMIT = 50;

    private const MEDIAN = 'percentile_cont(0.5) within group (order by extract(epoch from tickets.first_human_reply_at - tickets.opened_at))';

    /**
     * `overdue` e `waiting` não dependem do período: são os tickets de agora que esperam resposta e já passaram do vencimento do SLA
     * (contado só no expediente) ou estão perto dele.
     *
     * @return array{opened: int, median_seconds: int|null, sla_percent: float|null, overdue: int, waiting: list<Waiting>, by_sector: list<array{name: string, count: int}>, by_agent: list<Agent>}
     */
    public function for(CarbonInterface $from, CarbonInterface $to, int $slaMinutes): array
    {
        $period = fn (): Builder => DB::table('tickets')->whereBetween('tickets.opened_at', [$from, $to]);
        $answered = fn (): Builder => $period()->whereNotNull('tickets.first_human_reply_at');

        $sla = $answered()->selectRaw('count(*) as total, count(*) filter (where tickets.first_human_reply_at - tickets.opened_at <= make_interval(secs => ?)) as within', [$slaMinutes * 60])->first();
        $total = $this->int($sla->total ?? 0);

        return [
            'opened' => $period()->count(),
            'median_seconds' => $this->nullableInt($answered()->selectRaw(self::MEDIAN.' as median')->value('median')),
            'sla_percent' => $total === 0 ? null : round($this->int($sla->within ?? 0) / $total * 100, 1),
            'overdue' => DB::table('tickets')
                ->where('status', '<>', TicketStatus::ENCERRADO->value)
                ->whereIn('status', [TicketStatus::AGUARDANDO->value, TicketStatus::EM_ATENDIMENTO->value])
                ->whereNotNull('waiting_since')
                ->where('sla_due_at', '<=', now())
                ->count(),
            'waiting' => $this->waiting(),
            'by_sector' => $this->bySector($period()),
            'by_agent' => $this->byAgent($period()),
        ];
    }

    /**
     * @return list<Waiting>
     */
    private function waiting(): array
    {
        $now = now();
        $rows = [];

        $tickets = Ticket::query()
            ->open()
            ->whereIn('status', [TicketStatus::AGUARDANDO->value, TicketStatus::EM_ATENDIMENTO->value])
            ->whereNotNull('waiting_since')
            ->whereNotNull('sla_due_at')
            ->where('sla_warn_at', '<=', $now)
            ->with(['contact', 'sector', 'assignee'])
            ->orderBy('sla_due_at')
            ->orderBy('id')
            ->limit(self::WAITING_LIMIT)
            ->get();

        foreach ($tickets as $ticket) {
            if ($ticket->waiting_since === null || $ticket->sla_due_at === null) {
                continue;
            }

            $rows[] = [
                'contact' => $ticket->contact?->label() ?? '—',
                'sector' => $ticket->sector?->name,
                'agent' => $ticket->assignee?->name,
                'since' => $ticket->waiting_since,
                'due' => $ticket->sla_due_at,
                'state' => $ticket->slaState($now) ?? 'perto',
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{name: string, count: int}>
     */
    private function bySector(Builder $period): array
    {
        return array_values($period->leftJoin('sectors', 'sectors.id', '=', 'tickets.sector_id')
            ->groupBy('sectors.name')
            ->selectRaw("coalesce(sectors.name, 'Fila geral') as name, count(*) as count")
            ->orderByDesc('count')->orderBy('name')
            ->get()
            ->map(fn (object $row): array => ['name' => $this->text($row->name), 'count' => $this->int($row->count)])
            ->all());
    }

    /**
     * @return list<Agent>
     */
    private function byAgent(Builder $period): array
    {
        return array_values($period->join('users', 'users.id', '=', 'tickets.assigned_user_id')
            ->groupBy('users.id', 'users.name')
            ->selectRaw("users.name as name, count(*) as assigned, count(*) filter (where tickets.status = 'encerrado') as closed, ".self::MEDIAN.' filter (where tickets.first_human_reply_at is not null) as median')
            ->orderByDesc('assigned')->orderBy('users.name')
            ->get()
            ->map(fn (object $row): array => [
                'name' => $this->text($row->name),
                'assigned' => $this->int($row->assigned),
                'closed' => $this->int($row->closed),
                'median_seconds' => $this->nullableInt($row->median),
            ])
            ->all());
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value) : 0;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }
}
