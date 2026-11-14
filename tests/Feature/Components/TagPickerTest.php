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

    public function test_clicar_na_barra_de_rolagem_ou_no_padding_da_lista_nao_tira_o_foco_do_campo(): void
    {
        Tag::factory()->create(['name' => 'Orçamento', 'scope' => TagScope::CONVERSA]);

        $html = $this->blade('<x-tag-picker :tags="$tags" label="Adicionar" method="addTag" />', ['tags' => Tag::query()->get()])->__toString();

        $this->assertMatchesRegularExpression('/<ul[^>]*role="listbox"[^>]*x-on:mousedown\.prevent|<ul[^>]*x-on:mousedown\.prevent[^>]*role="listbox"/', $html);
    }

    /** Filhos do listbox são só as opções (e títulos de grupo escondidos do leitor de tela); o aviso de vazio é um status ao lado. */
    public function test_a_mensagem_de_vazio_fica_fora_do_listbox_como_status(): void
    {
        Tag::factory()->create(['name' => 'Orçamento', 'scope' => TagScope::CONVERSA]);

        $html = $this->blade('<x-tag-picker :tags="$tags" label="Adicionar" method="addTag" />', ['tags' => Tag::query()->get()])->__toString();

        $this->assertSame(1, preg_match('/<ul[^>]*role="listbox".*?<\/ul>/s', $html, $list));
        $this->assertStringNotContainsString('Nenhuma etiqueta encontrada', $list[0]);
        $this->assertMatchesRegularExpression('/role="status"[^>]*>[^<]*Nenhuma etiqueta encontrada/', $html);
        $this->assertMatchesRegularExpression('/<p[^>]*aria-hidden="true"[^>]*x-text="item\.group"|<p[^>]*x-text="item\.group"[^>]*aria-hidden="true"/', $html);
    }

    public function test_esc_e_tratado_pelo_seletor_para_nao_fechar_a_busca_da_fila_junto(): void
    {
        Tag::factory()->create(['name' => 'Orçamento', 'scope' => TagScope::CONVERSA]);

        $this->blade('<x-tag-picker :tags="$tags" label="Adicionar" method="addTag" />', ['tags' => Tag::query()->get()])
            ->assertSee('x-on:keydown.escape="escape"', false)
            ->assertSee('x-on:input="typed"', false);
    }

    public function test_sem_etiquetas_nao_renderiza_nada(): void
    {
        $this->blade('<x-tag-picker :tags="$tags" label="Adicionar etiqueta" method="addTag" />', ['tags' => collect()])
            ->assertDontSee('combobox');
    }
}
