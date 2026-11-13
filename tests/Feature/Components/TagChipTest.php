<?php

namespace Tests\Feature\Components;

use App\Enums\TagColor;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagChipTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_chip_usa_a_classe_do_tom_e_mostra_o_nome(): void
    {
        $tag = Tag::factory()->create(['name' => 'Revenda', 'color' => TagColor::AZUL]);

        $this->blade('<x-tag-chip :tag="$tag" />', ['tag' => $tag])
            ->assertSee('Revenda')
            ->assertSee('tag-azul', false)
            ->assertDontSee('style=', false);
    }

    public function test_a_etiqueta_da_conversa_vem_so_com_contorno(): void
    {
        $tag = Tag::factory()->create(['color' => TagColor::ROSA]);

        $this->blade('<x-tag-chip :tag="$tag" outline />', ['tag' => $tag])
            ->assertSee('border', false)
            ->assertDontSee('bg-[var(--tag-bg)]', false);
    }
}
