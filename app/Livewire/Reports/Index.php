<?php

namespace App\Livewire\Reports;

use App\Services\Reports\AttendanceReport;
use App\Settings\CompanySettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Relatórios')]
class Index extends Component
{
    public const PERIODS = ['hoje' => 'Hoje', '7' => '7 dias', '30' => '30 dias'];

    #[Url]
    public string $period = '7';

    public function mount(): void
    {
        $this->authorize('supervisor');
    }

    public function render(AttendanceReport $report): View
    {
        $period = array_key_exists($this->period, self::PERIODS) ? $this->period : '7';
        // "Hoje" é o dia de Brasília; o banco guarda em `app.timezone` e o construtor de consultas não converte o fuso.
        $now = CarbonImmutable::now(config()->string('app.display_timezone'));
        $from = $period === 'hoje' ? $now->startOfDay() : $now->subDays((int) $period)->startOfDay();

        return view('livewire.reports.index', [
            'data' => $report->for($from->setTimezone(config()->string('app.timezone')), $now->setTimezone(config()->string('app.timezone')), CompanySettings::slaMinutes()),
            'sla' => CompanySettings::slaMinutes(),
            'from' => $from,
        ]);
    }

    /** "3 min 12 s", ou um traço quando não há dado. */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return intdiv($seconds, 60).' min '.($seconds % 60).' s';
    }
}
