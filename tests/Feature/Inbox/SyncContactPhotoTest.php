<?php

namespace Tests\Feature\Inbox;

use App\Jobs\SyncContactPhotoJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\WahaEvent;
use App\Services\Inbox\IngestMessage;
use App\Services\Inbox\SyncContactPhoto;
use App\Services\Waha\WahaRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncContactPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '5516997524950@c.us';

    private const CDN = 'https://pps.whatsapp.net/v/t61/foto.jpg?sig=abc';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Cache::flush();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    }

    private function waha(mixed $body, int $status = 200): void
    {
        Http::fake(['*/api/contacts/profile-picture*' => Http::response($body, $status)]);
    }

    private function sync(Contact $contact): void
    {
        app(SyncContactPhoto::class)($contact, $this->channel);
    }

    public function test_url_https_e_gravada_e_marca_a_checagem_sem_baixar_nada(): void
    {
        $this->waha(['profilePictureURL' => self::CDN]);
        $contact = Contact::factory()->create(['wa_id' => self::PHONE]);

        $this->sync($contact);

        $fresh = $contact->fresh();
        $this->assertSame(self::CDN, $fresh?->avatar_url);
        $this->assertNotNull($fresh?->avatar_checked_at);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'pps.whatsapp.net'));
    }

    public function test_sem_foto_marca_a_checagem_e_deixa_monograma(): void
    {
        $this->waha(['profilePictureURL' => null]);
        $contact = Contact::factory()->create(['wa_id' => self::PHONE]);

        $this->sync($contact);

        $this->assertNull($contact->fresh()?->avatar_url);
        $this->assertNotNull($contact->fresh()?->avatar_checked_at);
    }

    public function test_privacidade_fechada_apaga_a_url_antiga(): void
    {
        $this->waha([], 404);
        $contact = Contact::factory()->create(['wa_id' => self::PHONE, 'avatar_url' => self::CDN, 'avatar_checked_at' => now()->subDays(2)]);

        $this->sync($contact);

        $this->assertNull($contact->fresh()?->avatar_url);
        $this->assertTrue($contact->fresh()?->avatar_checked_at?->isToday() ?? false);
    }

    public function test_url_http_conta_como_sem_foto(): void
    {
        $this->waha(['profilePictureURL' => 'http://pps.whatsapp.net/foto.jpg']);
        $contact = Contact::factory()->create(['wa_id' => self::PHONE]);

        $this->sync($contact);

        $this->assertNull($contact->fresh()?->avatar_url);
        $this->assertNotNull($contact->fresh()?->avatar_checked_at);
    }

    public function test_erro_transitorio_relanca_e_nao_marca_a_checagem(): void
    {
        $this->waha(['message' => 'rate-overlimit'], 429);
        $contact = Contact::factory()->create(['wa_id' => self::PHONE, 'avatar_url' => self::CDN]);

        try {
            $this->sync($contact);
            $this->fail('Esperava WahaRequestException.');
        } catch (WahaRequestException) {
            $this->assertNull($contact->fresh()?->avatar_checked_at);
            $this->assertSame(self::CDN, $contact->fresh()?->avatar_url);
        }
    }

    public function test_contato_so_com_lid_usa_o_lid(): void
    {
        $this->waha(['profilePictureURL' => self::CDN]);
        $contact = Contact::factory()->create(['wa_id' => null, 'lid' => '98765@lid']);

        $this->sync($contact);

        Http::assertSent(fn ($request): bool => str_contains(urldecode($request->url()), 'contactId=98765@lid'));
        $this->assertSame(self::CDN, $contact->fresh()?->avatar_url);
    }

    public function test_contato_sem_id_nao_chama_o_waha(): void
    {
        Http::fake();

        $this->sync(Contact::factory()->make(['wa_id' => null, 'lid' => null]));

        Http::assertNothingSent();
    }

    public function test_queue_for_nao_enfileira_com_checagem_de_menos_de_24h(): void
    {
        Queue::fake();
        $fresh = Contact::factory()->create(['wa_id' => self::PHONE, 'avatar_checked_at' => now()->subHours(23)]);

        app(SyncContactPhoto::class)->queueFor($fresh, $this->channel);

        Queue::assertNothingPushed();
    }

    public function test_dez_chamadas_seguidas_enfileiram_um_job_so(): void
    {
        Queue::fake();
        $contact = Contact::factory()->create(['wa_id' => self::PHONE, 'avatar_checked_at' => now()->subDays(2)]);

        foreach (range(1, 10) as $ignored) {
            app(SyncContactPhoto::class)->queueFor($contact, $this->channel);
        }

        Queue::assertPushed(SyncContactPhotoJob::class, 1);
    }

    public function test_job_chama_o_servico_e_some_quando_o_contato_nao_existe(): void
    {
        $this->waha(['profilePictureURL' => self::CDN]);
        $contact = Contact::factory()->create(['wa_id' => self::PHONE]);

        (new SyncContactPhotoJob($contact->id, $this->channel->id))->handle(app(SyncContactPhoto::class));
        (new SyncContactPhotoJob(999999, $this->channel->id))->handle(app(SyncContactPhoto::class));

        $this->assertSame(self::CDN, $contact->fresh()?->avatar_url);
        Http::assertSentCount(1);
    }

    public function test_falha_do_waha_no_job_nao_estoura_e_deixa_para_a_proxima_tentativa(): void
    {
        $this->waha(['message' => 'rate-overlimit'], 429);
        $contact = Contact::factory()->create(['wa_id' => self::PHONE, 'avatar_url' => self::CDN]);

        (new SyncContactPhotoJob($contact->id, $this->channel->id))->handle(app(SyncContactPhoto::class));

        $this->assertNull($contact->fresh()?->avatar_checked_at);
        $this->assertSame(self::CDN, $contact->fresh()?->avatar_url);
    }

    public function test_ingestao_de_mensagem_enfileira_o_job_e_nao_chama_o_waha(): void
    {
        Queue::fake();
        Http::fake();
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => $this->channel->id,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['event' => 'message', 'session' => 'main', 'payload' => ['id' => 'false_A1', 'from' => self::PHONE, 'fromMe' => false, 'body' => 'oi', 'timestamp' => now()->timestamp]],
            'received_at' => now(),
        ]);

        $this->assertInstanceOf(Message::class, app(IngestMessage::class)($event));

        Queue::assertPushed(SyncContactPhotoJob::class, 1);
        Http::assertNothingSent();
    }
}
