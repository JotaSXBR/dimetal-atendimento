<?php

namespace Tests\Feature\Livewire;

use App\Enums\ChannelStatus;
use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\QuickReply;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Inbox\SendFailure;
use App\Support\ConnectionState;
use App\Support\SidebarState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class InactiveChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_canal_desativado_nao_conta_para_o_aviso_de_whatsapp_fora(): void
    {
        $state = app(SidebarState::class);

        Channel::factory()->create(['waha_session' => 'a', 'active' => false]);
        $this->assertFalse($state->whatsappDown(), 'só há canal desativado de propósito');

        Channel::factory()->create(['waha_session' => 'b', 'active' => true]);
        $this->assertTrue($state->whatsappDown(), 'um canal ativo e parado é queda de verdade');

        Channel::query()->where('waha_session', 'b')->update(['status' => ChannelStatus::WORKING]);
        $this->assertFalse($state->whatsappDown(), 'um ativo conectado basta');
    }

    public function test_canal_desativado_nao_alerta_nem_aparece_na_faixa(): void
    {
        Notification::fake();
        $channel = Channel::factory()->connected()->create(['active' => false]);
        $channel->status = ChannelStatus::STOPPED;

        app(AlertDispatcher::class)->channelChanged($channel, ChannelStatus::WORKING);

        Notification::assertNothingSent();
        $this->assertCount(0, ConnectionState::down());
    }

    /** @return array{0: User, 1: Ticket} */
    private function mineOnInactiveChannel(): array
    {
        $me = User::factory()->create(['role' => UserRole::ADMIN, 'sign_messages' => false]);
        $channel = Channel::factory()->connected()->create(['active' => false]);
        $ticket = Ticket::factory()->create([
            'channel_id' => $channel->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $me->id,
        ]);

        return [$me, $ticket];
    }

    public function test_responder_em_canal_desativado_e_recusado(): void
    {
        Queue::fake();
        [$me, $ticket] = $this->mineOnInactiveChannel();
        $reply = QuickReply::query()->create(['shortcut' => 'ok', 'body' => 'Combinado']);
        $failed = Message::factory()->for($ticket)->create([
            'direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL, 'author_user_id' => $me->id,
            'delivery_status' => DeliveryStatus::FAILED, 'failure_code' => SendFailure::EXPIRED, 'wa_message_id' => null,
        ]);
        $before = Message::query()->count();

        // Um 403 inutiliza o componente: cada chamada usa uma tela nova.
        $screen = fn () => Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id);

        $screen()->set('draft', 'oi')->call('send')->assertForbidden();
        $screen()->call('useQuickReply', $reply->id)->assertForbidden();
        $screen()->call('resend', $failed->id, true)->assertForbidden();
        $screen()->call('commitUpload', $ticket->id)->assertForbidden();

        $this->assertSame($before, Message::query()->count());
        Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id)
            ->assertSee('Canal desativado. Reative para responder.');
    }

    public function test_canal_ativo_continua_respondendo(): void
    {
        Queue::fake();
        [$me, $ticket] = $this->mineOnInactiveChannel();
        $ticket->channel->update(['active' => true]);

        Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id)
            ->set('draft', 'oi')->call('send')->assertOk()->assertDontSee('Canal desativado');

        $this->assertSame(1, Message::query()->count());
    }
}
