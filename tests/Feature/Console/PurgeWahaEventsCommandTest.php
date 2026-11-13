<?php

namespace Tests\Feature\Console;

use App\Models\Channel;
use App\Models\WahaEvent;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurgeWahaEventsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $id, CarbonInterface $at, ?int $channelId = null): WahaEvent
    {
        return WahaEvent::create([
            'waha_event_id' => $id,
            'channel_id' => $channelId,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['id' => $id],
            'received_at' => $at,
        ]);
    }

    public function test_apaga_so_os_eventos_com_mais_de_7_dias(): void
    {
        $this->event('evt_8d', now()->subDays(8));
        $this->event('evt_30d', now()->subDays(30));
        $this->event('evt_6d', now()->subDays(6));

        $this->artisan('app:purge-waha-events')->expectsOutputToContain('Eventos apagados: 2')->assertSuccessful();

        $this->assertSame(['evt_6d'], WahaEvent::query()->pluck('waha_event_id')->all());
    }

    public function test_evento_no_limite_exato_fica(): void
    {
        $this->freezeTime();
        $this->event('evt_limite', now()->subDays(7));

        $this->artisan('app:purge-waha-events')->expectsOutputToContain('Eventos apagados: 0')->assertSuccessful();

        $this->assertSame(1, WahaEvent::count());
    }

    public function test_apaga_em_varios_lotes(): void
    {
        foreach (range(1, 5) as $i) {
            $this->event("evt_old_{$i}", now()->subDays(10));
        }
        $this->event('evt_novo', now());

        $this->artisan('app:purge-waha-events', ['--batch' => 2])->expectsOutputToContain('Eventos apagados: 5')->assertSuccessful();

        $this->assertSame(['evt_novo'], WahaEvent::query()->pluck('waha_event_id')->all());
    }

    public function test_lote_acima_do_teto_e_limitado(): void
    {
        // Um lote enorme viraria um `whereIn` com dezenas de milhares de parâmetros e estouraria o limite do Postgres.
        $this->event('evt_old', now()->subDays(10));
        DB::enableQueryLog();

        $this->artisan('app:purge-waha-events', ['--batch' => 999999])->expectsOutputToContain('Eventos apagados: 1')->assertSuccessful();

        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        $this->assertStringContainsString('limit 20000', $sql);
        $this->assertStringNotContainsString('limit 999999', $sql);
    }

    public function test_o_indice_de_received_at_existe(): void
    {
        $this->assertTrue(Schema::hasIndex('waha_events', ['received_at']));
    }

    public function test_evento_sem_canal_tambem_e_apagado(): void
    {
        $channel = Channel::factory()->create();
        $this->event('evt_sem_canal', now()->subDays(10));
        $this->event('evt_com_canal', now()->subDays(10), $channel->id);

        $this->artisan('app:purge-waha-events')->assertSuccessful();

        $this->assertSame(0, WahaEvent::count());
    }

    public function test_sem_nada_a_apagar_sai_com_sucesso(): void
    {
        $this->artisan('app:purge-waha-events')->expectsOutputToContain('Eventos apagados: 0')->assertSuccessful();
    }

    public function test_recente_com_outro_id_nao_e_tocado(): void
    {
        $this->event('evt_a', now()->subDays(9));
        $this->event('evt_b', now()->subDay());

        $this->artisan('app:purge-waha-events')->assertSuccessful();

        $this->assertSame(['evt_b'], WahaEvent::query()->pluck('waha_event_id')->all());
    }
}
