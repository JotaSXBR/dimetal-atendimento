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

    private function xpath(TicketStatus $status = TicketStatus::AGUARDANDO, array $override = []): \DOMXPath
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

        $html = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class)->call('select', $ticket->id)->html();
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

    public function test_transferir_e_reatribuir_tem_nome_acessivel_e_rotulo_so_em_coluna_larga(): void
    {
        $xpath = $this->xpath(TicketStatus::EM_ATENDIMENTO, ['assigned_user_id' => User::factory()->create()->id]);

        foreach (['Transferir', 'Reatribuir'] as $action) {
            $button = $xpath->query('//header//button[@aria-label="'.$action.'"]')->item(0);

            $this->assertNotNull($button, $action);
            $label = $xpath->query('.//span[contains(@class, "@2xl:inline")]', $button)->item(0);
            $this->assertSame($action, trim((string) $label?->textContent), $action);
        }
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
        $this->assertSame(0, $xpath->query('//header//button[@aria-label="Transferir" or @aria-label="Reatribuir"]')->length);
    }
}
