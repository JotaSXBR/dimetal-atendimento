<?php

namespace Tests\Feature\Security;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SendTicketMessageJob;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use App\Settings\WahaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** IP novo a cada chamada: com o cache em Redis (CI) as chaves do limitador passam de um teste para o outro. */
    private function ip(): string
    {
        return '10.'.random_int(1, 250).'.'.random_int(1, 250).'.'.random_int(1, 250);
    }

    /** @param  array<string, mixed>  $body */
    private function signed(string $ip, array $body): TestResponse
    {
        $json = (string) json_encode($body);

        return $this->call('POST', '/webhooks/waha', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => $ip,
            'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', $json, WahaSettings::webhookSecret()),
            'HTTP_X_WEBHOOK_HMAC_ALGORITHM' => 'sha512',
        ], $json);
    }

    private function unsigned(string $ip): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/webhooks/waha', ['event' => 'message']);
    }

    public function test_assinatura_valida_nunca_recebe_429(): void
    {
        Queue::fake();
        config(['security.webhook_invalid_per_minute' => 3]);
        $ip = $this->ip();

        for ($i = 1; $i <= 10; $i++) {
            $this->signed($ip, ['id' => 'evt_valido_'.$i, 'event' => 'session.status', 'session' => 'main'])->assertOk();
        }
    }

    public function test_presence_update_valido_tambem_passa_sem_limite(): void
    {
        Queue::fake();
        config(['security.webhook_invalid_per_minute' => 1]);
        $ip = $this->ip();

        for ($i = 1; $i <= 5; $i++) {
            $this->signed($ip, ['id' => 'evt_presenca_'.$i, 'event' => 'presence.update', 'session' => 'main', 'payload' => []])->assertOk();
        }
    }

    public function test_assinatura_invalida_leva_429_acima_do_teto_por_ip(): void
    {
        config(['security.webhook_invalid_per_minute' => 3]);
        $ip = $this->ip();

        for ($i = 0; $i < 3; $i++) {
            $this->unsigned($ip)->assertUnauthorized();
        }

        $this->unsigned($ip)->assertStatus(429);
    }

    public function test_invalidas_de_um_ip_nao_gastam_o_teto_de_outro(): void
    {
        config(['security.webhook_invalid_per_minute' => 1]);

        $this->unsigned($this->ip())->assertUnauthorized();
        $this->unsigned($this->ip())->assertUnauthorized();
    }

    public function test_valida_nao_gasta_o_teto_das_invalidas(): void
    {
        Queue::fake();
        config(['security.webhook_invalid_per_minute' => 2]);
        $ip = $this->ip();

        $this->unsigned($ip)->assertUnauthorized();
        $this->unsigned($ip)->assertUnauthorized();
        $this->signed($ip, ['id' => 'evt_depois_das_invalidas', 'event' => 'session.status', 'session' => 'main'])->assertOk();
        $this->unsigned($ip)->assertStatus(429);
    }

    public function test_envio_de_mensagem_tem_teto_por_usuario_e_o_excedente_nao_vai_para_a_fila(): void
    {
        Queue::fake();
        config(['security.send_message_per_minute' => 2]);

        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $me->id,
        ]);

        $component = Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id);

        foreach (['um', 'dois', 'tres'] as $text) {
            $component->set('draft', $text)->call('send');
        }

        $component->assertHasErrors('draft')->assertSee('rápido demais');
        Queue::assertPushed(SendTicketMessageJob::class, 2);
    }

    public function test_o_teto_de_envio_nao_atinge_outro_usuario(): void
    {
        Queue::fake();
        config(['security.send_message_per_minute' => 1]);

        $channel = Channel::factory()->create();
        $users = User::factory()->count(2)->create(['role' => UserRole::ATENDENTE]);

        foreach ($users as $user) {
            $ticket = Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $user->id]);
            Livewire::actingAs($user)->test(Inbox::class)->call('select', $ticket->id)->set('draft', 'oi')->call('send')->assertHasNoErrors();
        }

        Queue::assertPushed(SendTicketMessageJob::class, 2);
    }
}
