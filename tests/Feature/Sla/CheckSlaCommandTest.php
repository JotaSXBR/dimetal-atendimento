<?php

namespace Tests\Feature\Sla;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CheckSlaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_comando_roda_o_servico_e_grava_a_batida(): void
    {
        $this->assertNull(Cache::get('sla:last-run'));

        $this->artisan('app:check-sla')->assertSuccessful();

        $this->assertNotNull(Cache::get('sla:last-run'));
    }

    public function test_a_agenda_tem_o_comando_a_cada_minuto_sem_sobreposicao_e_em_segundo_plano(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'app:check-sla'));

        $this->assertNotNull($event, 'app:check-sla não está agendado');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertLessThanOrEqual(5, $event->expiresAt, 'o bloqueio de uma rodada morta expira em minutos, não em 24 h');
        $this->assertTrue($event->runInBackground);
    }
}
