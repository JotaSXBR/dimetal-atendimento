<?php

namespace Tests\Unit\Bot;

use App\Services\Bot\Vars;
use PHPUnit\Framework\TestCase;

class VarsBuiltinTest extends TestCase
{
    public function test_as_variaveis_embutidas_sao_as_que_o_bot_resolve(): void
    {
        $this->assertSame(['nome', 'empresa', 'protocolo', 'setor', 'horario', 'proximo_horario'], array_keys(Vars::BUILTIN));

        foreach (Vars::BUILTIN as $description) {
            $this->assertNotSame('', $description);
        }
    }
}
