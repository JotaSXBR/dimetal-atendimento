<?php

namespace App\Services\Reports;

use App\Enums\TicketStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Números de atendimento e SLA de primeira resposta, calculados no banco sobre os tickets abertos no período.
 *
 * @phpstan-type Agent array{name: string, assigned: int, closed: int, median_seconds: int|null}
 */
class AttendanceReport
{
    private const MEDIAN = 'percentile_cont(0.5) within group (order by extract(epoch from tickets.first_human_reply_at - tickets.opened_at))';

    /**
     * `overdue` não depende do período: são os tickets de agora que esperam resposta além do SLA.
     *
     * @return array{opened: int, median_seconds: int|null, sla_percent: float|null, overdue: int, by_sector: list<array{name: string, count: int}>, by_agent: list<Agent>}
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
                ->whereNull('first_human_reply_at')
                ->where('opened_at', '<', now()->subMinutes($slaMinutes))
                ->count(),
            'by_sector' => $this->bySector($period()),
            'by_agent' => $this->byAgent($period()),
        ];
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
