<?php

namespace Tests\Feature\Jobs;

use App\Enums\MessageDirection;
use App\Events\MessageStatusChanged;
use App\Jobs\ResolveMessageMediaJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToWriteFile;
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

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key', 'media.disk' => 'media']);
        Storage::fake('media');

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

            if (! in_array($path, $stored, true)) {
                return Http::response('', 404);
            }

            // A busca do arquivo pede só o primeiro byte (206); a cópia lê o arquivo inteiro (200).
            return $request->hasHeader('Range') ? Http::response('C', 206) : Http::response('CONTEUDO', 200);
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

    public function test_copia_o_arquivo_do_waha_para_o_disco_antes_de_avisar_a_tela(): void
    {
        $pathAtTheEvent = null;
        Event::listen(MessageStatusChanged::class, function (MessageStatusChanged $event) use (&$pathAtTheEvent): void {
            $pathAtTheEvent = Message::query()->find($this->message->id)?->media_path;
        });
        $this->fakeWaha(['filename' => 'IMG1.jpeg', 'mimetype' => 'image/jpeg'], ['/api/files/main/IMG1.jpeg']);

        (new ResolveMessageMediaJob($this->message->id))->handle();

        $fresh = $this->message->fresh();
        $this->assertMatchesRegularExpression('#^inbound/'.$this->message->channel_id.'/\d{4}/\d{2}/[0-9a-f-]{36}\.jpeg$#', (string) $fresh?->media_path);
        $this->assertSame('CONTEUDO', Storage::disk('media')->get((string) $fresh?->media_path));
        $this->assertSame(8, $fresh?->media_size);
        $this->assertSame($fresh?->media_path, $pathAtTheEvent, 'a tela só é avisada depois de a cópia existir');
    }

    public function test_rodar_de_novo_nao_copia_nem_chama_o_waha_outra_vez(): void
    {
        $this->fakeWaha(['filename' => 'IMG1.jpeg', 'mimetype' => 'image/jpeg'], ['/api/files/main/IMG1.jpeg']);
        (new ResolveMessageMediaJob($this->message->id))->handle();
        $path = $this->message->fresh()?->media_path;
        $requests = count(Http::recorded());

        (new ResolveMessageMediaJob($this->message->id))->handle();

        $this->assertSame($path, $this->message->fresh()?->media_path);
        $this->assertCount(1, Storage::disk('media')->allFiles());
        $this->assertCount($requests, Http::recorded());
    }

    public function test_falha_ao_ler_o_arquivo_relanca_e_deixa_a_mensagem_abrindo_pelo_waha(): void
    {
        Http::fake(function (Request $request) {
            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

            if (str_contains($path, '/chats/')) {
                return Http::response(['id' => 'x', 'media' => ['filename' => 'IMG1.jpeg', 'mimetype' => 'image/jpeg']]);
            }

            return $request->hasHeader('Range') ? Http::response('C', 206) : Http::response('erro', 500);
        });
        $retried = false;

        try {
            (new ResolveMessageMediaJob($this->message->id))->handle();
        } catch (\RuntimeException) {
            $retried = true;
        }

        $this->assertTrue($retried, 'a falha de cópia precisa relançar para o job tentar de novo');
        $fresh = $this->message->fresh();
        $this->assertSame('IMG1.jpeg', $fresh?->media_ref);
        $this->assertNull($fresh?->media_path);
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_falha_de_gravacao_no_disco_leva_o_motivo_do_driver_na_excecao(): void
    {
        $this->fakeWaha(['filename' => 'IMG1.jpeg', 'mimetype' => 'image/jpeg'], ['/api/files/main/IMG1.jpeg']);
        // O disco real (S3, throw=false) devolveria só `false`; o driver por baixo é quem sabe o motivo.
        $adapter = \Mockery::mock(FilesystemAdapter::class);
        $adapter->shouldReceive('writeStream')->andThrow(UnableToWriteFile::atLocation('x', 'disco cheio'));
        Storage::set('media', new \Illuminate\Filesystem\FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));
        $caught = null;

        try {
            (new ResolveMessageMediaJob($this->message->id))->handle();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertInstanceOf(FilesystemException::class, $caught->getPrevious(), 'o motivo do driver precisa ir junto, senão o log não diz por que falhou');
        $this->assertNull($this->message->fresh()?->media_path);
    }

    public function test_grava_com_stream_rebobinavel_mesmo_quando_o_corpo_do_waha_nao_e(): void
    {
        Http::fake(function (Request $request) {
            $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

            if (str_contains($path, '/chats/')) {
                return Http::response(['id' => 'x', 'media' => ['filename' => 'IMG1.jpeg', 'mimetype' => 'image/jpeg']]);
            }

            if ($request->hasHeader('Range')) {
                return Http::response('C', 206);
            }

            // O corpo em stream do WAHA não volta atrás; o SDK do S3 precisa rebobinar para assinar o envio.
            return Create::promiseFor(new Response(200, [], new NoSeekStream(Utils::streamFor('CONTEUDO'))));
        });
        $received = null;
        $adapter = \Mockery::mock(FilesystemAdapter::class);
        $adapter->shouldReceive('writeStream')->once()->andReturnUsing(function (string $path, $contents) use (&$received): void {
            if (@fseek($contents, 0) !== 0) {
                throw UnableToWriteFile::atLocation($path, 'Stream is not seekable');
            }
            $received = stream_get_contents($contents);
        });
        $adapter->shouldReceive('fileSize')->andReturn(new FileAttributes('x', 8));
        Storage::set('media', new \Illuminate\Filesystem\FilesystemAdapter(new Filesystem($adapter), $adapter, ['throw' => false]));

        (new ResolveMessageMediaJob($this->message->id))->handle();

        $this->assertSame('CONTEUDO', $received);
        $this->assertNotNull($this->message->fresh()?->media_path);
    }
}
