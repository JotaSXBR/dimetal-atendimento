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

    private function event(string $command): object
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, $command));
        $this->assertNotNull($event, $command.' não está agendado');

        return $event;
    }

    public function test_backup_diario_as_0215_sem_sobreposicao(): void
    {
        $event = $this->event('app:backup-database');

        $this->assertSame('15 2 * * *', $event->expression);
        $this->assertSame('America/Sao_Paulo', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(60, $event->expiresAt);
    }

    public function test_checagem_de_backup_de_hora_em_hora(): void
    {
        $event = $this->event('app:check-backup-health');

        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
