<?php

namespace Tests\Feature\Alertas;

use App\Enums\ChannelStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Alerts\Banner;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_canal_caido_aparece_em_toda_tela_para_qualquer_papel(): void
    {
        Channel::factory()->create(['name' => 'Loja Centro', 'status' => ChannelStatus::FAILED]);

        foreach ([UserRole::ATENDENTE, UserRole::SUPERVISOR, UserRole::ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));

            foreach (['inbox', 'contatos', 'historico', 'profile.edit'] as $rota) {
                $this->get(route($rota))->assertOk()->assertSee('WhatsApp desconectado')->assertSee('Loja Centro')->assertSee('role="alert"', false);
            }
        }
    }

    public function test_some_quando_o_canal_volta_e_a_regiao_de_alerta_continua_na_pagina(): void
    {
        $canal = Channel::factory()->create(['status' => ChannelStatus::FAILED]);
        $this->actingAs(User::factory()->create());

        Livewire::test(Banner::class)->assertSee('WhatsApp desconectado');

        $canal->update(['status' => ChannelStatus::WORKING]);

        Livewire::test(Banner::class)->assertDontSee('WhatsApp desconectado')->assertSee('role="alert"', false);
    }

    public function test_vindo_do_evento_de_volta_mostra_a_confirmacao_e_ela_pode_ser_dispensada(): void
    {
        $canal = Channel::factory()->create(['name' => 'Loja Centro', 'status' => ChannelStatus::WORKING]);
        $this->actingAs(User::factory()->create());

        Livewire::test(Banner::class)
            ->call('onChannelStatus', ['channel_id' => $canal->id, 'status' => 'WORKING'])
            ->assertSee('WhatsApp voltou')
            ->assertSee('Loja Centro')
            ->call('dismissRecovered')
            ->assertDontSee('WhatsApp voltou');
    }

    public function test_sla_estourado_aparece_para_quem_ve_o_ticket_sem_texto_de_cliente(): void
    {
        $contato = Contact::factory()->create(['name' => 'Cliente Secreto']);
        $dono = User::factory()->create();
        $outro = User::factory()->create();
        Ticket::factory()->create([
            'contact_id' => $contato->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $dono->id,
            'waiting_since' => now()->subHour(),
            'sla_due_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($dono);
        Livewire::test(Banner::class)->assertSee('SLA estourado')->assertDontSee('Cliente Secreto');

        $this->actingAs($outro);
        Livewire::test(Banner::class)->assertDontSee('SLA estourado');
    }

    public function test_silenciar_grava_so_15_min_ou_1_h_e_avisa_o_navegador(): void
    {
        Channel::factory()->create(['status' => ChannelStatus::FAILED]);
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(Banner::class)->call('mute', 5);
        $this->assertFalse($user->fresh()->alertSettings()->criticalMuted());

        Livewire::test(Banner::class)->call('mute', 15)->assertDispatched('alert-settings-updated');
        $this->assertTrue($user->fresh()->alertSettings()->criticalMuted());
        $this->assertLessThanOrEqual(15, (int) now()->diffInMinutes($user->fresh()->alertSettings()->muteCriticalUntil, true) + 1);
    }
}
