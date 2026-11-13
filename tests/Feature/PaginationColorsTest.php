<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Contacts\Index;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A paginação padrão do Laravel usa a paleta `gray-*` (cinza-azulado); a do app usa os tokens, nos dois temas. */
class PaginationColorsTest extends TestCase
{
    use RefreshDatabase;

    private function paginationHtml(): string
    {
        Contact::factory()->count(30)->create();
        $html = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Index::class)->html();

        $this->assertSame(1, preg_match('/<nav role="navigation".*?<\/nav>/s', $html, $m), 'navegação da paginação ausente');

        return $m[0];
    }

    public function test_paginacao_usa_os_tokens_do_app_e_nao_a_paleta_gray(): void
    {
        $nav = $this->paginationHtml();

        $this->assertStringNotContainsString('gray-', $nav);
        $this->assertStringContainsString('data-flux-button', $nav);
        $this->assertStringContainsString('aria-current="page"', $nav);
    }

    /** Duplo clique não dispara duas requisições: os botões ficam desativados enquanto a página carrega. */
    public function test_botoes_ficam_desativados_durante_o_carregamento(): void
    {
        $this->assertStringContainsString('wire:loading.attr="disabled"', $this->paginationHtml());
    }

    public function test_proxima_pagina_funciona_pelo_componente(): void
    {
        Contact::factory()->count(30)->create();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $page = Livewire::actingAs($admin)->test(Index::class)->assertSet('paginators.page', 1)->call('nextPage')->assertSet('paginators.page', 2);
        $page->call('previousPage')->assertSet('paginators.page', 1)->call('gotoPage', 2)->assertSet('paginators.page', 2);
    }

    public function test_item_desativado_tem_papel_para_aceitar_aria_label(): void
    {
        $this->assertStringNotContainsString('<span aria-disabled="true" aria-label=', $this->paginationHtml());
    }
}
