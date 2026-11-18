<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\History\Index as History;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Inbox\TicketTags;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketDetailsBlocksTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(TicketStatus $status): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => (Channel::query()->first() ?? Channel::factory()->create())->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => $status,
            'closed_at' => $status === TicketStatus::ENCERRADO ? now() : null,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    private function inboxHtml(): string
    {
        return Livewire::actingAs($this->admin())->test(Inbox::class)->call('select', $this->ticket(TicketStatus::AGUARDANDO)->id)->html();
    }

    /** A linha do tempo só existe no painel do Histórico. */
    private function historyHtml(): string
    {
        return Livewire::actingAs($this->admin())->test(History::class)->call('select', $this->ticket(TicketStatus::ENCERRADO)->id)->html();
    }

    /** O `<details>` (abertura) do bloco com esse data-testid. */
    private function tag(string $html, string $testid): string
    {
        $this->assertSame(1, preg_match('/<details[^>]*data-testid="'.$testid.'"[^>]*>/', $html, $m), "bloco {$testid} ausente");

        return $m[0];
    }

    public function test_etiquetas_vem_abertas_e_observacoes_anteriores_e_linha_do_tempo_fechadas(): void
    {
        $inbox = $this->inboxHtml();

        $this->assertMatchesRegularExpression('/\sopen[\s>]/', $this->tag($inbox, 'details-tags'));
        $this->assertDoesNotMatchRegularExpression('/\sopen[\s>]/', $this->tag($inbox, 'details-notes'));
        $this->assertDoesNotMatchRegularExpression('/\sopen[\s>]/', $this->tag($inbox, 'details-history'));
        $this->assertDoesNotMatchRegularExpression('/\sopen[\s>]/', $this->tag($this->historyHtml(), 'details-timeline'));
    }

    public function test_blocos_ignoram_o_morph_do_livewire(): void
    {
        $this->assertStringContainsString('wire:ignore.self', $this->tag($this->inboxHtml(), 'details-tags'));
        $this->assertStringContainsString('wire:ignore.self', $this->tag($this->historyHtml(), 'details-timeline'));
    }

    public function test_painel_do_inbox_tem_o_bloco_ticket(): void
    {
        $html = $this->inboxHtml();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $labels = [];
        foreach ((new \DOMXPath($dom))->query('//details[@data-testid="details-ticket"]//dl/div') as $row) {
            $this->assertNotSame('', trim($row->getElementsByTagName('dd')->item(0)->textContent));
            $labels[] = trim($row->getElementsByTagName('dt')->item(0)->textContent);
        }

        $this->assertSame(['Canal', 'Setor', 'Responsável', 'Aberto em'], $labels);
        $this->assertStringContainsString('data-testid="header-line"', $html);
    }

    public function test_aviso_de_sla_no_painel_so_com_ticket_esperando(): void
    {
        $sla = function (array $attributes): string {
            $ticket = $this->ticket(TicketStatus::AGUARDANDO);
            $ticket->forceFill($attributes)->save();

            return Livewire::actingAs($this->admin())->test(Inbox::class)->call('select', $ticket->id)->html();
        };
        $box = fn (string $html): ?string => preg_match('/<[^>]*data-testid="details-sla".*?<\/div>/s', $html, $m) ? $m[0] : null;

        $onTime = $box($sla(['waiting_since' => now()->subMinute(), 'sla_warn_at' => now()->addMinutes(30), 'sla_due_at' => now()->addHour()]));
        $this->assertNotNull($onTime);
        $this->assertStringContainsString('Responder até', $onTime);
        $this->assertStringContainsString('bg-muted', $onTime);
        $this->assertStringNotContainsString('text-warn', $onTime);
        $this->assertStringNotContainsString('role="status"', $onTime);

        $near = $box($sla(['waiting_since' => now()->subMinutes(40), 'sla_warn_at' => now()->subMinute(), 'sla_due_at' => now()->addHour()]));
        $this->assertNotNull($near);
        $this->assertStringContainsString('Responder até', $near);
        $this->assertStringContainsString('bg-warn-soft', $near);

        $breached = $box($sla(['waiting_since' => now()->subHours(2), 'sla_due_at' => now()->subHour()]));
        $this->assertNotNull($breached);
        $this->assertStringContainsString('SLA estourado', $breached);
        $this->assertStringContainsString('bg-danger/10', $breached);

        $this->assertNull($box($sla(['waiting_since' => now()->subMinute(), 'sla_due_at' => null])));
    }

    public function test_historico_mantem_ticket_encerrado_e_sem_aviso_de_sla(): void
    {
        $ticket = $this->ticket(TicketStatus::ENCERRADO);
        $ticket->forceFill(['waiting_since' => now()->subHours(2), 'sla_due_at' => now()->subHour()])->save();
        $html = Livewire::actingAs($this->admin())->test(History::class)->call('select', $ticket->id)->html();

        $this->tag($html, 'details-ticket');
        $this->assertStringContainsString('Encerrado em', $html);
        $this->assertStringContainsString('Motivo', $html);
        $this->assertStringNotContainsString('details-sla', $html);
    }

    public function test_contato_em_linhas_rotuladas(): void
    {
        $contact = Contact::factory()->create(['company' => 'Dimetal', 'job_title' => 'Compras', 'email' => 'ana@example.com']);

        $this->assertSame(['Nome' => $contact->label(), 'Empresa' => 'Dimetal', 'Cargo' => 'Compras', 'E-mail' => 'ana@example.com'], $this->contactRows($contact));
    }

    public function test_contato_so_com_cargo_nao_mostra_empresa(): void
    {
        $contact = Contact::factory()->create(['company' => null, 'job_title' => 'Gerente', 'email' => null]);

        $this->assertSame(['Nome' => $contact->label(), 'Cargo' => 'Gerente'], $this->contactRows($contact));
    }

    /** @return array<string, string> rótulo => valor das linhas do bloco Contato no painel da Inbox */
    private function contactRows(Contact $contact): array
    {
        $ticket = Ticket::factory()->create(['channel_id' => Channel::factory()->create()->id, 'contact_id' => $contact->id, 'status' => TicketStatus::AGUARDANDO]);

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.Livewire::actingAs($this->admin())->test(Inbox::class)->call('select', $ticket->id)->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $rows = [];
        foreach ((new \DOMXPath($dom))->query('//details[@data-testid="details-contact"]//dl/div') as $row) {
            $rows[trim($row->getElementsByTagName('dt')->item(0)->textContent)] = trim($row->getElementsByTagName('dd')->item(0)->textContent);
        }

        return $rows;
    }

    public function test_titulos_de_secao_em_caixa_alta(): void
    {
        $this->assertSame(1, preg_match('/<summary class="([^"]*)"/', $this->inboxHtml(), $m));
        $this->assertEmpty(array_diff(['uppercase', 'tracking-wider', 'font-bold'], explode(' ', $m[1])));
    }

    public function test_painel_do_historico_mantem_o_bloco_ticket_com_encerrado_em_e_motivo(): void
    {
        $html = $this->historyHtml();

        $this->tag($html, 'details-ticket');
        $this->assertStringContainsString('Encerrado em', $html);
        $this->assertStringContainsString('Responsável', $html);
        $this->assertStringContainsString('<dt class="text-xs text-ink-3">Telefone</dt>', $html);
    }

    public function test_um_unico_bloco_de_etiquetas_com_as_linhas_conversa_e_contato(): void
    {
        // As linhas só aparecem quando há etiqueta aplicada ou a aplicar.
        Tag::factory()->create();
        Tag::factory()->contato()->create();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->inboxHtml(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $this->assertSame(1, $xpath->query('//details[@data-testid="details-tags"]')->length);
        $this->assertSame(1, $xpath->query('//details[@data-testid="details-tags"]//*[@data-testid="tags-row-conversa"]')->length);
        $this->assertSame(1, $xpath->query('//details[@data-testid="details-tags"]//*[@data-testid="tags-row-contato"]')->length);
    }

    /** O telefone já está na linha de baixo do cabeçalho; no Histórico continua no painel. */
    public function test_telefone_sai_do_painel_do_inbox_e_fica_no_do_historico(): void
    {
        $contact = Contact::factory()->create(['wa_id' => '5516997524950@c.us']);
        $channel = Channel::factory()->create();
        $open = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => TicketStatus::AGUARDANDO]);
        $closed = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => TicketStatus::ENCERRADO, 'closed_at' => now()]);

        $panel = fn (string $html): string => (string) preg_replace('/^.*?<aside[^>]*id="ticket-details".*?>/s', '', $html);

        $inbox = Livewire::actingAs($this->admin())->test(Inbox::class)->call('select', $open->id)->html();
        $history = Livewire::actingAs($this->admin())->test(History::class)->call('select', $closed->id)->html();

        $this->assertStringNotContainsString($contact->phoneLabel(), $panel($inbox));
        $this->assertStringContainsString($contact->phoneLabel(), $panel($history));
    }

    public function test_painel_lista_todas_as_etiquetas_do_ticket(): void
    {
        $ticket = $this->ticket(TicketStatus::AGUARDANDO);
        $ticket->tags()->attach(Tag::factory()->count(3)->sequence(['name' => 'Gama'], ['name' => 'Alfa'], ['name' => 'Beta'])->create());

        Livewire::actingAs($this->admin())->test(Inbox::class)->call('select', $ticket->id)
            ->assertSee('Etiquetas')
            ->assertSeeInOrder(['Alfa', 'Beta', 'Gama']);
    }

    public function test_painel_do_historico_tambem_mostra_as_etiquetas(): void
    {
        $ticket = $this->ticket(TicketStatus::ENCERRADO);
        $ticket->tags()->attach(Tag::factory()->create(['name' => 'Alfa']));

        Livewire::actingAs($this->admin())->test(History::class)->call('select', $ticket->id)->assertSee('Etiquetas')->assertSee('Alfa');
    }

    public function test_etiquetas_do_painel_ordenam_sem_diferenciar_maiuscula_nem_acento(): void
    {
        $ticket = $this->ticket(TicketStatus::AGUARDANDO);
        $ticket->tags()->attach(Tag::factory()->count(3)->sequence(['name' => 'Zeta'], ['name' => 'alfa'], ['name' => 'Árvore'])->create());

        Livewire::actingAs($this->admin())->test(TicketTags::class, ['ticketId' => $ticket->id])->assertSeeInOrder(['alfa', 'Árvore', 'Zeta']);
    }

    public function test_blocos_do_painel_tem_espacamento_compacto(): void
    {
        $html = $this->inboxHtml();

        $this->assertMatchesRegularExpression('/<details[^>]*data-testid="details-tags"[^>]*\spy-0\.5[\s"]/', $html);
        $this->assertStringContainsString('min-h-9', $html);
        $this->assertStringNotContainsString('min-h-11', $html);
    }

    /** O painel é uma coluna flex para o rodapé de ações ir para o fim mesmo com pouco conteúdo (1080p, 2K). */
    public function test_painel_e_coluna_flex_e_o_rodape_de_acoes_vai_para_o_fim(): void
    {
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO);
        $html = Livewire::actingAs($this->admin())->test(Inbox::class)->call('select', $ticket->id)->html();

        $this->assertMatchesRegularExpression('/<aside[^>]*id="ticket-details"[^>]*x-bind:class="[^"]*hidden 2xl:flex 2xl:flex-col/', $html);
        $this->assertMatchesRegularExpression('/data-testid="contact-actions" class="[^"]*\bmt-auto\b/', $html);
    }

    /** O painel é coluna flex (stretch): o botão "Voltar à conversa" do celular não pode virar uma barra de largura total. */
    public function test_botao_voltar_do_celular_nao_estica_na_gaveta(): void
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->inboxHtml(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $button = (new \DOMXPath($dom))->query('//aside[@id="ticket-details"]//button[contains(normalize-space(.), "Voltar")]')->item(0);

        $this->assertNotNull($button);
        $this->assertStringContainsString('self-start', $button->getAttribute('class'));
    }
}
