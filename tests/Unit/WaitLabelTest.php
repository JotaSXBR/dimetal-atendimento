<?php

namespace Tests\Unit;

use App\Support\WaitLabel;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class WaitLabelTest extends TestCase
{
    public function test_formata_a_espera(): void
    {
        $now = CarbonImmutable::parse('2026-11-07 12:00:00');

        $this->assertSame('agora', WaitLabel::since($now->subSeconds(30), $now));
        $this->assertSame('há 1 min', WaitLabel::since($now->subMinute(), $now));
        $this->assertSame('há 59 min', WaitLabel::since($now->subMinutes(59), $now));
        $this->assertSame('há 1 h', WaitLabel::since($now->subMinutes(60), $now));
        $this->assertSame('há 23 h', WaitLabel::since($now->subMinutes(23 * 60 + 59), $now));
        $this->assertSame('há 1 d', WaitLabel::since($now->subDay(), $now));
        $this->assertSame('agora', WaitLabel::since($now->addMinutes(5), $now));
    }
}
