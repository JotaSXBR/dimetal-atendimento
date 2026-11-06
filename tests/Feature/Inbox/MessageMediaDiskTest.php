<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MessageMediaDiskTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->create(['waha_session' => 'main'])->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function media(array $attributes = []): Message
    {
        return Message::factory()->for($this->ticket)->create(array_merge([
            'type' => 'media', 'body' => '', 'media_mime' => 'audio/ogg', 'media_name' => 'voz.ogg',
            'media_path' => 'inbound/1/2026/11/voz.ogg',
        ], $attributes));
    }

    private function localDisk(): void
    {
        Storage::fake('media');
        config(['media.disk' => 'media']);
        Storage::disk('media')->put('inbound/1/2026/11/voz.ogg', '0123456789');
    }

    private function s3Disk(): void
    {
        config([
            'media.disk' => 'rustfs',
            'filesystems.disks.rustfs' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1', 'bucket' => 'b', 'endpoint' => 'http://rustfs.test:9000', 'use_path_style_endpoint' => true, 'throw' => false],
        ]);
    }

    public function test_serve_do_disco_com_os_cabecalhos_de_seguranca(): void
    {
        $this->localDisk();
        Http::fake();

        $response = $this->actingAs($this->me)->get(route('midia', $this->media()));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'audio/ogg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        Http::assertNothingSent();
    }

    public function test_range_do_disco_devolve_206_e_fora_do_arquivo_serve_inteiro(): void
    {
        $this->localDisk();
        $message = $this->media();

        $partial = $this->actingAs($this->me)->get(route('midia', $message), ['Range' => 'bytes=0-3']);
        $partial->assertStatus(206);
        $partial->assertHeader('Content-Range', 'bytes 0-3/10');
        $this->assertSame('0123', $partial->streamedContent());

        // O Symfony ignora um Range que não cabe no arquivo (a RFC 9110 permite) e serve tudo; o 416 do bucket passa direto (S3).
        $whole = $this->actingAs($this->me)->get(route('midia', $message), ['Range' => 'bytes=100-']);
        $whole->assertOk();
        $this->assertSame('0123456789', $whole->streamedContent());
    }

    public function test_arquivo_purgado_da_404_mesmo_com_referencia_no_waha(): void
    {
        $this->localDisk();
        Http::fake(['*' => Http::response('DADOS', 200)]);

        $this->actingAs($this->me)->get(route('midia', $this->media(['media_purged_at' => now(), 'media_path' => null, 'media_ref' => 'abc.ogg'])))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_copia_que_sumiu_do_disco_cai_no_waha(): void
    {
        $this->localDisk();
        Http::fake(['*/api/files/main/abc.ogg' => Http::response('DADOS', 200, ['Content-Type' => 'audio/ogg'])]);

        $response = $this->actingAs($this->me)->get(route('midia', $this->media(['media_path' => 'inbound/1/2026/11/sumiu.ogg', 'media_ref' => 'abc.ogg'])));

        $response->assertOk();
        $this->assertSame('DADOS', $response->streamedContent());
    }

    public function test_s3_repassa_o_range_e_o_status_do_endpoint_interno(): void
    {
        $this->s3Disk();
        Http::fake(['rustfs.test*' => Http::response('0123', 206, ['Content-Range' => 'bytes 0-3/10', 'Content-Length' => '4'])]);

        $response = $this->actingAs($this->me)->get(route('midia', $this->media()), ['Range' => 'bytes=0-3']);

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 0-3/10');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('0123', $response->streamedContent());
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'rustfs.test') && $r->header('Range') === ['bytes=0-3']);
    }

    public function test_s3_sem_o_objeto_cai_no_waha(): void
    {
        $this->s3Disk();
        Http::fake([
            'rustfs.test*' => Http::response('<Error/>', 404),
            '*/api/files/main/abc.ogg' => Http::response('DADOS', 200, ['Content-Type' => 'audio/ogg']),
        ]);

        $response = $this->actingAs($this->me)->get(route('midia', $this->media(['media_ref' => 'abc.ogg'])));

        $response->assertOk();
        $this->assertSame('DADOS', $response->streamedContent());
    }

    public function test_quem_nao_ve_o_ticket_leva_403_sem_tocar_no_disco(): void
    {
        $this->s3Disk();
        Http::fake();
        $other = User::factory()->create(['role' => UserRole::ATENDENTE]);

        $this->actingAs($other)->get(route('midia', $this->media()))->assertForbidden();

        Http::assertNothingSent();
    }
}
