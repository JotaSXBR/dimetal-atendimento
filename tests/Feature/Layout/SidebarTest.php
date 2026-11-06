<?php

namespace Tests\Feature\Layout;

use App\Enums\ChannelStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarTest extends TestCase
{
    use RefreshDatabase;

    private function page(UserRole $role, string $url = '/inbox'): string
    {
        return (string) $this->actingAs(User::factory()->create(['role' => $role]))->get($url)->assertOk()->getContent();
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    private function entry(string $html): ?\DOMElement
    {
        $node = $this->xpath($html)->query('//a[@data-test="settings-entry"]')?->item(0);

        return $node instanceof \DOMElement ? $node : null;
    }

    private function linksTo(string $html, string $route): int
    {
        return $this->xpath($html)->query('//a[@href="'.route($route).'"]')->length;
    }

    public function test_a_raiz_leva_ao_atendimento(): void
    {
        $this->get('/')->assertRedirect('/inbox');
    }

    public function test_sem_links_nem_titulos_do_starter_kit(): void
    {
        $html = $this->page(UserRole::ATENDENTE);

        $this->assertStringNotContainsString('Repository', $html);
        $this->assertStringNotContainsString('Documentation', $html);
        $this->assertStringNotContainsString('Platform', $html);
    }

    public function test_atendente_so_ve_o_trabalho_e_nenhuma_entrada_de_configuracao(): void
    {
        $html = $this->page(UserRole::ATENDENTE);

        foreach (['inbox', 'contatos', 'historico'] as $route) {
            $this->assertGreaterThan(0, $this->linksTo($html, $route), $route); // o logo também leva à Fila
        }
        $this->assertNull($this->entry($html));
        $this->assertSame(0, $this->linksTo($html, 'relatorios'));
    }

    public function test_supervisor_ve_relatorios_e_uma_entrada_que_abre_respostas_rapidas(): void
    {
        $html = $this->page(UserRole::SUPERVISOR);

        $this->assertSame(1, $this->linksTo($html, 'relatorios'));
        $this->assertSame(0, $this->linksTo($html, 'auditoria'));
        $this->assertSame(route('configuracoes.respostas'), $this->entry($html)?->getAttribute('href'));
        $this->assertSame(0, $this->linksTo($html, 'configuracoes.etiquetas'));
    }

    public function test_admin_ve_auditoria_e_uma_entrada_que_abre_setores_sem_os_itens_de_configuracao(): void
    {
        $html = $this->page(UserRole::ADMIN);

        $this->assertSame(1, $this->linksTo($html, 'auditoria'));
        $this->assertSame(route('configuracoes.setores'), $this->entry($html)?->getAttribute('href'));
        $this->assertSame(0, $this->linksTo($html, 'configuracoes.horario'));
        $this->assertStringNotContainsString('Acompanhamento', $html);
    }

    public function test_configuracao_fica_ativa_so_nas_paginas_de_configuracao(): void
    {
        $this->assertFalse($this->entry($this->page(UserRole::ADMIN))?->hasAttribute('data-current'));
        $this->assertTrue($this->entry($this->page(UserRole::ADMIN, '/configuracoes/setores'))?->hasAttribute('data-current'));
    }

    public function test_contadores_de_aguardando_e_sla_estourado(): void
    {
        $channel = Channel::factory()->connected()->create();
        Ticket::factory()->count(2)->for($channel)->create(['status' => TicketStatus::AGUARDANDO]);
        Ticket::factory()->for($channel)->create([
            'status' => TicketStatus::AGUARDANDO, 'waiting_since' => now()->subHour(), 'sla_due_at' => now()->subMinutes(5),
        ]);

        $xpath = $this->xpath($this->page(UserRole::ADMIN));

        $this->assertSame('3', trim($xpath->query('//*[@data-test="queue-badge"]')->item(0)?->textContent ?? ''));
        $this->assertSame('1 com SLA estourado', $xpath->query('//*[@data-test="sla-badge"]')->item(0)?->getAttribute('aria-label'));
    }

    public function test_sla_estourado_sem_ninguem_aguardando_ainda_mostra_o_selo_vermelho(): void
    {
        $channel = Channel::factory()->connected()->create();
        Ticket::factory()->for($channel)->create([
            'status' => TicketStatus::EM_ATENDIMENTO, 'waiting_since' => now()->subHour(), 'sla_due_at' => now()->subMinutes(5),
        ]);

        $xpath = $this->xpath($this->page(UserRole::ADMIN));

        $this->assertSame(1, $xpath->query('//*[@data-test="sla-badge"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-test="queue-badge"]')->length);
    }

    public function test_sem_pendencia_nao_ha_selo(): void
    {
        $xpath = $this->xpath($this->page(UserRole::ADMIN));

        $this->assertSame(0, $xpath->query('//*[@data-test="queue-badge"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-test="sla-badge"]')->length);
    }

    public function test_aviso_quando_nenhum_canal_esta_conectado(): void
    {
        Channel::factory()->create(['status' => ChannelStatus::STOPPED]);

        $this->assertStringContainsString('WhatsApp desconectado', $this->page(UserRole::ATENDENTE));
    }

    public function test_sem_aviso_com_canal_conectado(): void
    {
        Channel::factory()->connected()->create();

        $this->assertStringNotContainsString('WhatsApp desconectado', $this->page(UserRole::ATENDENTE));
    }
}
