<?php

namespace Tests\Feature\Livewire;

use App\Enums\MessageDirection;
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

    /** @return list<string> Os modos de posição da hora, um por bolha. */
    private function timeModes(array $attributes): array
    {
        preg_match_all('/data-testid="message-time"[^>]*data-time-mode="([a-z]+)"/', $this->screen($attributes)->html(), $m);

        return $m[1];
    }

    /** @return list<string> As classes do trecho da hora, uma por bolha. */
    private function timeClasses(array $attributes): array
    {
        preg_match_all('/<span data-testid="message-time"[^>]*class="([^"]*)"/', $this->screen($attributes)->html(), $m);

        return $m[1];
    }

    /**
     * No modo `inline` a hora vive dentro de um `<p class="whitespace-pre-wrap">`: sem `whitespace-nowrap` ela herda o `pre-wrap` e as
     * quebras de linha e os recuos do Blade viram espaço de verdade (a hora sobe, os tiques ficam no meio e aparece um vão entre os dois).
     */
    public function test_a_hora_nao_herda_o_pre_wrap_do_texto_em_nenhum_modo(): void
    {
        foreach ([
            ['type' => 'text', 'body' => 'Olá', 'direction' => MessageDirection::OUT],
            ['media_ref' => 'a.jpg', 'media_mime' => 'image/jpeg'],
            ['media_ref' => 'a.ogg', 'media_mime' => 'audio/ogg'],
        ] as $attributes) {
            $classes = $this->timeClasses($attributes);

            $this->assertNotSame([], $classes);
            $this->assertStringContainsString('whitespace-nowrap', $classes[array_key_last($classes)]);
        }
    }

    public function test_texto_tem_hora_inline(): void
    {
        $this->assertSame(['inline'], $this->timeModes(['type' => 'text', 'body' => 'Olá']));
    }

    public function test_foto_com_legenda_tem_hora_inline_no_fim_da_legenda(): void
    {
        $this->assertSame(['inline'], $this->timeModes(['body' => 'Segue a foto', 'media_ref' => 'a.jpg', 'media_mime' => 'image/jpeg']));
    }

    public function test_foto_sem_legenda_tem_hora_sobre_a_imagem(): void
    {
        $this->assertSame(['overlay'], $this->timeModes(['media_ref' => 'a.jpg', 'media_mime' => 'image/jpeg']));
    }

    public function test_video_sem_legenda_tem_hora_sobre_o_video(): void
    {
        $this->assertSame(['overlay'], $this->timeModes(['media_ref' => 'a.mp4', 'media_mime' => 'video/mp4']));
    }

    public function test_audio_tem_a_hora_na_linha_da_duracao_e_documento_abaixo(): void
    {
        $this->assertSame(['audio'], $this->timeModes(['media_ref' => 'a.ogg', 'media_mime' => 'audio/ogg']));
        // A segunda chamada soma uma bolha à mesma conversa: áudio e documento.
        $this->assertSame(['audio', 'below'], $this->timeModes(['media_ref' => 'a.pdf', 'media_mime' => 'application/pdf', 'media_name' => 'a.pdf']));
    }

    public function test_a_hora_do_audio_existe_uma_vez_e_dentro_da_bolha_do_audio(): void
    {
        $html = $this->screen(['media_ref' => 'a.ogg', 'media_mime' => 'audio/ogg', 'direction' => MessageDirection::OUT])->html();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $this->assertSame(1, $xpath->query('//*[@data-testid="message-time"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-testid="audio-bubble"]//*[@data-testid="message-time"]')->length);
    }

    public function test_visualizacao_unica_com_mime_de_imagem_ainda_mostra_a_hora(): void
    {
        $this->assertSame(['below'], $this->timeModes(['type' => 'view_once', 'media_ref' => 'a.jpg', 'media_mime' => 'image/jpeg']));
    }

    public function test_recebida_nao_tem_ticks(): void
    {
        $html = $this->screen(['type' => 'text', 'body' => 'Oi', 'direction' => MessageDirection::IN])->html();

        $this->assertDoesNotMatchRegularExpression('/aria-label="(Lida|Entregue|Enviada|Enviando)"/', $html);
    }

    public function test_imagem_aparece_como_img_com_a_rota_da_midia(): void
    {
        $message = Message::factory()->for($this->ticket)->create(['type' => 'media', 'body' => '', 'media_ref' => 'a.jpg', 'media_mime' => 'image/jpeg']);

        Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id)
            ->assertSeeHtml('<img')
            ->assertSeeHtml(route('midia', $message));
    }

    public function test_foto_abre_em_previa_na_frente_do_app_sem_nova_aba(): void
    {
        $this->screen(['media_ref' => 'a.jpg', 'media_mime' => 'image/jpeg'])
            ->assertSeeHtml('data-photo-preview')
            ->assertDontSeeHtml('data-photo-box href="')
            ->assertSeeHtml('x-teleport="body"');
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
        $this->screen(['media_ref' => 'a.zip', 'media_mime' => 'application/zip', 'media_name' => 'orcamento.zip'])
            ->assertSeeHtml('download')
            ->assertSee('orcamento.zip');
    }

    public function test_documento_vira_cartao_com_icone_nome_tipo_e_tamanho(): void
    {
        $this->screen(['media_ref' => 'c.docx', 'media_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'media_name' => 'COTAÇÃO DIMETAL.docx', 'media_size' => 22528])
            ->assertSeeHtml('data-document-card')
            ->assertSee('COTAÇÃO DIMETAL.docx')
            ->assertSee('DOCX · 22 KB')
            ->assertSeeHtml('bg-blue-600');
    }

    public function test_cartao_de_pdf_tem_so_abrir_em_nova_aba(): void
    {
        $message = Message::factory()->for($this->ticket)->create(['type' => 'media', 'body' => '', 'media_ref' => 'a.pdf', 'media_mime' => 'application/pdf', 'media_name' => 'orcamento.pdf']);
        $url = route('midia', $message);

        Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id)
            ->assertSeeHtml('href="'.$url.'" target="_blank" rel="noopener"')
            ->assertSee('Abrir')
            ->assertDontSee('Salvar como');
    }

    public function test_cartao_de_documento_que_o_navegador_nao_abre_tem_so_baixar_com_o_nome(): void
    {
        $message = Message::factory()->for($this->ticket)->create(['type' => 'media', 'body' => '', 'media_ref' => 'c.docx', 'media_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'media_name' => 'COTACAO.docx']);
        $url = route('midia', $message);

        Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id)
            ->assertSeeHtml('href="'.$url.'" download="')
            ->assertSee('Baixar')
            ->assertDontSee('Abrir')
            ->assertDontSee('Salvar como');
    }

    public function test_documento_sem_nome_sem_tamanho_e_sem_extensao_usa_padroes(): void
    {
        $this->screen(['media_ref' => 'x.bin', 'media_mime' => 'application/octet-stream', 'media_name' => null, 'media_size' => null])
            ->assertSee('Arquivo')
            ->assertSeeHtml('data-document-card')
            ->assertDontSeeHtml('data-document-meta')
            ->assertDontSee('0 KB');
    }

    public function test_nome_do_documento_sai_escapado(): void
    {
        $this->screen(['media_ref' => 'x.pdf', 'media_mime' => 'application/pdf', 'media_name' => '<b>x</b>.pdf'])
            ->assertDontSeeHtml('<b>x</b>')
            ->assertSeeHtml('&lt;b&gt;x&lt;/b&gt;.pdf');
    }

    public function test_pdf_e_planilha_usam_a_etiqueta_certa(): void
    {
        $this->screen(['media_ref' => 'a.pdf', 'media_mime' => 'application/pdf', 'media_name' => 'a.pdf'])->assertSeeHtml('bg-red-600');
        $this->screen(['media_ref' => 'a.xlsx', 'media_mime' => 'application/octet-stream', 'media_name' => 'dados.xlsx'])->assertSeeHtml('bg-green-600');
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

    public function test_foto_tem_largura_fixa_e_altura_maxima_com_corte(): void
    {
        $this->screen(['media_ref' => 'f.jpg', 'media_mime' => 'image/jpeg'])
            ->assertSeeHtml('data-photo-box')
            ->assertSeeHtml('w-72 max-w-full')
            ->assertSeeHtml('max-h-80 w-full object-cover')
            ->assertDontSeeHtml('max-h-72');
    }

    public function test_figurinha_nao_ganha_a_caixa_da_foto(): void
    {
        $this->screen(['media_ref' => 's.webp', 'media_mime' => 'image/webp'])->assertDontSeeHtml('data-photo-box')->assertSeeHtml('max-h-40');
    }

    public function test_figurinha_aparece_menor_que_uma_foto(): void
    {
        $this->screen(['media_ref' => 's.webp', 'media_mime' => 'image/webp'])->assertSeeHtml('max-h-40')->assertDontSeeHtml('max-h-72');
    }

    public function test_localizacao_e_um_cartao_de_mapa_que_abre_o_google_maps(): void
    {
        $this->screen(['type' => 'location', 'body' => '-21.17,-47.81', 'media_name' => 'Loja Centro'])
            ->assertSeeHtml('data-location-card')
            ->assertSeeHtml('https://www.google.com/maps/search/?api=1&amp;query=-21.17%2C-47.81')
            ->assertSee('Loja Centro')
            ->assertSee('Abrir no Google Maps')
            ->assertDontSee('-21.17,-47.81');
    }

    public function test_localizacao_sem_nome_mostra_titulo_padrao(): void
    {
        $this->screen(['type' => 'location', 'body' => '-21.17,-47.81'])->assertSee('Localização');
    }

    public function test_contato_e_um_cartao_com_iniciais_telefone_e_botoes_para_conversar(): void
    {
        $this->screen(['type' => 'contact', 'body' => 'Contato: Maria Souza (+55 16 99999-0001)
Contato: Beto (+55 11 90000-0002, +55 11 3000-0000)'])
            ->assertSeeHtml('data-contact-card')
            ->assertSee('Maria Souza')
            ->assertSee('MS')
            ->assertSee('+55 16 99999-0001')
            ->assertSee('Beto')
            ->assertSee('+55 11 3000-0000')
            ->assertSee('Adicionar aos contatos')
            ->assertSee('Conversar')
            ->assertDontSeeHtml('wa.me')
            ->assertDontSee('Contato: Maria');
    }

    public function test_contato_sem_telefone_nao_tem_link_para_conversar(): void
    {
        $this->screen(['type' => 'contact', 'body' => 'Contato: Sem Fone'])
            ->assertSee('Sem Fone')
            ->assertDontSeeHtml('wa.me');
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
