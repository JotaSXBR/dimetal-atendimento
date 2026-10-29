<?php

namespace Tests\Feature\Security;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SendTicketMessageJob;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_tem_teto_por_ip_e_responde_429_acima_dele(): void
    {
        config(['security.webhook_per_minute' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/webhooks/waha', [])->assertUnauthorized();
        }

        $this->postJson('/webhooks/waha', [])->assertStatus(429);
    }

    public function test_o_teto_do_webhook_e_por_ip_e_nao_global(): void
    {
        config(['security.webhook_per_minute' => 1]);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->postJson('/webhooks/waha', [])->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->postJson('/webhooks/waha', [])->assertUnauthorized();
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
