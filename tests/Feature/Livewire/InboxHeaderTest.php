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

    public function test_sla_fica_na_primeira_linha_junto_do_status(): void
    {
        $xpath = $this->xpath();

        $sla = $xpath->query('//*[@data-testid="header-title"]//*[@data-testid="header-sla"]')->item(0);

        $this->assertNotNull($sla);
        $this->assertStringContainsString('SLA estourado: venceu', trim(preg_replace('/\s+/', ' ', $sla->textContent)));
        $this->assertSame('status', $sla->getAttribute('role'));
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

        $this->assertStringContainsString('lg:flex-nowrap', $header->getAttribute('class'));
        $this->assertStringContainsString('lg:basis-0', $text->getAttribute('class'));
    }
}
