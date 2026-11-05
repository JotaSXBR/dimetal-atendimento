<?php

namespace Tests\Feature\Inbox;

use App\Models\Channel;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaRequestException;
use App\Support\TicketEventText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NumberExistsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
    }

    public function test_numero_com_whatsapp_e_enviado_so_com_digitos_e_a_sessao(): void
    {
        Http::fake(['*/api/contacts/check-exists*' => Http::response(['numberExists' => true, 'chatId' => '5516999999999@c.us'])]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        $this->assertTrue((new WahaClient($channel))->numberExists('5516999999999@c.us'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/contacts/check-exists')
            && $request['phone'] === '5516999999999'
            && $request['session'] === 'main'
            && $request->hasHeader('X-Api-Key', 'test-key'));
    }

    public function test_numero_sem_whatsapp_e_falso(): void
    {
        Http::fake(['*/api/contacts/check-exists*' => Http::response(['numberExists' => false])]);

        $this->assertFalse((new WahaClient(Channel::factory()->create()))->numberExists('5516999999999@c.us'));
    }

    public function test_erro_do_waha_vira_excecao(): void
    {
        Http::fake(['*/api/contacts/check-exists*' => Http::response(['message' => 'Session not found'], 500)]);

        $this->expectException(WahaRequestException::class);

        (new WahaClient(Channel::factory()->create()))->numberExists('5516999999999@c.us');
    }

    public function test_evento_de_conversa_iniciada_tem_rotulo(): void
    {
        $this->assertSame('Conversa iniciada pela equipe', TicketEventText::label('opened_by_agent'));
    }
}
