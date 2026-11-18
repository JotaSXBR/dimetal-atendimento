<?php

namespace Tests\Feature\Reports;

use App\Enums\UserRole;
use App\Livewire\Reports\Index;
use App\Models\Channel;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsMarkupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lista_de_numeros_e_uma_dl_valida(): void
    {
        $html = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Index::class)->html();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $this->assertGreaterThan(0, $xpath->query('//dl/div')->length);
        // Dentro do `<div>` de um `<dl>` só entram `<dt>` e `<dd>` (axe: definition-list).
        $this->assertSame(0, $xpath->query('//dl/div/*[not(self::dt or self::dd)]')->length);
    }

    private function page(): \DOMXPath
    {
        $html = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Index::class)->html();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    private function panel(string $title): string
    {
        return "//*[@data-panel][.//h2[normalize-space()='{$title}']]";
    }

    public function test_relatorios_usam_stats_e_paineis(): void
    {
        $xpath = $this->page();

        $this->assertSame(4, $xpath->query('//dl//*[@data-stat]')->length);
        foreach (['Aguardando resposta além do SLA', 'Volume por setor', 'Desempenho por atendente'] as $title) {
            $this->assertSame(1, $xpath->query($this->panel($title))->length, "painel {$title}");
        }

        $this->assertSame(1, $xpath->query('//*[@data-flux-radio-group-segmented]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-page-header-actions]//*[@data-flux-radio-group-segmented]')->length);
        $this->assertSame(1, $xpath->query("//section[contains(concat(' ', normalize-space(@class), ' '), ' max-w-[120rem] ')]")->length);
    }

    public function test_aviso_do_verificador_usa_os_tokens_de_aviso(): void
    {
        // Sem batida no cache, o verificador conta como parado.
        $alert = $this->page()->query("//*[@role='alert'][contains(., 'O verificador de SLA')]")->item(0);

        $this->assertInstanceOf(\DOMElement::class, $alert);
        $this->assertContains('bg-warn-soft', explode(' ', $alert->getAttribute('class')));
        $this->assertFalse($alert->hasAttribute('data-flux-callout'));
    }

    public function test_paineis_com_borda_no_cabecalho_e_tabelas_nomeadas_na_largura_toda(): void
    {
        $agent = User::factory()->create(['name' => 'Ana Lima']);
        Ticket::factory()->for(Channel::factory()->connected()->create())->create([
            'assigned_user_id' => $agent->id, 'opened_at' => now(), 'status' => 'aguardando',
            'waiting_since' => now()->subHour(), 'sla_warn_at' => now()->subMinutes(30), 'sla_due_at' => now()->subMinutes(10),
        ]);
        $xpath = $this->page();

        $headers = $xpath->query('//*[@data-panel]/*[1]');
        $this->assertSame(3, $headers->length);
        foreach ($headers as $header) {
            $this->assertInstanceOf(\DOMElement::class, $header);
            $this->assertContains('border-b', explode(' ', $header->getAttribute('class')));
        }

        foreach (['Aguardando resposta além do SLA', 'Desempenho por atendente'] as $title) {
            $table = $xpath->query($this->panel($title).'//table')->item(0);
            $this->assertInstanceOf(\DOMElement::class, $table, "tabela {$title}");
            $this->assertSame($title, trim($xpath->query('./caption', $table)->item(0)?->textContent ?? ''));
            $this->assertContains('w-full', explode(' ', $table->getAttribute('class')));
        }
    }

    /** As colunas fixas cabem o conteúdo (data mono e selo) e deixam a do cliente legível. */
    public function test_colunas_fixas_da_espera_deixam_espaco_ao_cliente(): void
    {
        Ticket::factory()->for(Channel::factory()->connected()->create())->create([
            'opened_at' => now(), 'status' => 'aguardando',
            'waiting_since' => now()->subHour(), 'sla_warn_at' => now()->subMinutes(30), 'sla_due_at' => now()->subMinutes(10),
        ]);
        $xpath = $this->page();

        $widths = ['Setor' => 'w-28', 'Responsável' => 'w-32', 'Esperando desde' => 'w-32', 'Limite' => 'w-32', 'Situação' => 'w-40'];
        foreach ($widths as $header => $width) {
            $th = $xpath->query($this->panel('Aguardando resposta além do SLA')."//th[normalize-space()='{$header}']")->item(0);
            $this->assertInstanceOf(\DOMElement::class, $th, $header);
            $this->assertContains($width, explode(' ', $th->getAttribute('class')), $header);
        }
    }

    public function test_paineis_vazios_usam_o_estado_vazio_com_circulo(): void
    {
        $xpath = $this->page();

        foreach (['Aguardando resposta além do SLA' => 'Ninguém esperando além do SLA agora.', 'Volume por setor' => 'Nenhum ticket no período.', 'Desempenho por atendente' => 'Nenhum atendimento no período.'] as $panel => $title) {
            $empty = $this->panel($panel)."//*[@data-empty-state][.//p[normalize-space()='{$title}']]";
            $this->assertSame(1, $xpath->query($empty.'//*[@data-empty-state-icon]')->length, $panel);
        }
    }

    public function test_desempenho_mostra_avatar_do_atendente(): void
    {
        $agent = User::factory()->create(['name' => 'Ana Lima']);
        Ticket::factory()->for(Channel::factory()->connected()->create())->create(['assigned_user_id' => $agent->id, 'opened_at' => now()]);

        $row = $this->page()->query($this->panel('Desempenho por atendente')."//tr[.//*[normalize-space()='Ana Lima']]")->item(0);

        $this->assertInstanceOf(\DOMElement::class, $row);
        $this->assertSame(1, (new \DOMXPath($row->ownerDocument))->query('.//*[@data-flux-avatar]', $row)->length);
    }

    public function test_volume_por_setor_usa_o_grafico_de_barras(): void
    {
        $sector = Sector::factory()->create(['name' => 'Comercial']);
        Ticket::factory()->for(Channel::factory()->connected()->create())->create(['sector_id' => $sector->id, 'opened_at' => now()]);

        $screen = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Index::class);

        $screen->assertSeeHtml('data-testid="bar-row"')->assertSeeHtml('<title>Comercial: 1 (100%)</title>');
    }
}
