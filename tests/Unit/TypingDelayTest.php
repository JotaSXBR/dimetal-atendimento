<?php

namespace Tests\Unit;

use App\Services\Inbox\TypingDelay;
use PHPUnit\Framework\TestCase;

class TypingDelayTest extends TestCase
{
    public function test_o_atraso_fica_entre_1_e_6_segundos(): void
    {
        foreach ([str_repeat('a', 0), 'oi', str_repeat('a', 4096)] as $text) {
            for ($i = 0; $i < 50; $i++) {
                $ms = TypingDelay::milliseconds($text);

                $this->assertGreaterThanOrEqual(1000, $ms);
                $this->assertLessThanOrEqual(6000, $ms);
            }
        }
    }

    public function test_o_teto_informado_nunca_e_passado(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertLessThanOrEqual(3000, TypingDelay::milliseconds(str_repeat('a', 4096), 3000));
            $this->assertGreaterThanOrEqual(1000, TypingDelay::milliseconds('oi', 3000));
        }
    }

    public function test_texto_maior_nao_atrasa_menos_na_media(): void
    {
        $average = fn (string $text): float => array_sum(array_map(fn () => TypingDelay::milliseconds($text), range(1, 200))) / 200;

        $this->assertGreaterThan($average('oi'), $average(str_repeat('a', 120)));
    }
}
