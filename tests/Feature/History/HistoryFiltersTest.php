<?php

namespace Tests\Feature\History;

use App\Enums\UserRole;
use App\Livewire\History\Index;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** Os filtros do Histórico moram num popover "Filtros": o padrão não conta como ativo, "Limpar" volta ao padrão. */
class HistoryFiltersTest extends TestCase
{
    use RefreshDatabase;

    private function page(UserRole $role = UserRole::ADMIN, array $params = []): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => $role]))->test(Index::class, $params);
    }

    private function defaultFrom(): string
    {
        return CarbonImmutable::now(config()->string('app.display_timezone'))->subDays(30)->toDateString();
    }

    public function test_a_tela_tem_a_busca_e_um_botao_filtros_com_os_campos_dentro_do_dialogo(): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->page()->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $this->assertSame(1, $xpath->query('//input[@aria-label="Buscar no histórico"]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-popover-trigger and contains(normalize-space(.), "Filtros")]')->length);

        foreach (['De', 'Até', 'Setor', 'Motivo', 'Atendente'] as $label) {
            $found = $xpath->query('//*[@role="dialog"]//label[contains(normalize-space(.), "'.$label.'")] | //*[@role="dialog"]//*[normalize-space(text())="'.$label.'"]');

            $this->assertGreaterThan(0, $found->length, "campo {$label} fora do diálogo de filtros");
        }
    }

    /**
     * Os campos são `wire:model` adiado: o valor só vai ao servidor numa requisição. Para ele não vazar numa requisição qualquer (a busca, um
     * clique numa linha), o popover aplica ao fechar com mudança (Esc, clique fora ou Aplicar) e "Limpar" descarta.
     */
    public function test_campos_adiados_e_o_popover_aplica_ao_fechar_com_mudanca(): void
    {
        $html = $this->page()->html();

        foreach (['from', 'to', 'sectorId', 'outcome', 'userId'] as $field) {
            $this->assertStringContainsString('wire:model="'.$field.'"', $html, $field);
            $this->assertStringNotContainsString('wire:model.live="'.$field.'"', $html, $field);
        }

        $this->assertStringContainsString('x-on:click="apply"', $html);
        $this->assertStringContainsString('x-on:click="reset"', $html);
    }

    public function test_com_os_padroes_nao_ha_filtro_ativo(): void
    {
        $page = $this->page();

        $this->assertSame($this->defaultFrom(), $page->get('from'));
        $this->assertSame(0, $page->viewData('activeFilters'));
        $page->assertDontSee('Filtros ·');
    }

    public function test_cada_campo_fora_do_padrao_conta_um(): void
    {
        $sector = Sector::factory()->create();

        $this->assertSame(1, $this->page()->set('to', '2026-11-30')->viewData('activeFilters'));
        $this->assertSame(1, $this->page()->set('from', '2026-01-01')->viewData('activeFilters'));
        $this->assertSame(2, $this->page()->set('to', '2026-11-30')->set('sectorId', $sector->id)->viewData('activeFilters'));
        $this->assertSame(3, $this->page()->set('sectorId', $sector->id)->set('outcome', 'resolvido')->set('userId', User::factory()->create()->id)->viewData('activeFilters'));
    }

    public function test_o_botao_mostra_a_contagem_quando_ha_filtros(): void
    {
        $sector = Sector::factory()->create();

        $this->page()->set('to', '2026-11-30')->set('sectorId', $sector->id)->assertSee('Filtros · 2');
    }

    /** Vindo de "Ver todos" de um contato não há janela padrão: `De` vazio é o padrão e qualquer data conta. */
    public function test_com_contato_o_padrao_de_de_e_vazio(): void
    {
        $contact = Contact::factory()->create();
        $page = $this->page(params: ['contactId' => $contact->id]);

        $this->assertSame('', $page->get('from'));
        $this->assertSame(0, $page->viewData('activeFilters'));
        $this->assertSame(1, $page->set('from', '2026-01-01')->viewData('activeFilters'));
    }

    public function test_limpar_volta_ao_padrao_e_zera_o_resto_e_a_pagina(): void
    {
        $sector = Sector::factory()->create();

        $page = $this->page()->set('from', '2026-01-01')->set('to', '2026-11-30')->set('sectorId', $sector->id)->set('outcome', 'resolvido')
            ->call('clearFilters');

        $this->assertSame($this->defaultFrom(), $page->get('from'));
        $page->assertSet('to', '')->assertSet('sectorId', null)->assertSet('outcome', '')->assertSet('userId', null);
        $this->assertSame(0, $page->viewData('activeFilters'));
    }

    public function test_limpar_nao_apaga_o_filtro_de_contato_nem_a_busca(): void
    {
        $contact = Contact::factory()->create();

        $this->page(params: ['contactId' => $contact->id])->set('q', 'maria')->set('to', '2026-11-30')->call('clearFilters')
            ->assertSet('contactId', $contact->id)->assertSet('q', 'maria')->assertSet('from', '');
    }

    public function test_o_filtro_de_atendente_so_existe_para_quem_ve_todos_os_setores(): void
    {
        $this->assertStringContainsString('Atendente', $this->page(UserRole::SUPERVISOR)->html());
        $this->assertStringNotContainsString('wire:model="userId"', $this->page(UserRole::ATENDENTE)->html());
    }
}
