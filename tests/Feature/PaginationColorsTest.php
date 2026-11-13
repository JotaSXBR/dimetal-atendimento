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

    public function test_item_desativado_tem_papel_para_aceitar_aria_label(): void
    {
        $this->assertStringNotContainsString('<span aria-disabled="true" aria-label=', $this->paginationHtml());
    }
}
