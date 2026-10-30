<?php

namespace Tests\Feature\Sla;

use App\Models\BusinessHour;
use App\Models\Holiday;
use App\Services\Inbox\BusinessHours;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessSecondsTest extends TestCase
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

    /** O resultado no horário de São Paulo, para comparar sem pensar em UTC. */
    private function add(string $from, int $seconds): string
    {
        return (new BusinessHours)->addBusinessSeconds($this->sp($from), $seconds)->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s');
    }

    public function test_dentro_da_faixa_soma_direto(): void
    {
        $this->schedule();

        $this->assertSame('2026-10-30 10:20:00', $this->add('2026-10-30 10:00', 20 * 60));
    }

    public function test_atravessa_o_almoco_e_continua_as_13h(): void
    {
        $this->schedule();

        $this->assertSame('2026-10-30 13:10:00', $this->add('2026-10-30 11:50', 20 * 60));
    }

    public function test_atravessa_o_fechamento_e_continua_na_abertura_seguinte(): void
    {
        $this->schedule();

        // Sexta 17h50 + 20 min: 10 min até as 18h, o resto no sábado, que abre às 8h.
        $this->assertSame('2026-10-31 08:10:00', $this->add('2026-10-30 17:50', 20 * 60));
    }

    public function test_atravessa_o_fim_de_semana_ate_segunda(): void
    {
        $this->schedule();

        // Sábado 11h50 + 20 min: 10 min até o meio-dia; domingo fechado; segunda 8h10.
        $this->assertSame('2026-11-02 08:10:00', $this->add('2026-10-31 11:50', 20 * 60));
    }

    public function test_varias_faixas_e_dias_numa_conta_so(): void
    {
        $this->schedule();

        // Sexta 17h55 + 5 h: 5 min na sexta, 4 h no sábado (8h-12h), faltam 55 min: segunda 8h55.
        $this->assertSame('2026-11-02 08:55:00', $this->add('2026-10-30 17:55', 5 * 3600));
    }

    public function test_pula_feriado(): void
    {
        $this->schedule();
        Holiday::create(['date' => '2026-11-02', 'name' => 'Finados']);

        $this->assertSame('2026-11-03 08:10:00', $this->add('2026-10-31 11:50', 20 * 60));
    }

    public function test_comeca_na_proxima_abertura_se_esta_fechado(): void
    {
        $this->schedule();

        // Terça 22h: a espera só começa a contar quarta às 8h.
        $this->assertSame('2026-10-28 08:10:00', $this->add('2026-10-27 22:00', 10 * 60));
    }

    public function test_fronteiras_exatas_de_abertura_e_de_fechamento(): void
    {
        $this->schedule();

        $this->assertSame('2026-10-30 09:00:00', $this->add('2026-10-30 08:00', 3600), 'a abertura é expediente');
        $this->assertSame('2026-10-30 13:10:00', $this->add('2026-10-30 12:00', 10 * 60), 'o fechamento não é: conta a partir das 13h');
        $this->assertSame('2026-10-31 08:10:00', $this->add('2026-10-30 18:00', 10 * 60));
        $this->assertSame('2026-10-30 18:00:00', $this->add('2026-10-30 17:00', 3600), 'esgotar a faixa exata vence no fechamento');
    }

    public function test_sem_faixa_cadastrada_soma_direto(): void
    {
        $this->assertSame('2026-10-31 23:10:00', $this->add('2026-10-31 23:00', 10 * 60));
    }

    public function test_devolve_no_fuso_do_banco(): void
    {
        $this->schedule();

        $result = (new BusinessHours)->addBusinessSeconds($this->sp('2026-10-30 10:00'), 20 * 60);

        $this->assertSame(config('app.timezone'), $result->getTimezone()->getName());
        $this->assertSame('2026-10-30 13:20:00', $result->format('Y-m-d H:i:s'), '10h20 de Brasília é 13h20 UTC');
    }

    public function test_when_label_hoje_amanha_e_dia_da_semana(): void
    {
        $now = $this->sp('2026-10-30 10:00');
        $hours = new BusinessHours;

        $this->assertSame('hoje às 10h20', $hours->whenLabel($this->sp('2026-10-30 10:20'), $now));
        $this->assertSame('amanhã às 8h10', $hours->whenLabel($this->sp('2026-10-31 08:10'), $now));
        $this->assertSame('segunda-feira às 8h30', $hours->whenLabel($this->sp('2026-11-02 08:30'), $now));
    }
}
