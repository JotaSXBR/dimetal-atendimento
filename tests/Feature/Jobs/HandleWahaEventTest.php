<?php

namespace Tests\Feature\Jobs;

use App\Enums\ChannelStatus;
use App\Jobs\HandleWahaEvent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\WahaEvent;
use App\Services\Waha\WahaRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HandleWahaEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_da_sessao_atualiza_o_canal(): void
    {
        $channel = Channel::factory()->create([
            'waha_session' => 'main',
            'status' => ChannelStatus::STARTING,
        ]);
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_01',
            'channel_id' => $channel->id,
            'event' => 'session.status',
            'session' => 'main',
            'payload' => ['payload' => ['status' => 'WORKING']],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($event->id))->handle();

        $this->assertSame(ChannelStatus::WORKING, $channel->fresh()->status);
        $this->assertNotNull($channel->fresh()->last_event_at);
    }

    /** @return array{Channel, WahaEvent} */
    private function inactiveChannelEvent(string $status): array
    {
        $channel = Channel::factory()->create(['waha_session' => 'main'.$status, 'status' => ChannelStatus::STOPPED, 'active' => false]);
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_'.$status,
            'channel_id' => $channel->id,
            'event' => 'session.status',
            'session' => 'main'.$status,
            'payload' => ['payload' => ['status' => $status]],
            'received_at' => now(),
        ]);

        return [$channel, $event];
    }

    public function test_canal_desativado_que_volta_no_waha_e_apagado_de_novo(): void
    {
        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Http::fake(['*' => Http::response([], 200)]);

        foreach (['SCAN_QR_CODE', 'WORKING'] as $status) {
            [$channel, $event] = $this->inactiveChannelEvent($status);

            (new HandleWahaEvent($event->id))->handle();

            $this->assertSame(ChannelStatus::STOPPED, $channel->fresh()->status);
        }

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/sessions/mainWORKING'));
    }

    public function test_canal_reativado_entre_a_leitura_e_a_decisao_nao_e_apagado(): void
    {
        Http::fake();
        [$channel, $event] = $this->inactiveChannelEvent('WORKING');

        // Simula o "Reativar" do dono logo depois que o job carregou o canal (ainda inativo na memória).
        Channel::retrieved(function (Channel $carregado) use ($channel) {
            Channel::query()->whereKey($channel->id)->update(['active' => true]);
        });

        (new HandleWahaEvent($event->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(ChannelStatus::WORKING, $channel->fresh()->status);
    }

    public function test_falha_do_waha_ao_apagar_a_sessao_relanca_para_a_fila_tentar_de_novo(): void
    {
        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Http::fake(['*' => Http::response([], 500)]);
        [, $event] = $this->inactiveChannelEvent('SCAN_QR_CODE');

        $this->expectException(WahaRequestException::class);

        (new HandleWahaEvent($event->id))->handle();
    }

    public function test_canal_desativado_que_recebe_stopped_nao_chama_o_waha(): void
    {
        Http::fake();
        [$channel, $event] = $this->inactiveChannelEvent('STOPPED');

        (new HandleWahaEvent($event->id))->handle();

        $this->assertSame(ChannelStatus::STOPPED, $channel->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_status_com_me_grava_o_numero_do_canal(): void
    {
        $channel = Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::STARTING, 'phone' => null]);
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_me',
            'channel_id' => $channel->id,
            'event' => 'session.status',
            'session' => 'main',
            'payload' => ['me' => ['id' => '5516999990001@c.us', 'pushName' => 'Fluxie'], 'payload' => ['status' => 'WORKING']],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($event->id))->handle();

        $this->assertSame('5516999990001', $channel->fresh()->phone);
    }

    public function test_evento_message_waiting_de_visualizacao_unica_entra_pelo_job(): void
    {
        $channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_wait',
            'channel_id' => $channel->id,
            'event' => 'message.waiting',
            'session' => 'main',
            'payload' => ['payload' => ['id' => 'false_5516997524950@c.us_W', 'from' => '5516997524950@c.us', 'fromMe' => false, 'timestamp' => 1_793_000_000, '_data' => ['Info' => ['PushName' => 'Maria'], 'IsViewOnce' => true]]],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($event->id))->handle();

        $this->assertSame('view_once', Message::query()->sole()->type);
    }

    public function test_evento_de_mensagem_vira_mensagem_no_inbox(): void
    {
        $channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_msg',
            'channel_id' => $channel->id,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['payload' => [
                'id' => 'false_5516997524950@c.us_ABC',
                'from' => '5516997524950@c.us',
                'fromMe' => false,
                'body' => 'Oi',
                'timestamp' => 1_793_000_000,
            ]],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($event->id))->handle();

        $this->assertSame('Oi', Message::query()->sole()->body);
        $this->assertSame(ChannelStatus::WORKING, $channel->fresh()->status);
    }

    public function test_evento_sem_canal_acha_o_canal_pela_sessao(): void
    {
        $channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_sem_canal',
            'channel_id' => null,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['payload' => ['id' => 'false_5516997524950@c.us_SC', 'from' => '5516997524950@c.us', 'fromMe' => false, 'body' => 'Oi', 'timestamp' => 1_793_000_000]],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($event->id))->handle();

        $this->assertSame($channel->id, $event->fresh()->channel_id);
        $this->assertSame(1, Message::query()->count());
    }

    public function test_evento_de_sessao_desconhecida_deixa_aviso_no_log(): void
    {
        Log::spy();
        Cache::forget('waha:sessao-sem-canal:nao-existe');
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_orfao',
            'channel_id' => null,
            'event' => 'message',
            'session' => 'nao-existe',
            'payload' => ['payload' => ['id' => 'false_1@c.us_X', 'from' => '1@c.us', 'fromMe' => false, 'body' => 'Oi', 'timestamp' => 1_793_000_000]],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($event->id))->handle();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => ($context['waha_event_id'] ?? null) === $event->id && ($context['session'] ?? null) === 'nao-existe');
        $this->assertSame(0, Message::query()->count());
    }

    public function test_sessao_desconhecida_avisa_no_log_uma_vez_por_hora_e_nao_a_cada_evento(): void
    {
        Log::spy();
        Cache::forget('waha:sessao-sem-canal:nao-existe');
        $ids = [];

        foreach (['evt_orfao_1', 'evt_orfao_2', 'evt_orfao_3'] as $waId) {
            $ids[] = WahaEvent::create([
                'waha_event_id' => $waId,
                'channel_id' => null,
                'event' => 'message.any',
                'session' => 'nao-existe',
                'payload' => [],
                'received_at' => now(),
            ])->id;
        }

        foreach ($ids as $id) {
            (new HandleWahaEvent($id))->handle();
        }

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_job_tenta_de_novo_antes_de_ir_para_failed_jobs(): void
    {
        $job = new HandleWahaEvent(1);

        $this->assertSame(6, $job->tries);
        $this->assertSame([2, 5, 10, 30], $job->backoff);
    }

    public function test_status_desconhecido_e_evento_sem_canal_sao_ignorados(): void
    {
        $channel = Channel::factory()->create([
            'waha_session' => 'main',
            'status' => ChannelStatus::WORKING,
        ]);
        $unknown = WahaEvent::create([
            'waha_event_id' => 'evt_02',
            'channel_id' => $channel->id,
            'event' => 'session.status',
            'session' => 'main',
            'payload' => ['payload' => ['status' => 'ALGO_NOVO']],
            'received_at' => now(),
        ]);
        $orphan = WahaEvent::create([
            'waha_event_id' => 'evt_03',
            'channel_id' => null,
            'event' => 'session.status',
            'session' => 'sumiu',
            'payload' => [],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($unknown->id))->handle();
        (new HandleWahaEvent($orphan->id))->handle();

        $this->assertSame(ChannelStatus::WORKING, $channel->fresh()->status);
    }
}
