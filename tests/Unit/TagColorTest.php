<?php

namespace Tests\Unit;

use App\Enums\TagColor;
use PHPUnit\Framework\TestCase;

class TagColorTest extends TestCase
{
    public function test_o_verde_antigo_vira_o_tom_mais_proximo(): void
    {
        $this->assertInstanceOf(TagColor::class, TagColor::nearest('#2F855A'));
    }

    public function test_cada_tom_e_o_mais_proximo_do_proprio_swatch(): void
    {
        foreach (TagColor::cases() as $tone) {
            $this->assertSame($tone, TagColor::nearest($tone->swatch()), $tone->value);
        }
    }

    public function test_so_o_nome_do_tom_vale_nao_a_cor_livre(): void
    {
        $this->assertSame(TagColor::AZUL, TagColor::tryFrom('azul'));
        $this->assertNull(TagColor::tryFrom('#2F855A'));
    }

    public function test_o_padrao_existe_e_todo_tom_tem_rotulo(): void
    {
        $this->assertSame(TagColor::ARDOSIA, TagColor::default());

        foreach (TagColor::cases() as $tone) {
            $this->assertNotSame('', $tone->label());
        }
    }

    public function test_hex_invalido_cai_no_padrao(): void
    {
        $this->assertSame(TagColor::default(), TagColor::nearest('azul'));
    }
}
