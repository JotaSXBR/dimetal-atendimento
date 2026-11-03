<?php

namespace Tests\Feature\Services;

use App\Models\Channel;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaMessages;
use App\Services\Waha\WahaRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WahaMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
    }

    private function messages(): WahaMessages
    {
        return new WahaMessages(new WahaClient(Channel::factory()->create(['waha_session' => 'main'])));
    }

    public function test_entrega_o_corpo_de_cada_mensagem_como_veio(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/waha/chats-all-messages.json')), true);
        Http::fake(['*/api/main/chats/all/messages*' => Http::response($fixture, 200)]);

        $items = iterator_to_array($this->messages()->between(now()->subDay(), now()), false);

        $this->assertSame($fixture, $items);
    }

    public function test_pagina_ate_a_ultima_pagina(): void
    {
        $page = fn (int $n): array => array_map(fn (int $i): array => ['id' => "false_x@c.us_{$i}"], range(1, $n));
        Http::fake(['*/api/main/chats/all/messages*' => Http::sequence()->push($page(100), 200)->push($page(3), 200)]);
        $from = now()->subDay();
        $to = now();

        $this->assertCount(103, iterator_to_array($this->messages()->between($from, $to), false));

        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values();
        $this->assertCount(2, $requests);
        $this->assertSame($from->getTimestamp(), (int) $requests[0]['filter.timestamp.gte']);
        $this->assertSame($to->getTimestamp(), (int) $requests[0]['filter.timestamp.lte']);
        $this->assertSame(100, (int) $requests[0]['limit']);
        $this->assertSame(0, (int) $requests[0]['offset']);
        $this->assertSame('false', $requests[0]['downloadMedia']);
        $this->assertSame(100, (int) $requests[1]['offset']);
    }

    public function test_para_no_teto_de_paginas(): void
    {
        $full = array_map(fn (int $i): array => ['id' => "false_x@c.us_{$i}"], range(1, 100));
        Http::fake(['*/api/main/chats/all/messages*' => Http::response($full, 200)]);

        $this->assertCount(200 * 100, iterator_to_array($this->messages()->between(now()->subDay(), now()), false));
        Http::assertSentCount(200);
    }

    public function test_janela_de_varios_dias_vai_em_fatias_de_24h_sem_sobreposicao(): void
    {
        Http::fake(['*/api/main/chats/all/messages*' => Http::response([], 200)]);
        $to = now();
        $from = $to->copy()->subDays(3);

        iterator_to_array($this->messages()->between($from, $to), false);

        $requests = Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values();
        $this->assertCount(3, $requests);
        $start = $from->getTimestamp();
        $this->assertSame([$start, $start + 86401, $start + 172802], $requests->map(fn (array $r): int => (int) $r['filter.timestamp.gte'])->all());
        $this->assertSame($start + 86400, (int) $requests[0]['filter.timestamp.lte']);
        $this->assertSame($to->getTimestamp(), (int) $requests[2]['filter.timestamp.lte']);
    }

    public function test_fatia_que_bate_o_teto_de_paginas_avisa_no_log(): void
    {
        $full = array_map(fn (int $i): array => ['id' => "false_x@c.us_{$i}"], range(1, 100));
        Http::fake(['*/api/main/chats/all/messages*' => Http::response($full, 200)]);
        Log::spy();

        iterator_to_array($this->messages()->between(now()->subDay(), now()), false);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'teto'));
    }

    public function test_falha_do_waha_lanca_waha_request_exception(): void
    {
        Http::fake(['*/api/main/chats/all/messages*' => Http::response(['message' => 'boom'], 500)]);

        $this->expectException(WahaRequestException::class);

        iterator_to_array($this->messages()->between(now()->subDay(), now()), false);
    }
}
