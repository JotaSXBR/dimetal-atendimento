<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Cabeçalho da conversa responsivo à largura da coluna (container queries), não da janela (spec 4a). */
class InboxHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(TicketStatus $status = TicketStatus::AGUARDANDO, array $override = [], ?User $as = null): \DOMXPath
    {
        $ticket = Ticket::factory()->create(array_replace([
            'channel_id' => (Channel::query()->first() ?? Channel::factory()->create())->id,
            'contact_id' => Contact::factory()->create(['name' => 'João Pereira'])->id,
            'status' => $status,
            'closed_at' => $status === TicketStatus::ENCERRADO ? now() : null,
            'waiting_since' => now()->subHour(),
            'sla_warn_at' => now()->subMinutes(30),
            'sla_due_at' => now()->subMinute(),
        ], $override));

        $html = Livewire::actingAs($as ?? User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class)->call('select', $ticket->id)->html();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    public function test_coluna_da_conversa_e_container(): void
    {
        $column = $this->xpath()->query('//*[@data-testid="conversation-column"]')->item(0);

        $this->assertNotNull($column);
        $this->assertStringContainsString('@container', $column->getAttribute('class'));
    }

    /** @return list<string> os itens do menu "Mais ações" do cabeçalho */
    private function menuItems(\DOMXPath $xpath): array
    {
        $items = [];

        foreach ($xpath->query('//header//*[@data-testid="header-more-menu"]//*[@data-flux-menu-item]') as $item) {
            $items[] = trim((string) preg_replace('/\s+/', ' ', $item->textContent));
        }

        return $items;
    }

    public function test_transferir_e_reatribuir_ficam_no_menu_mais_acoes_e_nao_avulsos(): void
    {
        $xpath = $this->xpath(TicketStatus::EM_ATENDIMENTO, ['assigned_user_id' => User::factory()->create()->id]);

        $this->assertNotNull($xpath->query('//header//button[@aria-label="Mais ações"]')->item(0));
        $this->assertSame(['Transferir', 'Reatribuir'], $this->menuItems($xpath));
        $this->assertSame(0, $xpath->query('//header//button[@aria-label="Transferir" or @aria-label="Reatribuir"]')->length);
    }

    /** O responsável com a opção "pode transferir" ligada transfere, mas só supervisor e admin reatribuem. */
    public function test_quem_so_pode_transferir_ve_so_transferir_no_menu(): void
    {
        $attendant = User::factory()->create(['role' => UserRole::ATENDENTE, 'can_transfer' => true]);

        $xpath = $this->xpath(TicketStatus::EM_ATENDIMENTO, ['assigned_user_id' => $attendant->id], $attendant);

        $this->assertSame(['Transferir'], $this->menuItems($xpath));
    }

    public function test_sem_permissao_de_transferir_nem_reatribuir_nao_ha_menu_mas_o_encerrar_fica(): void
    {
        $attendant = User::factory()->create(['role' => UserRole::ATENDENTE, 'can_transfer' => false]);

        $xpath = $this->xpath(TicketStatus::EM_ATENDIMENTO, ['assigned_user_id' => $attendant->id], $attendant);

        $this->assertSame(0, $xpath->query('//header//button[@aria-label="Mais ações"]')->length);
        $this->assertNotNull($xpath->query('//header//button[normalize-space(.)="Encerrar"]')->item(0));
    }

    public function test_assumir_e_encerrar_mantem_o_texto(): void
    {
        $xpath = $this->xpath();

        foreach (['Assumir', 'Encerrar'] as $action) {
            $button = $xpath->query('//header//button[normalize-space(.)="'.$action.'"]')->item(0);

            $this->assertNotNull($button, $action);
            $this->assertStringNotContainsString('hidden', $button->getAttribute('class'), $action);
        }
    }

    /** Com o Transferir avulso, o "…" só sobra em coluna larga se tiver Reatribuir. */
    public function test_transferir_e_botao_na_coluna_larga_e_item_do_menu_na_estreita(): void
    {
        $xpath = $this->xpath(TicketStatus::EM_ATENDIMENTO, ['assigned_user_id' => User::factory()->create()->id]);

        $button = $xpath->query('//header//button[@data-testid="header-transfer"]')->item(0);
        $this->assertNotNull($button);
        $this->assertSame("openPanel('transfer')", $button->getAttribute('wire:click'));
        $this->assertSame('Transferir', trim($button->textContent));
        // `hidden` puro perde para o `inline-flex` de base do flux:button (vem depois no CSS); a variante de container ganha.
        $this->assertStringContainsString('@max-3xl:hidden', $button->getAttribute('class'));
        $this->assertDoesNotMatchRegularExpression('/(^|\s)hidden(\s|$)/', $button->getAttribute('class'));

        $item = $xpath->query('//header//*[@data-testid="header-more-menu"]//*[@data-flux-menu-item][normalize-space(.)="Transferir"]')->item(0);
        $this->assertNotNull($item);
        $this->assertStringContainsString('@3xl:hidden', $item->getAttribute('class'));

        $reassign = $xpath->query('//header//*[@data-testid="header-more-menu"]//*[@data-flux-menu-item][normalize-space(.)="Reatribuir"]')->item(0);
        $this->assertNotNull($reassign);
        $this->assertStringNotContainsString('@3xl:hidden', $reassign->getAttribute('class'));
        // Com Reatribuir, o gatilho do "…" fica em qualquer largura.
        $this->assertSame(0, $xpath->query('//header//button[@aria-label="Mais ações"]/ancestor-or-self::*[contains(@class,"@3xl:hidden")]')->length);
    }

    public function test_menu_mais_some_na_coluna_larga_quando_so_tem_transferir(): void
    {
        $attendant = User::factory()->create(['role' => UserRole::ATENDENTE, 'can_transfer' => true]);

        $xpath = $this->xpath(TicketStatus::EM_ATENDIMENTO, ['assigned_user_id' => $attendant->id], $attendant);

        $this->assertNotNull($xpath->query('//header//button[@aria-label="Mais ações"]')->item(0));
        $this->assertSame(1, $xpath->query('//header//button[@aria-label="Mais ações"]/ancestor-or-self::*[contains(@class,"@3xl:hidden")]')->length);
    }

    public function test_ordem_das_acoes_do_cabecalho(): void
    {
        $xpath = $this->xpath(TicketStatus::EM_ATENDIMENTO, ['assigned_user_id' => User::factory()->create()->id]);

        $order = [];
        foreach ($xpath->query('//header//button[@aria-controls="ticket-details"]/../*') as $node) {
            $label = trim((string) preg_replace('/\s+/', ' ', $node->getAttribute('aria-label') ?: $node->textContent));
            $order[] = $node->getAttribute('data-testid') ?: ($node->hasAttribute('data-flux-dropdown') ? 'menu' : $label);
        }

        $this->assertSame(['header-transfer', 'Tomar', 'menu', 'Encerrar'], array_values(array_slice($order, 2)));
    }

    public function test_sla_vai_para_a_linha_de_baixo(): void
    {
        $xpath = $this->xpath();

        $this->assertSame(0, $xpath->query('//*[@data-testid="header-title"]//*[@data-testid="header-sla"]')->length);
        $sla = $xpath->query('//*[@data-testid="header-line"]/preceding-sibling::*[@data-testid="header-sla"]')->item(0);

        $this->assertNotNull($sla);
        $this->assertStringContainsString('SLA estourado: venceu', trim(preg_replace('/\s+/', ' ', $sla->textContent)));
        $this->assertSame('status', $sla->getAttribute('role'));
    }

    /** SLA longo em coluna estreita: o protocolo desce de linha em vez de encolher até sumir, e o SLA não estoura a largura. */
    public function test_linha_do_protocolo_nao_some_com_sla_longo(): void
    {
        $xpath = $this->xpath();
        $line = $xpath->query('//*[@data-testid="header-line"]')->item(0);
        $sla = $xpath->query('//*[@data-testid="header-sla"]')->item(0);

        $this->assertMatchesRegularExpression('/(^|\s)flex-wrap(\s|$)/', $line->parentNode->getAttribute('class'));
        $this->assertStringContainsString('min-w-24', $line->getAttribute('class'));
        $this->assertStringContainsString('min-w-0', $sla->getAttribute('class'));
        $this->assertStringNotContainsString('shrink-0', $sla->getAttribute('class'));
    }

    // O SLA abre a linha de baixo (que já quebra): em coluna estreita o texto quebra em vez de ser cortado.
    public function test_sla_do_cabecalho_quebra_de_linha_em_vez_de_cortar(): void
    {
        $sla = $this->xpath()->query('//*[@data-testid="header-sla"]')->item(0);

        $this->assertNotNull($sla);
        $this->assertStringNotContainsString('whitespace-nowrap', $sla->getAttribute('class'));
        $this->assertStringNotContainsString('truncate', $sla->getAttribute('class'));
    }

    public function test_ticket_encerrado_nao_mostra_acoes_nem_sla(): void
    {
        $xpath = $this->xpath(TicketStatus::ENCERRADO);

        $this->assertSame(0, $xpath->query('//*[@data-testid="header-sla"]')->length);
        $this->assertSame(0, $xpath->query('//header//button[@aria-label="Transferir" or @aria-label="Reatribuir" or @aria-label="Mais ações"]')->length);
    }

    public function test_cabecalho_nao_quebra_linha_em_lg_para_as_acoes_nao_descerem_ao_abrir_detalhes(): void
    {
        $xpath = $this->xpath();
        $header = $xpath->query('//header[.//*[@data-testid="header-title"]]')->item(0);
        $text = $xpath->query('//*[@data-testid="header-title"]/..')->item(0);

        $actions = $xpath->query('.//button[@aria-controls="ticket-details"]/..', $header)->item(0);
        $line = $xpath->query('//*[@data-testid="header-line"]')->item(0);

        $this->assertStringContainsString('xl:flex-nowrap', $header->getAttribute('class'));
        $this->assertStringContainsString('xl:basis-0', $text->getAttribute('class'));
        $this->assertStringContainsString('xl:shrink-0', $actions->getAttribute('class'));
        $title = $xpath->query('//*[@data-testid="header-title"]')->item(0);
        $this->assertMatchesRegularExpression('/(^|\s)xl:flex-nowrap/', $title->getAttribute('class'));
        $this->assertStringContainsString('shrink-0', $xpath->query('//*[@data-testid="header-title"]/*[2]')->item(0)->getAttribute('class'));
        $this->assertStringContainsString('truncate', $line->getAttribute('class'));
        $this->assertStringNotContainsString('break-words', $line->getAttribute('class'));
    }

    /** Detalhes lado a lado só a partir de 2xl; abaixo disso abre por cima da conversa (sheet), como abaixo de lg. */
    public function test_detalhes_so_ficam_lado_a_lado_a_partir_de_2xl(): void
    {
        $xpath = $this->xpath();
        $grid = $xpath->query('//*[@x-data="ticketDetails"]')->item(0)->getAttribute('class');

        $this->assertStringContainsString('2xl:grid-cols-[340px_minmax(0,1fr)_var(--details-w,280px)]', $grid);
        $this->assertStringNotContainsString('lg:grid-cols-[340px_minmax(0,1fr)_var', $grid);
        $this->assertSame(1, $xpath->query('//header//button[@aria-controls="ticket-details"][contains(concat(" ",@class," ")," 2xl:hidden ")]')->length);
        $this->assertSame(1, $xpath->query('//header//button[@aria-controls="ticket-details"][contains(@class,"max-2xl:hidden")]')->length);
    }
}
