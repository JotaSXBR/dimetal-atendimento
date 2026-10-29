<?php

namespace Tests\Feature\Jobs;

use App\Enums\MessageDirection;
use App\Events\MessageStatusChanged;
use App\Jobs\ResolveMessageMediaJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResolveMessageMediaJobTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);

        $ticket = Ticket::factory()
            ->for(Contact::factory()->create(['wa_id' => self::PEER]))
            ->for(Channel::factory()->connected()->create(['waha_session' => 'main']))
            ->create();

        $this->message = Message::factory()->for($ticket)->create([
            'wa_message_id' => 'IMG1',
            'type' => 'media',
            'body' => '',
            'direction' => MessageDirection::IN,
        ]);
    }

    public function test_busca_a_midia_pelo_id_e_grava_a_referencia(): void
    {
        Event::fake([MessageStatusChanged::class]);
        Http::fake(['*' => Http::response(['id' => 'x', 'media' => ['filename' => 'IMG1.jpeg', 'mimetype' => 'image/jpeg; x=y']])]);

        (new ResolveMessageMediaJob($this->message->id))->handle();

        $fresh = $this->message->fresh();
        $this->assertSame('IMG1.jpeg', $fresh?->media_ref);
        $this->assertSame('image/jpeg', $fresh->media_mime);
        $this->assertSame('IMG1.jpeg', $fresh->media_name);
        Http::assertSent(fn ($r) => $r->method() === 'GET'
            && str_contains($r->url(), '/api/main/chats/'.rawurlencode(self::PEER).'/messages/'.rawurlencode('false_'.self::PEER.'_IMG1'))
            && str_contains($r->url(), 'downloadMedia=true'));
        Event::assertDispatched(MessageStatusChanged::class);
    }

    public function test_midia_ja_resolvida_nao_chama_o_waha(): void
    {
        $this->message->update(['media_ref' => 'ok.jpeg']);
        Http::fake();

        (new ResolveMessageMediaJob($this->message->id))->handle();

        Http::assertNothingSent();
    }

    public function test_waha_sem_arquivo_ainda_pede_nova_tentativa(): void
    {
        Http::fake(['*' => Http::response(['id' => 'x', 'media' => null])]);

        $this->expectException(\RuntimeException::class);

        (new ResolveMessageMediaJob($this->message->id))->handle();
    }

    public function test_waha_fora_do_ar_pede_nova_tentativa(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->expectException(\RuntimeException::class);

        (new ResolveMessageMediaJob($this->message->id))->handle();
    }

    public function test_esgotadas_as_tentativas_a_mensagem_continua_sem_referencia(): void
    {
        (new ResolveMessageMediaJob($this->message->id))->failed(new \RuntimeException('x'));

        $fresh = $this->message->fresh();
        $this->assertNull($fresh?->media_ref);
        $this->assertSame('media', $fresh->type);
    }
}
