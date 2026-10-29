<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessageMediaRouteTest extends TestCase
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
            'type' => 'media',
            'body' => '',
            'media_ref' => 'abc.ogg',
            'media_mime' => 'audio/ogg',
        ], $attributes));
    }

    public function test_quem_ve_o_ticket_recebe_o_arquivo_sem_vazar_a_chave(): void
    {
        Http::fake(['*/api/files/main/abc.ogg' => Http::response('DADOS', 200, ['Content-Type' => 'audio/ogg'])]);

        $response = $this->actingAs($this->me)->get(route('midia', $this->media()));

        $response->assertOk();
        $this->assertSame('DADOS', $response->streamedContent());
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Accel-Buffering', 'no');
        $response->assertHeader('Content-Type', 'audio/ogg');
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('test-key', json_encode($response->headers->all()) ?: '');
        Http::assertSent(fn ($r) => $r->hasHeader('X-Api-Key', 'test-key'));
    }

    public function test_quem_nao_ve_o_ticket_leva_403(): void
    {
        Http::fake();
        $other = User::factory()->create(['role' => UserRole::ATENDENTE]);

        $this->actingAs($other)->get(route('midia', $this->media()))->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get(route('midia', $this->media()))->assertRedirect(route('login'));
    }

    public function test_tipo_fora_da_lista_baixa_como_anexo(): void
    {
        Http::fake(['*' => Http::response('<html>', 200)]);

        $response = $this->actingAs($this->me)->get(route('midia', $this->media(['media_mime' => 'text/html', 'media_ref' => 'x.html'])));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/octet-stream');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_referencia_com_barra_ou_ponto_ponto_da_404_sem_chamar_o_waha(): void
    {
        Http::fake();

        foreach (['../x', 'a/b', '..'] as $ref) {
            $this->actingAs($this->me)->get(route('midia', $this->media(['media_ref' => $ref])))->assertNotFound();
        }

        Http::assertNothingSent();
    }

    public function test_referencia_so_de_pontos_ou_com_quebra_de_linha_da_404(): void
    {
        Http::fake();

        foreach (['.', "abc.ogg\n"] as $ref) {
            $this->actingAs($this->me)->get(route('midia', $this->media(['media_ref' => $ref])))->assertNotFound();
        }

        Http::assertNothingSent();
    }

    public function test_pede_o_arquivo_sem_compressao_e_range_invalido_vira_416(): void
    {
        Http::fake(['*' => Http::response('', 416)]);

        $this->actingAs($this->me)->withHeaders(['Range' => 'bytes=999-'])->get(route('midia', $this->media()))->assertStatus(416);

        Http::assertSent(fn ($r) => $r->hasHeader('Accept-Encoding', 'identity'));
    }

    public function test_range_e_repassado_e_devolve_206(): void
    {
        Http::fake(['*' => Http::response('AB', 206, ['Content-Range' => 'bytes 0-1/10', 'Content-Type' => 'audio/ogg'])]);

        $response = $this->actingAs($this->me)->withHeaders(['Range' => 'bytes=0-1'])->get(route('midia', $this->media()));

        $response->assertStatus(206);
        $response->assertHeader('Content-Range', 'bytes 0-1/10');
        $response->assertHeader('Accept-Ranges', 'bytes');
        Http::assertSent(fn ($r) => $r->hasHeader('Range', 'bytes=0-1'));
    }

    public function test_mensagem_sem_midia_da_404(): void
    {
        Http::fake();

        $this->actingAs($this->me)->get(route('midia', $this->media(['media_ref' => null])))->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_arquivo_que_o_waha_nao_tem_da_404(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->actingAs($this->me)->get(route('midia', $this->media()))->assertNotFound();
    }
}
