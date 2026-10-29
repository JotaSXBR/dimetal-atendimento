<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class InboxMediaTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function screen(array $attributes): Testable
    {
        Message::factory()->for($this->ticket)->create(array_merge(['type' => 'media', 'body' => ''], $attributes));

        return Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id);
    }

    public function test_imagem_aparece_como_img_com_a_rota_da_midia(): void
    {
        $message = Message::factory()->for($this->ticket)->create(['type' => 'media', 'body' => '', 'media_ref' => 'a.jpg', 'media_mime' => 'image/jpeg']);

        Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id)
            ->assertSeeHtml('<img')
            ->assertSeeHtml(route('midia', $message));
    }

    public function test_audio_aparece_no_player_proprio_com_velocidade(): void
    {
        $message = Message::factory()->for($this->ticket)->create(['type' => 'media', 'body' => '', 'media_ref' => 'a.ogg', 'media_mime' => 'audio/ogg']);

        Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id)
            ->assertSeeHtml('data-audio-player')
            ->assertSeeHtml(route('midia', $message))
            ->assertSeeHtml('aria-label="Velocidade de reprodução"')
            ->assertSeeHtml('role="slider"')
            ->assertDontSeeHtml('<audio controls');
    }

    public function test_video_aparece_com_controles(): void
    {
        $this->screen(['media_ref' => 'a.mp4', 'media_mime' => 'video/mp4'])->assertSeeHtml('<video controls');
    }

    public function test_documento_vira_link_de_download(): void
    {
        $this->screen(['media_ref' => 'a.pdf', 'media_mime' => 'application/pdf', 'media_name' => 'orcamento.pdf'])
            ->assertSeeHtml('download')
            ->assertSee('orcamento.pdf');
    }

    public function test_midia_antiga_sem_referencia_mostra_indisponivel(): void
    {
        $this->screen(['media_ref' => null, 'created_at' => now()->subMinutes(10)])->assertSee('Mídia indisponível');
    }

    public function test_midia_recente_sem_referencia_mostra_carregando(): void
    {
        $this->screen(['media_ref' => null])->assertSee('Carregando mídia')->assertDontSee('Mídia indisponível');
    }

    public function test_visualizacao_unica_mostra_o_aviso(): void
    {
        $this->screen(['type' => 'view_once'])->assertSee('Mensagem de visualização única')->assertSee('peça ao cliente que reenvie');
    }

    public function test_aviso_de_visualizacao_unica_nomeia_o_tipo_da_midia(): void
    {
        $this->screen(['type' => 'view_once', 'media_mime' => 'image/jpeg'])->assertSee('Foto de visualização única');
    }

    public function test_figurinha_aparece_menor_que_uma_foto(): void
    {
        $this->screen(['media_ref' => 's.webp', 'media_mime' => 'image/webp'])->assertSeeHtml('max-h-40')->assertDontSeeHtml('max-h-72');
    }

    public function test_localizacao_vira_link_para_o_mapa_com_o_nome_do_local(): void
    {
        $this->screen(['type' => 'location', 'body' => '-21.17,-47.81', 'media_name' => 'Loja Centro'])
            ->assertSeeHtml('https://www.google.com/maps?q=-21.17%2C-47.81')
            ->assertSee('Loja Centro');
    }

    public function test_documento_mostra_o_nome_original(): void
    {
        $this->screen(['media_ref' => 'abc.pdf', 'media_mime' => 'application/pdf', 'media_name' => 'Orçamento outubro.pdf'])->assertSee('Orçamento outubro.pdf');
    }

    public function test_legenda_continua_visivel_junto_da_midia(): void
    {
        $this->screen(['body' => 'segue a foto', 'media_ref' => 'a.jpg', 'media_mime' => 'image/jpeg'])->assertSee('segue a foto');
    }
}
