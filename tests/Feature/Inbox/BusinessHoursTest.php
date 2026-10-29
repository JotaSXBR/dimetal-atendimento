<?php

namespace Tests\Feature\Inbox;

use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Services\Inbox\BusinessHours;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHoursTest extends TestCase
{
    use RefreshDatabase;

    /** Sexta 30/10/2026, sábado 31/10, domingo 01/11 e segunda 02/11, no horário de São Paulo. */
    private function sp(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, 'America/Sao_Paulo');
    }

    /** Segunda a sexta 8h-12h e 13h-18h; sábado 8h-12h; domingo fechado. */
    private function schedule(): void
    {
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            BusinessHour::create(['weekday' => $weekday, 'opens_at' => '08:00', 'closes_at' => '12:00']);
            BusinessHour::create(['weekday' => $weekday, 'opens_at' => '13:00', 'closes_at' => '18:00']);
        }

        BusinessHour::create(['weekday' => 6, 'opens_at' => '08:00', 'closes_at' => '12:00']);
    }

    private function hours(): BusinessHours
    {
        return new BusinessHours;
    }

    public function test_sem_nenhuma_faixa_esta_sempre_aberto_e_nao_ha_proxima_abertura(): void
    {
        $this->assertTrue($this->hours()->isOpen($this->sp('2026-10-31 23:00')));
        $this->assertNull($this->hours()->nextOpening($this->sp('2026-10-31 23:00')));
        $this->assertSame('', $this->hours()->nextOpeningLabel($this->sp('2026-10-31 23:00')));
        $this->assertSame('', $this->hours()->summary());
    }

    public function test_aberto_dentro_da_faixa_e_fechado_na_pausa_e_fora(): void
    {
        $this->schedule();

        $this->assertTrue($this->hours()->isOpen($this->sp('2026-10-30 10:00')));
        $this->assertFalse($this->hours()->isOpen($this->sp('2026-10-30 12:30')));
        $this->assertTrue($this->hours()->isOpen($this->sp('2026-10-30 15:00')));
        $this->assertFalse($this->hours()->isOpen($this->sp('2026-10-30 19:00')));
        $this->assertFalse($this->hours()->isOpen($this->sp('2026-11-01 10:00')));
    }

    public function test_abre_no_minuto_exato_e_fecha_no_minuto_exato(): void
    {
        $this->schedule();

        $this->assertTrue($this->hours()->isOpen($this->sp('2026-10-30 08:00:00')));
        $this->assertFalse($this->hours()->isOpen($this->sp('2026-10-30 12:00:00')));
        $this->assertTrue($this->hours()->isOpen($this->sp('2026-10-30 13:00:00')));
        $this->assertFalse($this->hours()->isOpen($this->sp('2026-10-30 18:00:00')));
    }

    public function test_feriado_fecha_o_dia_todo_mesmo_dentro_da_faixa(): void
    {
        $this->schedule();
        Holiday::create(['date' => '2026-10-30', 'name' => 'Feriado de teste']);

        $this->assertFalse($this->hours()->isOpen($this->sp('2026-10-30 10:00')));
        $this->assertTrue($this->hours()->isOpen($this->sp('2026-11-02 10:00')));
    }

    public function test_compara_no_fuso_de_exibicao_e_nao_em_utc(): void
    {
        $this->schedule();

        // 23:30 UTC de sexta ainda é 20:30 em São Paulo (fechado); 11:00 UTC são 08:00 (aberto).
        $this->assertFalse($this->hours()->isOpen(CarbonImmutable::parse('2026-10-30 23:30:00', 'UTC')));
        $this->assertTrue($this->hours()->isOpen(CarbonImmutable::parse('2026-10-30 11:00:00', 'UTC')));
    }

    public function test_proxima_abertura_hoje_amanha_e_dia_da_semana(): void
    {
        $this->schedule();

        $this->assertSame('2026-10-30 13:00', $this->hours()->nextOpening($this->sp('2026-10-30 12:30'))?->format('Y-m-d H:i'));
        $this->assertSame('hoje às 13h', $this->hours()->nextOpeningLabel($this->sp('2026-10-30 12:30')));

        $this->assertSame('2026-10-31 08:00', $this->hours()->nextOpening($this->sp('2026-10-30 19:00'))?->format('Y-m-d H:i'));
        $this->assertSame('amanhã às 8h', $this->hours()->nextOpeningLabel($this->sp('2026-10-30 19:00')));

        $this->assertSame('2026-11-02 08:00', $this->hours()->nextOpening($this->sp('2026-10-31 13:00'))?->format('Y-m-d H:i'));
        $this->assertSame('segunda-feira às 8h', $this->hours()->nextOpeningLabel($this->sp('2026-10-31 13:00')));
    }

    public function test_proxima_abertura_pula_feriado_e_mostra_minutos(): void
    {
        $this->schedule();
        Holiday::create(['date' => '2026-10-31', 'name' => 'Sábado fechado']);
        BusinessHour::query()->where('weekday', 1)->where('opens_at', '08:00')->update(['opens_at' => '08:30']);

        $this->assertSame('2026-11-02 08:30', $this->hours()->nextOpening($this->sp('2026-10-30 19:00'))?->format('Y-m-d H:i'));
        $this->assertSame('segunda-feira às 8h30', $this->hours()->nextOpeningLabel($this->sp('2026-10-30 19:00')));
    }

    public function test_resumo_agrupa_dias_iguais(): void
    {
        $this->schedule();

        $this->assertSame('de segunda a sexta, das 8h às 12h e das 13h às 18h; sábado, das 8h às 12h', $this->hours()->summary());
    }

    public function test_resumo_de_dois_dias_e_de_dia_isolado(): void
    {
        BusinessHour::create(['weekday' => 2, 'opens_at' => '09:00', 'closes_at' => '17:30']);
        BusinessHour::create(['weekday' => 3, 'opens_at' => '09:00', 'closes_at' => '17:30']);
        BusinessHour::create(['weekday' => 5, 'opens_at' => '09:00', 'closes_at' => '12:00']);

        $this->assertSame('terça e quarta, das 9h às 17h30; sexta, das 9h às 12h', $this->hours()->summary());
    }
}
