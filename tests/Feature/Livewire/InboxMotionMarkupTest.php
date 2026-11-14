<?php

namespace Tests\Feature\Livewire;

use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Alerts\Bell;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Movimento dos painéis da inbox: o teste fixa os atributos no HTML (o movimento em si se confere no navegador).
 * Ver docs/superpowers/specs/2026-11-14-animacao-paineis-e-compositor-design.md.
 */
class InboxMotionMarkupTest extends TestCase
{
    use RefreshDatabase;

    private const ENTRADA = 'x-transition:enter="transition duration-150 ease-[cubic-bezier(0.23,1,0.32,1)]"';

    private const SAIDA = 'x-transition:leave="transition duration-100 ease-out"';

    private User $me;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
    }

    private function inbox(): Testable
    {
        return Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id);
    }

    private function mensagem(MessageDirection $direction): Message
    {
        return Message::factory()->for($this->ticket)->create(['direction' => $direction, 'wa_message_id' => strtoupper(bin2hex(random_bytes(6)))]);
    }

    /** O elemento (abertura da tag) que traz o atributo, para os asserts não pegarem o vizinho. */
    private function tagCom(string $html, string $marca): string
    {
        $at = strpos($html, $marca);
        $this->assertNotFalse($at, "falta {$marca}");

        return substr($html, strrpos(substr($html, 0, $at), '<') ?: 0, 1200);
    }

    public function test_o_menu_da_mensagem_tem_entrada_pelo_placed_e_saida_por_x_transition(): void
    {
        $this->mensagem(MessageDirection::IN);

        $menu = $this->tagCom($this->inbox()->html(), 'x-ref="menu"');

        $this->assertStringContainsString('x-bind:class="placed', $menu);
        $this->assertStringContainsString('duration-150 ease-[cubic-bezier(0.23,1,0.32,1)]', $menu);
        $this->assertStringContainsString(self::SAIDA, $menu);
    }

    public function test_o_sino_cresce_do_canto_superior_esquerdo(): void
    {
        $painel = $this->tagCom(Livewire::actingAs($this->me)->test(Bell::class)->html(), 'role="dialog" aria-label="Alertas"');

        $this->assertStringContainsString('origin-top-left', $painel);
        $this->assertStringContainsString(self::ENTRADA, $painel);
        $this->assertStringContainsString(self::SAIDA, $painel);
        $this->assertStringNotContainsString('x-transition.opacity', $painel);
    }

    public function test_o_emoji_sobe_do_botao(): void
    {
        $painel = $this->tagCom($this->inbox()->html(), 'aria-label="Seletor de emoji"');

        $this->assertStringContainsString('origin-bottom-left', $painel);
        $this->assertStringContainsString(self::ENTRADA, $painel);
        $this->assertStringContainsString(self::SAIDA, $painel);
    }

    public function test_a_barra_de_reacao_abre_do_lado_da_bolha(): void
    {
        $this->mensagem(MessageDirection::OUT);
        $this->mensagem(MessageDirection::IN);

        $html = $this->inbox()->html();
        preg_match_all('/<div x-cloak x-show="open"[^>]*aria-label="Reagir"[^>]*>/', $html, $barras);

        $this->assertCount(2, $barras[0]);
        $this->assertStringContainsString('origin-right', $barras[0][0]);
        $this->assertStringContainsString('origin-left', $barras[0][1]);

        foreach ($barras[0] as $barra) {
            $this->assertStringContainsString(self::ENTRADA, $barra);
            $this->assertStringContainsString(self::SAIDA, $barra);
        }
    }

    public function test_as_faixas_de_audio_abrem_pela_altura(): void
    {
        $html = $this->inbox()->html();

        foreach (['recording', 'preview'] as $fase) {
            $wrapper = $this->tagCom($html, "x-show=\"phase === '{$fase}'\"");

            $this->assertStringContainsString('x-collapse.duration.200ms', $wrapper, $fase);
            // A margem vai para dentro do wrapper: no wrapper ela sobraria como vão de 8px com a faixa fechada.
            $this->assertStringNotContainsString('mb-2', substr($wrapper, 0, (int) strpos($wrapper, '>')), $fase);
            $this->assertMatchesRegularExpression('/x-collapse\.duration\.200ms[^>]*>\s*<div class="mb-2 /', $wrapper, $fase);
        }
    }
}
