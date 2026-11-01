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

    private function page(UserRole $role): string
    {
        return (string) $this->actingAs(User::factory()->create(['role' => $role]))->get(route('inbox'))->assertOk()->getContent();
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

    public function test_atendente_so_ve_o_grupo_de_atendimento(): void
    {
        $html = $this->page(UserRole::ATENDENTE);

        foreach (['Fila', 'Contatos', 'Histórico'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Acompanhamento', $html);
        $this->assertStringNotContainsString('Configuração', $html);
    }

    public function test_supervisor_ve_acompanhamento_e_admin_ve_os_grupos_de_configuracao(): void
    {
        $supervisor = $this->page(UserRole::SUPERVISOR);
        foreach (['Acompanhamento', 'Relatórios', 'Respostas rápidas', 'Usuários'] as $text) {
            $this->assertStringContainsString($text, $supervisor);
        }
        foreach (['Auditoria', 'Anexos e retenção', 'Conexões'] as $text) {
            $this->assertStringNotContainsString($text, $supervisor);
        }

        $admin = $this->page(UserRole::ADMIN);
        foreach (['Auditoria', 'Horário e SLA', 'Empresa e assinatura', 'Anexos e retenção', 'Conexões', 'Equipe'] as $text) {
            $this->assertStringContainsString($text, $admin);
        }
        foreach (['Gestão', 'Pessoas e empresa', 'Dados e integrações'] as $text) {
            $this->assertStringNotContainsString($text, $admin);
        }
    }

    public function test_contadores_de_aguardando_e_sla_estourado(): void
    {
        $channel = Channel::factory()->connected()->create();
        Ticket::factory()->count(2)->for($channel)->create(['status' => TicketStatus::AGUARDANDO]);
        Ticket::factory()->for($channel)->create([
            'status' => TicketStatus::AGUARDANDO, 'waiting_since' => now()->subHour(), 'sla_due_at' => now()->subMinutes(5),
        ]);

        $html = $this->page(UserRole::ADMIN);

        $this->assertStringContainsString('3 aguardando', $html);
        $this->assertStringContainsString('1 com SLA estourado', $html);
    }

    public function test_sem_pendencia_nao_ha_contador(): void
    {
        $html = $this->page(UserRole::ADMIN);

        $this->assertDoesNotMatchRegularExpression('/\d+ aguardando/', $html);
        $this->assertStringNotContainsString('com SLA estourado', $html);
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
