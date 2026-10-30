<?php

namespace Tests\Feature\Alertas;

use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ChannelDown;
use App\Notifications\ChannelUp;
use App\Notifications\SlaBreached;
use App\Notifications\SlaWarning;
use App\Notifications\TicketAssigned;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_so_tem_as_chaves_permitidas_e_nenhum_texto_de_cliente(): void
    {
        $ticket = Ticket::factory()->create();
        $channel = $ticket->channel;
        $channel->update(['name' => 'Loja']);
        $user = User::factory()->create();

        foreach ([new TicketAssigned($ticket), new SlaWarning($ticket), new SlaBreached($ticket), new ChannelDown($channel), new ChannelUp($channel)] as $n) {
            $payload = $n->toArray($user);

            $this->assertEmpty(array_diff(array_keys($payload), ['kind', 'level', 'ticket_id', 'channel_id', 'channel_name', 'state']), get_class($n));
            $this->assertContains($n->via($user)[0], ['database']);
            $this->assertContains('broadcast', $n->via($user));
        }

        $this->assertSame(['kind' => 'sla_breached', 'level' => 'grave', 'ticket_id' => $ticket->id], (new SlaBreached($ticket))->toArray($user));
        $this->assertSame('down', (new ChannelDown($channel))->toArray($user)['state']);
        $this->assertSame('critico', (new ChannelDown($channel))->toArray($user)['level']);
    }

    public function test_o_broadcast_vai_para_o_canal_do_usuario(): void
    {
        $user = User::factory()->create();

        $this->assertSame('users.'.$user->id, $user->receivesBroadcastNotificationsOn());
    }

    public function test_expurgo_remove_so_com_mais_de_7_dias(): void
    {
        $user = User::factory()->create();
        $canal = Channel::factory()->create();
        [$novo, $seis, $oito] = Ticket::factory()->count(3)->create(['channel_id' => $canal->id])->all();
        foreach ([$novo, $seis, $oito] as $ticket) {
            $user->notify(new SlaBreached($ticket));
        }
        DatabaseNotification::query()->where('data', 'like', '%"ticket_id":'.$seis->id.'}%')->update(['created_at' => Carbon::now()->subDays(6)]);
        DatabaseNotification::query()->where('data', 'like', '%"ticket_id":'.$oito->id.'}%')->update(['created_at' => Carbon::now()->subDays(8)]);

        $this->artisan('app:purge-notifications')->assertSuccessful();

        $this->assertSame(2, DatabaseNotification::count());
        $this->assertSame(0, DatabaseNotification::query()->where('data', 'like', '%"ticket_id":'.$oito->id.'}%')->count());
    }

    public function test_agenda_tem_o_expurgo_diario(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'app:purge-notifications'));

        $this->assertNotNull($event);
        $this->assertSame('45 3 * * *', $event->expression);
        $this->assertLessThanOrEqual(10, $event->expiresAt);
    }
}
