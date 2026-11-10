<?php

namespace Tests\Feature\Components;

use App\Enums\CloseOutcome;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Contacts\ContactCard;
use App\Livewire\History\Index as History;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Reports\Index as Reports;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** O selo único `x-status-badge` nos pontos onde o estado aparece (Inbox, Histórico, cartão do contato, Relatórios). */
class StatusBadgeScreensTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    private function ticket(TicketStatus $status, array $override = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'channel_id' => (Channel::query()->first() ?? Channel::factory()->create())->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => $status,
            'closed_at' => $status === TicketStatus::ENCERRADO ? now() : null,
        ], $override));
    }

    /** @return array<string, array{0: TicketStatus}> */
    public static function todosOsStatus(): array
    {
        return array_combine(array_map(fn (TicketStatus $s) => $s->value, TicketStatus::cases()), array_map(fn (TicketStatus $s) => [$s], TicketStatus::cases()));
    }

    #[DataProvider('todosOsStatus')]
    public function test_cabecalho_da_conversa_usa_o_selo_com_o_tom_do_status(TicketStatus $status): void
    {
        $ticket = $this->ticket($status);

        $xpath = $this->xpath(Livewire::actingAs($this->admin())->test(Inbox::class)->call('select', $ticket->id)->html());

        $badge = $xpath->query('//*[@data-testid="header-title"]//*[@data-status-tone]')->item(0);
        $this->assertNotNull($badge);
        $this->assertSame($status->tone(), $badge->getAttribute('data-status-tone'));
        $this->assertStringContainsString($status->label(), $badge->textContent);
        $this->assertSame(0, $xpath->query('//*[@data-testid="header-title"]//*[@data-flux-badge]')->length, 'sem flux:badge com cor crua no cabeçalho');
    }

    public function test_historico_mostra_o_desfecho_em_selo_na_lista_e_no_cabecalho(): void
    {
        $ticket = $this->ticket(TicketStatus::ENCERRADO, ['close_outcome' => CloseOutcome::RESOLVIDO]);

        $html = Livewire::actingAs($this->admin())->test(History::class)->call('select', $ticket->id)->html();
        $xpath = $this->xpath($html);

        $this->assertGreaterThanOrEqual(2, $xpath->query('//*[@data-status-tone="ok"][contains(., "Resolvido")]')->length, 'lista e barra de encerramento');
    }

    public function test_cartao_do_contato_mostra_o_status_dos_anteriores_em_selo(): void
    {
        $current = $this->ticket(TicketStatus::EM_ATENDIMENTO);
        // O banco só permite um ticket aberto por contato e canal: o anterior é um encerrado com desfecho.
        $this->ticket(TicketStatus::ENCERRADO, ['contact_id' => $current->contact_id, 'close_outcome' => CloseOutcome::RESOLVIDO]);

        $html = Livewire::actingAs($this->admin())->test(ContactCard::class, ['contactId' => $current->contact_id, 'compact' => true, 'currentTicketId' => $current->id])->html();
        $xpath = $this->xpath($html);

        $this->assertSame(1, $xpath->query('//*[@data-status-tone="neutral"][contains(., "Encerrado")]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-status-tone="ok"][contains(., "Resolvido")]')->length);
    }

    public function test_relatorio_mostra_a_situacao_do_sla_em_selo(): void
    {
        $this->ticket(TicketStatus::EM_ATENDIMENTO, ['waiting_since' => now()->subHours(2), 'sla_warn_at' => now()->subMinutes(90), 'sla_due_at' => now()->subMinute()]);
        $this->ticket(TicketStatus::EM_ATENDIMENTO, ['waiting_since' => now()->subHours(2), 'sla_warn_at' => now()->subMinutes(5), 'sla_due_at' => now()->addMinutes(20)]);

        $xpath = $this->xpath(Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->test(Reports::class)->html());

        $this->assertSame(1, $xpath->query('//td//*[@data-status-tone="danger"][contains(., "SLA estourado")]')->length);
        $this->assertSame(1, $xpath->query('//td//*[@data-status-tone="warn"][contains(., "Perto de estourar")]')->length);
    }
}
