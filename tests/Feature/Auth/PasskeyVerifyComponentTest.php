<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Str;
use Tests\TestCase;

class PasskeyVerifyComponentTest extends TestCase
{
    public function test_separador_usa_os_tokens_do_cartao(): void
    {
        $html = (string) $this->blade('<x-passkey-verify separator="Ou confirme com a senha" />');

        // O botão outline do Flux traz zinc próprio; confere só o que vem depois dele (erro e separador).
        $separador = Str::after($html, '</button>');

        $this->assertStringContainsString('Ou confirme com a senha', $separador);
        $this->assertStringContainsString('border-line', $separador);
        $this->assertStringContainsString('bg-surface', $separador);
        $this->assertStringNotContainsString('zinc-', $separador);
        $this->assertStringNotContainsString('bg-white', $separador);
    }
}
