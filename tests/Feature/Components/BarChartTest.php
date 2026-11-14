<?php

namespace Tests\Feature\Components;

use Tests\TestCase;

class BarChartTest extends TestCase
{
    /** @param  list<array{label: string, value: int}>  $rows */
    private function chart(array $rows): string
    {
        return (string) $this->blade('<x-bar-chart :rows="$rows" caption="Volume por setor" />', ['rows' => $rows]);
    }

    public function test_uma_linha_por_item_com_rotulo_valor_e_dica_exata(): void
    {
        $html = $this->chart([['label' => 'Vendas', 'value' => 6], ['label' => 'Suporte', 'value' => 2]]);

        $this->assertSame(2, substr_count($html, 'data-testid="bar-row"'));
        $this->assertStringContainsString('<title>Vendas: 6 (75%)</title>', $html);
        $this->assertStringContainsString('<title>Suporte: 2 (25%)</title>', $html);
    }

    public function test_a_barra_do_maior_enche_e_a_metade_vale_metade(): void
    {
        $html = $this->chart([['label' => 'A', 'value' => 8], ['label' => 'B', 'value' => 4]]);

        $this->assertStringContainsString('width="100"', $html);
        $this->assertStringContainsString('width="50"', $html);
    }

    public function test_tudo_zero_nao_divide_por_zero(): void
    {
        $html = $this->chart([['label' => 'A', 'value' => 0]]);

        $this->assertStringContainsString('<title>A: 0 (0%)</title>', $html);
        $this->assertStringContainsString('width="0"', $html);
    }

    public function test_leitor_de_tela_le_a_tabela_e_nao_o_desenho(): void
    {
        $html = $this->chart([['label' => 'Vendas', 'value' => 6]]);

        $this->assertStringContainsString('<table class="sr-only">', $html);
        $this->assertStringContainsString('<caption>Volume por setor</caption>', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_rotulo_e_escapado(): void
    {
        $html = $this->chart([['label' => '<script>x</script>', 'value' => 1]]);

        $this->assertStringNotContainsString('<script>x</script>', $html);
    }
}
