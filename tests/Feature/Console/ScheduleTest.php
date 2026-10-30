<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_nenhuma_tarefa_agendada_segura_o_bloqueio_por_mais_de_60_minutos(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => $event->withoutOverlapping);

        $this->assertGreaterThanOrEqual(4, $events->count());

        foreach ($events as $event) {
            $this->assertLessThanOrEqual(60, $event->expiresAt, (string) $event->command.': o padrão de 24 h prende a tarefa depois de um deploy que mata o processo');
        }
    }
}
