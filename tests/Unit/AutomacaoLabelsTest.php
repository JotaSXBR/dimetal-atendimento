<?php

namespace Tests\Unit;

use App\Enums\ClosedReason;
use App\Enums\NoteOrigin;
use PHPUnit\Framework\TestCase;

/** Na interface o valor gravado `bot` aparece como "Automação" (item 23 da leva 3). */
class AutomacaoLabelsTest extends TestCase
{
    public function test_rotulos_da_automacao_nos_enums(): void
    {
        $this->assertSame('Automação', NoteOrigin::BOT->label());
        $this->assertSame('Encerrado pela Automação', ClosedReason::BOT->label());
        $this->assertSame('bot', NoteOrigin::BOT->value);
    }
}
