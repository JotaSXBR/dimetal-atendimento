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

    public function test_bloco_do_ticket_vem_aberto_e_linha_do_tempo_fechada(): void
    {
        $this->assertMatchesRegularExpression('/\sopen[\s>]/', $this->tag($this->inboxHtml(), 'details-ticket'));
        $this->assertDoesNotMatchRegularExpression('/\sopen[\s>]/', $this->tag($this->historyHtml(), 'details-timeline'));
    }

    public function test_blocos_ignoram_o_morph_do_livewire(): void
    {
        $this->assertStringContainsString('wire:ignore.self', $this->tag($this->inboxHtml(), 'details-ticket'));
        $this->assertStringContainsString('wire:ignore.self', $this->tag($this->historyHtml(), 'details-timeline'));
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

        $this->assertMatchesRegularExpression('/<details[^>]*data-testid="details-ticket"[^>]*\spy-0\.5[\s"]/', $html);
        $this->assertStringContainsString('min-h-9', $html);
        $this->assertStringNotContainsString('min-h-11', $html);
    }

    /** O painel é uma coluna flex para o rodapé de ações ir para o fim mesmo com pouco conteúdo (1080p, 2K). */
    public function test_painel_e_coluna_flex_e_o_rodape_de_acoes_vai_para_o_fim(): void
    {
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO);
        $html = Livewire::actingAs($this->admin())->test(Inbox::class)->call('select', $ticket->id)->html();

        $this->assertMatchesRegularExpression('/<aside[^>]*id="ticket-details"[^>]*x-bind:class="[^"]*lg:flex lg:flex-col/', $html);
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
