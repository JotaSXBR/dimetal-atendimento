<?php

namespace Tests\Feature\Alertas;

use App\Actions\Inbox\ReassignTicket;
use App\Actions\Inbox\TransferTicket;
use App\Enums\ChannelStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Events\ChannelStatusChanged;
use App\Events\MessageCreated;
use App\Events\TicketQueued;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ChannelDown;
use App\Notifications\ChannelUp;
use App\Notifications\SlaBreached;
use App\Notifications\SlaWarning;
use App\Notifications\TicketAssigned;
use App\Services\Inbox\SlaClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DisparosTest extends TestCase
{
    use RefreshDatabase;

    public function test_canal_que_cai_avisa_todos_os_ativos_e_a_faixa(): void
    {
        Notification::fake();
        Event::fake([ChannelStatusChanged::class]);
        Queue::fake();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $inativo = User::factory()->create(['active' => false]);
        $channel = Channel::factory()->create(['status' => ChannelStatus::WORKING]);

        $channel->update(['status' => ChannelStatus::FAILED]);

        Notification::assertSentTo([$a, $b], ChannelDown::class);
        Notification::assertNotSentTo($inativo, ChannelDown::class);
        Event::assertDispatched(ChannelStatusChanged::class, fn ($e) => $e->channelId === $channel->id && $e->status === 'FAILED');
    }

    public function test_cair_de_um_estado_ruim_para_outro_ruim_nao_repete_o_alerta(): void
    {
        Notification::fake();
        Queue::fake();
        User::factory()->create();
        $channel = Channel::factory()->create(['status' => ChannelStatus::STARTING]);

        $channel->update(['status' => ChannelStatus::SCAN_QR_CODE]);

        Notification::assertNothingSent();
    }

    public function test_canal_que_volta_avisa_que_voltou(): void
    {
        Notification::fake();
        Queue::fake();
        $a = User::factory()->create();
        $channel = Channel::factory()->create(['status' => ChannelStatus::FAILED]);

        $channel->update(['status' => ChannelStatus::WORKING]);

        Notification::assertSentTo($a, ChannelUp::class);
    }

    public function test_canal_desativado_nao_alerta(): void
    {
        Notification::fake();
        Queue::fake();
        User::factory()->create();
        $channel = Channel::factory()->create(['status' => ChannelStatus::WORKING, 'active' => false]);

        $channel->update(['status' => ChannelStatus::FAILED]);

        Notification::assertNothingSent();
    }

    public function test_sla_avisa_e_estoura_uma_unica_vez(): void
    {
        Notification::fake();
        $dono = User::factory()->create();
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $outro = User::factory()->create();
        Ticket::factory()->create([
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $dono->id,
            'waiting_since' => now()->subHour(),
            'sla_warn_at' => now()->subMinutes(50),
            'sla_due_at' => now()->subMinutes(40),
        ]);

        app(SlaClock::class)();
        app(SlaClock::class)();

        Notification::assertSentToTimes($dono, SlaWarning::class, 1);
        Notification::assertSentToTimes($dono, SlaBreached::class, 1);
        Notification::assertSentToTimes($supervisor, SlaBreached::class, 1);
        Notification::assertNotSentTo($outro, SlaBreached::class);
    }

    public function test_reatribuir_avisa_o_novo_responsavel_e_nao_o_autor(): void
    {
        Notification::fake();
        Event::fake([TicketUpdated::class]);
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $novo = User::factory()->create();
        $ticket = Ticket::factory()->create(['status' => TicketStatus::EM_ATENDIMENTO]);

        app(ReassignTicket::class)($ticket, $supervisor, $novo);
        app(ReassignTicket::class)($ticket->refresh(), $novo, $novo);

        Notification::assertSentToTimes($novo, TicketAssigned::class, 1);
        Notification::assertNotSentTo($supervisor, TicketAssigned::class);
    }

    public function test_transferir_para_o_setor_avisa_a_fila_do_setor_menos_o_autor(): void
    {
        Event::fake([TicketQueued::class]);
        $x = Sector::factory()->create();
        $y = Sector::factory()->create();
        $autor = User::factory()->create();
        $autor->sectors()->attach($y);
        $doX = User::factory()->create();
        $doX->sectors()->attach($x);
        $ticket = Ticket::factory()->create(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $autor->id]);

        app(TransferTicket::class)($ticket, $autor, $x);

        Event::assertDispatched(TicketQueued::class, fn ($e) => $e->ticketId === $ticket->id && $e->userIds === [$doX->id]);
    }

    public function test_payload_de_mensagem_leva_direcao_e_nenhum_texto(): void
    {
        $ticket = Ticket::factory()->create();
        $message = Message::factory()->for($ticket)->create(['body' => 'segredo do cliente']);

        $payload = (new MessageCreated($message->load('ticket')))->broadcastWith();

        $this->assertSame($message->direction->value, $payload['direction']);
        $this->assertStringNotContainsString('segredo', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_payload_do_ticket_na_fila_so_tem_ids(): void
    {
        $payload = (new TicketQueued(5, [1, 2]))->broadcastWith();

        $this->assertSame(['kind' => 'queue', 'ticket_id' => 5], $payload);
    }
}
