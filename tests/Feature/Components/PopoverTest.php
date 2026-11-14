<?php

namespace Tests\Feature\Components;

use Tests\TestCase;

class PopoverTest extends TestCase
{
    private function html(): string
    {
        return $this->blade('<x-popover id="pop-x" label="Filtros do teste"><x-slot:trigger><button type="button" data-popover-trigger x-on:click="toggle" aria-controls="pop-x">Abrir</button></x-slot:trigger><input name="campo"></x-popover>')->__toString();
    }

    public function test_o_painel_e_um_dialogo_nomeado_ligado_ao_botao(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('id="pop-x"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-label="Filtros do teste"', $html);
        $this->assertStringContainsString('data-popover-panel', $html);
        $this->assertStringContainsString('x-data="popover"', $html);
    }

    /** Esc fecha mesmo com o foco fora do painel (clicou numa área não focável): o ouvinte é da janela e só age com o painel aberto. */
    public function test_esc_e_ouvido_na_janela_e_clique_fora_fecha(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('x-on:keydown.escape.window="escape"', $html);
        $this->assertStringContainsString('x-on:click.outside="dismiss"', $html);
    }

    /** Campos `wire:model` adiados só valem numa requisição: o painel marca mudança nos campos para aplicar ao fechar. */
    public function test_mudanca_nos_campos_marca_o_painel_como_alterado(): void
    {
        $this->assertStringContainsString('x-on:change="touch"', $this->html());
    }
}
