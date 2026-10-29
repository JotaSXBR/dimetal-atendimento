<?php

namespace Tests\Feature\Inbox;

use App\Enums\ChannelStatus;
use App\Enums\DeliveryStatus;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SendTicketMessageJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\DrainOutbox;
use App\Services\Inbox\IngestMessage;
use App\Services\Inbox\ResendTicketMessage;
use App\Services\Inbox\SendFailure;
use App\Services\Inbox\SendTicketMedia;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Tests\TestCase;

class SendMediaTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private const MP4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free";

    private const MP3 = "ID3\x03\x00\x00\x00\x00\x00\x00\xFF\xFB\x90\x00\xFF\xFB\x90\x00\xFF\xFB\x90\x00\xFF\xFB\x90\x00";

    private Channel $channel;

    private Ticket $ticket;

    private User $author;

    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Sleep::fake();
        Queue::fake();
        Storage::fake('local');

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->ticket = Ticket::factory()->for(Contact::factory()->create(['wa_id' => self::PEER]))->for($this->channel)->create(['status' => TicketStatus::EM_ATENDIMENTO]);
        $this->author = User::factory()->create();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function upload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'snd');
        $this->temporary[] = $path;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function fakeWaha(): void
    {
        $count = 0;
        Http::fake(function (Request $r) use (&$count) {
            return str_contains($r->url(), '/api/send') && ! str_contains($r->url(), 'sendSeen')
                ? Http::response(['id' => 'true_'.self::PEER.'_MEDIA'.++$count], 201)
                : Http::response([], 201);
        });
    }

    private function send(string $name, string $bytes, string $caption = '', bool $voice = false): Message
    {
        return app(SendTicketMedia::class)($this->ticket, $this->author, $this->upload($name, $bytes), $caption, $voice);
    }

    private function sentTo(string $endpoint): ?Request
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), $endpoint))->first()[0] ?? null;
    }

    public function test_imagem_vai_por_send_image_com_a_legenda_e_o_conteudo_em_base64(): void
    {
        $this->fakeWaha();
        $message = $this->send('foto.jpg', self::JPEG, 'Segue a foto');

        app(SendTicketMessage::class)->deliver($message);

        $request = $this->sentTo('/api/sendImage');
        $this->assertNotNull($request);
        $this->assertSame('main', $request['session']);
        $this->assertSame(self::PEER, $request['chatId']);
        $this->assertSame('Segue a foto', $request['caption']);
        $this->assertSame('image/jpeg', $request['file']['mimetype']);
        $this->assertSame('foto.jpg', $request['file']['filename']);
        $this->assertSame(base64_encode(self::JPEG), $request['file']['data']);
        $this->assertSame(DeliveryStatus::SENT, $message->fresh()?->delivery_status);
        $this->assertSame('MEDIA1', $message->fresh()?->wa_message_id);
        $this->assertNotNull($this->ticket->fresh()?->first_human_reply_at);
    }

    public function test_cada_tipo_usa_o_endpoint_certo(): void
    {
        $this->fakeWaha();

        app(SendTicketMessage::class)->deliver($this->send('orcamento.pdf', self::PDF));
        app(SendTicketMessage::class)->deliver($this->send('clip.mp4', self::MP4, 'Veja'));
        app(SendTicketMessage::class)->deliver($this->send('musica.mp3', self::MP3, 'ignorada no audio'));

        $this->assertSame('application/pdf', $this->sentTo('/api/sendFile')['file']['mimetype'] ?? null);
        $this->assertSame('Veja', $this->sentTo('/api/sendVideo')['caption'] ?? null);
        $voice = $this->sentTo('/api/sendVoice');
        $this->assertTrue($voice['convert'] ?? false);
        $this->assertNull($voice['caption'] ?? null);
    }

    public function test_tres_arquivos_saem_na_ordem_em_que_foram_criados(): void
    {
        $this->fakeWaha();
        $messages = [
            $this->send('a.jpg', self::JPEG),
            $this->send('b.pdf', self::PDF),
            $this->send('c.mp4', self::MP4),
        ];

        $ids = collect($messages)->map(fn (Message $m): int => $m->id)->all();
        $this->assertSame($ids, collect($ids)->sort()->values()->all());
        Queue::assertPushed(SendTicketMessageJob::class, 3);

        foreach ($messages as $message) {
            app(SendTicketMessage::class)->deliver($message);
        }

        $order = Http::recorded(fn (Request $r) => preg_match('#/api/send(Image|File|Video)$#', $r->url()) === 1)
            ->map(fn (array $pair): string => basename($pair[0]->url()))->values()->all();
        $this->assertSame(['sendImage', 'sendFile', 'sendVideo'], $order);
    }

    public function test_a_linha_guarda_tipo_nome_tamanho_e_a_copia_local(): void
    {
        $message = $this->send('orcamento.pdf', self::PDF, 'Legenda');

        $this->assertSame('media', $message->type);
        $this->assertSame('Legenda', $message->body);
        $this->assertSame(MessageOrigin::PAINEL, $message->origin);
        $this->assertSame('application/pdf', $message->media_mime);
        $this->assertSame('orcamento.pdf', $message->media_name);
        $this->assertSame(strlen(self::PDF), $message->media_size);
        $this->assertNull($message->media_ref);
        Storage::disk('local')->assertExists((string) $message->local_path);
        $this->assertStringStartsWith('outbound/', (string) $message->local_path);
    }

    public function test_arquivo_recusado_nao_cria_mensagem_nem_deixa_arquivo_no_disco(): void
    {
        try {
            $this->send('programa.exe', "MZ\x90\x00\x03\x00\x00\x00");
            $this->fail('Era esperada a recusa do executável.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        $this->assertSame(0, Message::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Queue::assertNothingPushed();
    }

    public function test_canal_desconectado_deixa_aguardando_com_a_copia_e_envia_na_reconexao(): void
    {
        $this->fakeWaha();
        $this->channel->update(['status' => ChannelStatus::STOPPED]);
        $message = $this->send('orcamento.pdf', self::PDF);

        app(SendTicketMessage::class)->deliver($message);

        $this->assertSame(DeliveryStatus::QUEUED, $message->fresh()?->delivery_status);
        Storage::disk('local')->assertExists((string) $message->local_path);
        $this->assertNull($this->sentTo('/api/sendFile'));

        $this->channel->update(['status' => ChannelStatus::WORKING]);
        app(DrainOutbox::class)($this->channel->fresh() ?? $this->channel);

        $this->assertNotNull($this->sentTo('/api/sendFile'));
        $this->assertSame(DeliveryStatus::SENT, $message->fresh()?->delivery_status);
    }

    public function test_copia_sumida_falha_com_motivo_sem_chamar_o_waha_e_sem_laco(): void
    {
        $this->fakeWaha();
        $message = $this->send('orcamento.pdf', self::PDF);
        Storage::disk('local')->delete((string) $message->local_path);

        app(SendTicketMessage::class)->deliver($message);

        $fresh = $message->fresh();
        $this->assertSame(DeliveryStatus::FAILED, $fresh?->delivery_status);
        $this->assertSame(SendFailure::REFUSED, $fresh->failure_code);
        $this->assertStringContainsString('não está mais disponível', (string) $fresh->failure_reason);
        $this->assertNull($this->sentTo('/api/sendFile'));
    }

    public function test_eco_do_anexo_enviado_pelo_painel_e_adotado_e_nao_vira_fora_do_painel(): void
    {
        $this->fakeWaha();
        $message = $this->send('foto.jpg', self::JPEG, 'Segue a foto');
        $message->touch();

        // O WhatsApp recomprime: o eco volta com outro tipo e outro nome de arquivo.
        $echo = WahaEvent::create([
            'waha_event_id' => 'evt_echo',
            'channel_id' => $this->channel->id,
            'event' => 'message.any',
            'session' => 'main',
            'payload' => ['event' => 'message.any', 'payload' => [
                'id' => 'true_'.self::PEER.'_ECHOMEDIA',
                'from' => '5516999990001@c.us',
                'to' => self::PEER,
                'fromMe' => true,
                'body' => 'Segue a foto',
                'hasMedia' => true,
                'media' => ['mimetype' => 'image/png', 'filename' => 'ECHOMEDIA.png'],
                'timestamp' => now()->timestamp,
            ]],
            'received_at' => now(),
        ]);

        app(IngestMessage::class)($echo);

        $this->assertSame(0, Message::query()->where('origin', MessageOrigin::FORA_DO_PAINEL->value)->count());
        $this->assertSame(1, Message::query()->where('direction', 'out')->count());
        $this->assertSame('ECHOMEDIA', $message->fresh()?->wa_message_id);
    }

    public function test_reenvio_vale_para_anexo_com_copia_local_e_nao_para_sem_ela(): void
    {
        $message = $this->send('orcamento.pdf', self::PDF);
        $message->update(['delivery_status' => DeliveryStatus::FAILED, 'failure_code' => SendFailure::UNKNOWN]);
        $resend = app(ResendTicketMessage::class);

        $this->assertSame(ResendTicketMessage::CONFIRM, $resend->kind($message->fresh() ?? $message, $this->author->forceFill(['role' => UserRole::SUPERVISOR])));

        $message->update(['local_path' => null]);

        $this->assertSame(ResendTicketMessage::NONE, $resend->kind($message->fresh() ?? $message, $this->author));
    }

    public function test_rota_de_midia_serve_a_copia_local_sem_chamar_o_waha(): void
    {
        Http::preventStrayRequests();
        $viewer = User::factory()->create(['role' => UserRole::ADMIN]);
        $image = $this->send('foto.jpg', self::JPEG);
        $sheet = $this->send('tabela.xls', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 600));

        $this->actingAs($viewer)->get(route('midia', $image))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $download = $this->actingAs($viewer)->get(route('midia', $sheet));
        $download->assertOk();
        $this->assertSame('application/octet-stream', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
    }
}
