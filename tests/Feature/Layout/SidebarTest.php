<?php

namespace Tests\Feature\Layout;

use App\Enums\ChannelStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use App\Settings\CompanySettings;
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

    public function test_sidebar_e_recolhivel_no_desktop_para_todos_os_papeis(): void
    {
        foreach ([UserRole::ATENDENTE, UserRole::SUPERVISOR, UserRole::ADMIN] as $role) {
            $html = $this->page($role);

            $this->assertStringContainsString('collapsible="true"', $html, $role->value);
            $this->assertStringNotContainsString('collapsible="mobile"', $html, $role->value);
        }
    }

    public function test_botao_de_colapso_aparece_no_desktop(): void
    {
        $this->assertSame(0, $this->xpath($this->page(UserRole::ADMIN))->query('//*[@data-flux-sidebar-collapse and contains(@class, "lg:hidden")]')->length);
    }

    public function test_ponto_da_fila_fechada_e_vermelho_com_sla_estourado_mesmo_sem_aguardando(): void
    {
        $channel = Channel::factory()->connected()->create();
        Ticket::factory()->for($channel)->create([
            'status' => TicketStatus::EM_ATENDIMENTO, 'waiting_since' => now()->subHour(), 'sla_due_at' => now()->subMinutes(5),
        ]);

        $dot = $this->xpath($this->page(UserRole::ADMIN))->query('//*[@data-test="queue-dot"]')->item(0);

        $this->assertStringContainsString('bg-danger', (string) $dot?->getAttribute('class'));
        $this->assertSame('true', $dot?->getAttribute('aria-hidden'));
        $this->assertSame('', trim((string) $dot?->textContent));
    }

    public function test_ponto_da_fila_fechada_e_amarelo_com_aguardando(): void
    {
        Ticket::factory()->count(3)->for(Channel::factory()->connected()->create())->create(['status' => TicketStatus::AGUARDANDO]);

        $dot = $this->xpath($this->page(UserRole::ADMIN))->query('//*[@data-test="queue-dot"]')->item(0);

        $this->assertStringContainsString('bg-accent', (string) $dot?->getAttribute('class'));
        $this->assertSame('true', $dot?->getAttribute('aria-hidden'));
        $this->assertSame('', trim((string) $dot?->textContent));
    }

    /** O anel do ponto imita o fundo da sidebar: as duas cores saem da mesma variável, para não se separarem. */
    public function test_anel_do_ponto_e_fundo_da_sidebar_usam_a_mesma_variavel(): void
    {
        Ticket::factory()->for(Channel::factory()->connected()->create())->create(['status' => TicketStatus::AGUARDANDO]);

        $xpath = $this->xpath($this->page(UserRole::ADMIN));

        $this->assertStringContainsString('bg-(--sidebar-bg)', (string) $xpath->query('//ui-sidebar')->item(0)?->getAttribute('class'));
        $this->assertStringContainsString('ring-(--sidebar-bg)', (string) $xpath->query('//*[@data-test="queue-dot"]')->item(0)?->getAttribute('class'));
    }

    /** Fechada, o botão de expandir fica sempre à vista (o padrão do Flux só o mostra no hover, e sem mouse não haveria como reabrir). */
    public function test_botao_de_expandir_fica_sempre_visivel_com_a_sidebar_fechada(): void
    {
        $xpath = $this->xpath($this->page(UserRole::ADMIN));
        $class = (string) $xpath->query('//*[@data-flux-sidebar-collapse]')->item(0)?->getAttribute('class');

        $this->assertStringContainsString('in-data-flux-sidebar-collapsed-desktop:opacity-100!', $class);
        $this->assertStringContainsString('in-data-flux-sidebar-collapsed-desktop:static!', $class);
    }

    /** O hover não pode tirar a marca do lugar nem do cabeçalho (o Flux a esconde para mostrar o botão). */
    public function test_marca_continua_no_lugar_com_o_mouse_na_sidebar_fechada(): void
    {
        $xpath = $this->xpath($this->page(UserRole::ADMIN));
        $class = (string) $xpath->query('//*[@data-flux-sidebar-brand]')->item(0)?->getAttribute('class');

        $this->assertStringContainsString('in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:opacity-100!', $class);
        $this->assertStringContainsString('in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:static!', $class);
    }

    public function test_botao_de_colapso_tem_dica_em_portugues(): void
    {
        $this->assertStringContainsString('Recolher ou expandir menu', $this->page(UserRole::ADMIN));
    }

    /** Fechada, o Flux esconde o texto do item (só sobra a descrição do tooltip): o nome tem de vir do `aria-label`. */
    public function test_links_do_menu_e_o_logo_tem_nome_acessivel_com_a_sidebar_fechada(): void
    {
        $xpath = $this->xpath($this->page(UserRole::ADMIN));

        $names = [];
        foreach ($xpath->query('//a[@data-flux-sidebar-item]') as $link) {
            $names[] = $link->getAttribute('aria-label');
        }

        $this->assertSame(['Fila', 'Contatos', 'Histórico', 'Relatórios', 'Auditoria', 'Configuração'], $names);
        // Com a inicial do monograma (texto visível) na frente do nome: WCAG 2.5.3, o nome acessível contém o rótulo visível.
        $this->assertSame(mb_strtoupper(mb_substr((string) config('app.name'), 0, 1)).' '.config('app.name'), $xpath->query('//a[@data-flux-sidebar-brand]')->item(0)?->getAttribute('aria-label'));
    }

    /** O `aria-label` do link substitui o conteúdo dele: as contagens do selo precisam entrar no nome. */
    public function test_nome_da_fila_leva_as_contagens_de_aguardando_e_sla(): void
    {
        $channel = Channel::factory()->connected()->create();
        Ticket::factory()->count(2)->for($channel)->create(['status' => TicketStatus::AGUARDANDO]);
        Ticket::factory()->for($channel)->create([
            'status' => TicketStatus::AGUARDANDO, 'waiting_since' => now()->subHour(), 'sla_due_at' => now()->subMinutes(5),
        ]);

        $link = $this->xpath($this->page(UserRole::ADMIN))->query('//a[@data-flux-sidebar-item][1]')->item(0);

        $this->assertSame('Fila, 3 aguardando, 1 com SLA estourado', $link?->getAttribute('aria-label'));
    }

    /** A ordem do Tab é a do DOM: marca, botão de colapso, sino, igual à ordem visual aberta e fechada (sem `order-*`). */
    public function test_ordem_do_cabecalho_e_a_mesma_aberta_e_fechada(): void
    {
        $xpath = $this->xpath($this->page(UserRole::ADMIN));
        $header = $xpath->query('//ui-sidebar//*[@data-flux-sidebar-brand]/ancestor::*[@data-flux-sidebar-header][1]')->item(0)
            ?? $xpath->query('//ui-sidebar//*[@data-flux-sidebar-brand]/..')->item(0);

        $kinds = [];
        foreach ($xpath->query('.//*[@data-flux-sidebar-brand or @data-flux-sidebar-collapse or (self::button and @aria-haspopup)]', $header) as $node) {
            $kinds[] = $node->hasAttribute('data-flux-sidebar-brand') ? 'marca' : ($node->hasAttribute('data-flux-sidebar-collapse') ? 'botao' : 'sino');
        }

        $this->assertSame(['marca', 'botao', 'sino'], $kinds);
        $this->assertSame(0, $xpath->query('.//*[contains(@class, "order-1") or contains(@class, "order-2") or contains(@class, "order-3")]', $header)->length);
    }

    public function test_selo_da_fila_nao_repete_aria_label(): void
    {
        Ticket::factory()->for(Channel::factory()->connected()->create())->create(['status' => TicketStatus::AGUARDANDO]);

        $badge = $this->xpath($this->page(UserRole::ADMIN))->query('//*[@data-test="queue-badge"]')->item(0);

        $this->assertNotNull($badge);
        $this->assertFalse($badge->hasAttribute('aria-label'));
    }

    public function test_rotulo_do_logo_cai_para_laravel_sem_nome_do_app(): void
    {
        config(['app.name' => null]);

        $brand = $this->xpath($this->page(UserRole::ADMIN))->query('//a[@data-flux-sidebar-brand]')->item(0);

        $this->assertSame('L Laravel', $brand?->getAttribute('aria-label'));
    }

    public function test_sem_pendencia_nao_ha_ponto_da_fila(): void
    {
        $this->assertSame(0, $this->xpath($this->page(UserRole::ADMIN))->query('//*[@data-test="queue-dot"]')->length);
    }

    public function test_ponto_da_fila_so_aparece_com_a_sidebar_fechada(): void
    {
        Ticket::factory()->for(Channel::factory()->connected()->create())->create(['status' => TicketStatus::AGUARDANDO]);

        $class = (string) $this->xpath($this->page(UserRole::ADMIN))->query('//*[@data-test="queue-dot"]')->item(0)?->getAttribute('class');

        $this->assertStringContainsString('hidden', $class);
        $this->assertStringContainsString('in-data-flux-sidebar-collapsed-desktop:block', $class);
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

    public function test_selos_de_texto_somem_com_a_sidebar_fechada_porque_o_ponto_os_substitui(): void
    {
        Ticket::factory()->for(Channel::factory()->connected()->create())->create([
            'status' => TicketStatus::AGUARDANDO, 'waiting_since' => now()->subHour(), 'sla_due_at' => now()->subMinutes(5),
        ]);

        $sla = $this->xpath($this->page(UserRole::ADMIN))->query('//*[@data-test="sla-badge"]')->item(0);

        $this->assertStringContainsString('in-data-flux-sidebar-collapsed-desktop:hidden', (string) $sla?->getAttribute('class'));
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

    public function test_aviso_de_whatsapp_tem_texto_e_icone_com_rotulo_para_a_sidebar_fechada(): void
    {
        Channel::factory()->create(['status' => ChannelStatus::STOPPED]);

        $xpath = $this->xpath($this->page(UserRole::ATENDENTE));
        $notice = $xpath->query('//*[@data-test="whatsapp-down"]')->item(0);

        $this->assertNotNull($notice);
        $this->assertSame('WhatsApp desconectado', $xpath->query('.//*[@aria-label="WhatsApp desconectado"]', $notice)->item(0)?->getAttribute('aria-label'));
        // `aria-label` num elemento sem papel costuma ser ignorado por leitores de tela: o ícone precisa de `role="img"`.
        $this->assertSame('img', $xpath->query('.//*[@aria-label="WhatsApp desconectado"]', $notice)->item(0)?->getAttribute('role'));
        $text = $xpath->query('.//p', $notice)->item(0);
        $this->assertStringContainsString('As mensagens ficam aguardando', (string) $text?->textContent);
        $this->assertStringContainsString('in-data-flux-sidebar-collapsed-desktop:hidden', (string) $text?->getAttribute('class'));
    }

    public function test_o_nome_acessivel_da_marca_contem_o_texto_visivel(): void
    {
        CompanySettings::save(['name' => 'Dimetal Distribuidora']);

        $brand = $this->xpath($this->page(UserRole::ATENDENTE))->query('//a[@data-flux-sidebar-brand]')->item(0);

        $this->assertNotNull($brand);
        // O texto visível é o monograma (a inicial) mais o nome; o nome acessível precisa começar por ele (WCAG 2.5.3).
        $this->assertSame('D Dimetal Distribuidora', $brand->getAttribute('aria-label'));
    }

    public function test_o_rotulo_da_marca_ignora_espacos_nas_pontas_do_nome(): void
    {
        CompanySettings::save(['name' => '  Dimetal  ']);

        $brand = $this->xpath($this->page(UserRole::ATENDENTE))->query('//a[@data-flux-sidebar-brand]')->item(0);

        $this->assertSame('D Dimetal', $brand?->getAttribute('aria-label'));
    }

    public function test_sem_aviso_com_canal_conectado(): void
    {
        Channel::factory()->connected()->create();

        $this->assertStringNotContainsString('WhatsApp desconectado', $this->page(UserRole::ATENDENTE));
    }
}
