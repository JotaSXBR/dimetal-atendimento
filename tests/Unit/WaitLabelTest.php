<?php

namespace Tests\Unit;

use App\Support\WaitLabel;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WaitLabelTest extends TestCase
{
    /** O texto do servidor é o mesmo que o navegador escreve depois (`relativeTime(..., 'narrow')`), para o card não piscar ao carregar. */
    public function test_o_texto_do_servidor_e_o_do_navegador(): void
    {
        $now = CarbonImmutable::parse('2026-11-07 12:00:00');

        $this->assertSame('há 0 seg.', WaitLabel::narrow($now, $now));
        $this->assertSame('há 5 seg.', WaitLabel::narrow($now->subSeconds(5), $now));
        $this->assertSame('há 59 seg.', WaitLabel::narrow($now->subSeconds(59), $now));
        $this->assertSame('há 1 min.', WaitLabel::narrow($now->subMinute(), $now));
        $this->assertSame('há 12 min.', WaitLabel::narrow($now->subMinutes(12), $now));
        $this->assertSame('há 59 min.', WaitLabel::narrow($now->subMinutes(59), $now));
        $this->assertSame('há 1 h', WaitLabel::narrow($now->subMinutes(60), $now));
        $this->assertSame('há 23 h', WaitLabel::narrow($now->subMinutes(23 * 60 + 59), $now));
        $this->assertSame('há 1 dia', WaitLabel::narrow($now->subDay(), $now));
        $this->assertSame('há 2 dias', WaitLabel::narrow($now->subDays(2), $now));
        $this->assertSame('há 40 dias', WaitLabel::narrow($now->subDays(40), $now));
    }

    public function test_instante_no_futuro_vira_zero(): void
    {
        $now = CarbonImmutable::parse('2026-11-07 12:00:00');

        $this->assertSame('há 0 seg.', WaitLabel::narrow($now->addMinutes(5), $now));
    }
}
