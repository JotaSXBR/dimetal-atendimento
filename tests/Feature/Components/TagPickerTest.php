<?php

namespace Tests\Feature\Components;

use App\Enums\TagScope;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_modo_adicionar_e_um_combobox_que_chama_o_metodo_e_leva_as_etiquetas_em_json(): void
    {
        Tag::factory()->create(['name' => 'Orçamento', 'scope' => TagScope::CONVERSA]);
        Tag::factory()->create(['name' => 'Urgente', 'scope' => TagScope::CONVERSA]);

        $this->blade('<x-tag-picker :tags="$tags" label="Adicionar etiqueta à conversa" method="addTag" />', ['tags' => Tag::query()->get()])
            ->assertSee('role="combobox"', false)
            ->assertSee('aria-label="Adicionar etiqueta à conversa"', false)
            ->assertSee('data-method="addTag"', false)
            ->assertSee('role="listbox"', false)
            ->assertSee('Orçamento')
            ->assertSee('Urgente')
            ->assertDontSee('data-model');
    }

    public function test_modo_filtro_mostra_a_etiqueta_escolhida_no_campo_e_liga_ao_model(): void
    {
        $urgente = Tag::factory()->create(['name' => 'Urgente', 'scope' => TagScope::CONVERSA]);

        $this->blade('<x-tag-picker :tags="$tags" label="Filtrar por etiqueta" model="tagId" :selected="$selected" placeholder="Todas as etiquetas" />', ['tags' => Tag::query()->get(), 'selected' => $urgente->id])
            ->assertSee('data-model="tagId"', false)
            ->assertSee('placeholder="Urgente"', false)
            ->assertDontSee('data-method');

        $this->blade('<x-tag-picker :tags="$tags" label="Filtrar por etiqueta" model="tagId" placeholder="Todas as etiquetas" />', ['tags' => Tag::query()->get()])
            ->assertSee('placeholder="Todas as etiquetas"', false);
    }

    public function test_sem_etiquetas_nao_renderiza_nada(): void
    {
        $this->blade('<x-tag-picker :tags="$tags" label="Adicionar etiqueta" method="addTag" />', ['tags' => collect()])
            ->assertDontSee('combobox');
    }
}
