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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ResolveMessageMediaJobTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private const SERIALIZED = 'false_5516997524950@c.us_IMG1';

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

    /**
     * Simula o WAHA: a busca da mensagem responde `$media` e só os arquivos de `$stored` existem.
     *
     * @param  array<string, mixed>|null  $media
     * @param  list<string>  $stored  caminhos completos (`/api/files/...`) que respondem 206
     */
    private function fakeWaha(?array $media, array $stored = []): void
    {
        Http::fake(function (Request $request) use ($media, $stored) {
            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

            if (str_contains($path, '/chats/')) {
                return Http::response(['id' => 'x', 'media' => $media]);
            }

            return Http::response('', in_array($path, $stored, true) ? 206 : 404);
        });
    }

    public function test_busca_a_midia_pelo_id_e_grava_o_nome_que_existe_no_waha(): void
    {
        Event::fake([MessageStatusChanged::class]);
        $this->fakeWaha(['filename' => 'IMG1.jpeg', 'mimetype' => 'image/jpeg; x=y'], ['/api/files/main/IMG1.jpeg']);

        (new ResolveMessageMediaJob($this->message->id))->handle();

        $fresh = $this->message->fresh();
        $this->assertSame('IMG1.jpeg', $fresh?->media_ref);
        $this->assertSame('image/jpeg', $fresh->media_mime);
        $this->assertSame('IMG1.jpeg', $fresh->media_name);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_contains($r->url(), '/api/main/chats/'.rawurlencode(self::PEER).'/messages/'.rawurlencode(self::SERIALIZED))
            && str_contains($r->url(), 'downloadMedia=true'));
        Event::assertDispatched(MessageStatusChanged::class);
    }

    public function test_acha_o_arquivo_pelo_id_serializado_quando_o_filename_nao_existe(): void
    {
        $this->fakeWaha(['filename' => 'outro.jpeg', 'mimetype' => 'image/jpeg'], ['/api/files/main/'.self::SERIALIZED.'.jpeg']);

        (new ResolveMessageMediaJob($this->message->id))->handle();

        $this->assertSame(self::SERIALIZED.'.jpeg', $this->message->fresh()?->media_ref);
    }

    public function test_sem_filename_deriva_o_nome_do_id_e_da_extensao_do_mimetype(): void
    {
        $this->fakeWaha(['url' => 'https://mmg.whatsapp.net/x.enc', 'mimetype' => 'audio/ogg; codecs=opus'], ['/api/files/IMG1.ogg']);

        (new ResolveMessageMediaJob($this->message->id))->handle();

        $this->assertSame('IMG1.ogg', $this->message->fresh()?->media_ref);
    }

    public function test_usa_o_nome_da_url_do_proprio_waha(): void
    {
        $this->fakeWaha(['url' => 'http://localhost:3000/api/files/main/arquivo%40x.pdf', 'mimetype' => 'application/pdf'], ['/api/files/main/arquivo@x.pdf']);

        (new ResolveMessageMediaJob($this->message->id))->handle();

        $this->assertSame('arquivo@x.pdf', $this->message->fresh()?->media_ref);
    }

    public function test_nome_invalido_do_waha_nunca_e_gravado(): void
    {
        $this->fakeWaha(['filename' => '../x'], []);

        try {
            (new ResolveMessageMediaJob($this->message->id))->handle();
            $this->fail('Deveria pedir nova tentativa');
        } catch (\RuntimeException) {
            $this->assertNull($this->message->fresh()?->media_ref);
        }
    }

    public function test_mimetype_longo_e_cortado_para_caber_na_coluna(): void
    {
        $this->fakeWaha(['filename' => 'a.jpg', 'mimetype' => 'image/'.str_repeat('x', 300)], ['/api/files/main/a.jpg']);

        (new ResolveMessageMediaJob($this->message->id))->handle();

        $this->assertSame(100, mb_strlen((string) $this->message->fresh()?->media_mime));
    }

    public function test_nenhum_candidato_existe_registra_o_que_foi_tentado_e_pede_nova_tentativa(): void
    {
        Log::spy();
        $this->fakeWaha(['filename' => 'a.jpg', 'mimetype' => 'image/jpeg'], []);

        try {
            (new ResolveMessageMediaJob($this->message->id))->handle();
            $this->fail('Deveria pedir nova tentativa');
        } catch (\RuntimeException) {
            $this->assertNull($this->message->fresh()?->media_ref);
            Log::shouldHaveReceived('warning')->once();
        }
    }

    public function test_midia_sem_objeto_media_pede_nova_tentativa(): void
    {
        $this->fakeWaha(null);

        $this->expectException(\RuntimeException::class);

        (new ResolveMessageMediaJob($this->message->id))->handle();
    }

    public function test_midia_ja_apontando_para_arquivo_existente_so_confirma(): void
    {
        $this->message->update(['media_ref' => 'ok.jpeg', 'media_mime' => 'image/jpeg']);
        $this->fakeWaha(['filename' => 'ok.jpeg', 'mimetype' => 'image/jpeg'], ['/api/files/main/ok.jpeg']);

        (new ResolveMessageMediaJob($this->message->id))->handle();

        $this->assertSame('ok.jpeg', $this->message->fresh()?->media_ref);
    }

    public function test_waha_fora_do_ar_pede_nova_tentativa(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->expectException(\RuntimeException::class);

        (new ResolveMessageMediaJob($this->message->id))->handle();
    }
}
