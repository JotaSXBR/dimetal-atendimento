<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SendTicketMessageJob;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\QuickReply;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** O botão "Respostas rápidas" do compositor: lista as do usuário e entrega o texto ao Alpine, que o insere no cursor. */
class InboxQuickRepliesButtonTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

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

    public function test_o_botao_esta_na_linha_de_ferramentas_entre_o_anexo_e_o_campo(): void
    {
        $html = $this->inbox()->html();

        $anexo = strpos($html, 'aria-label="Anexar arquivo"');
        $respostas = strpos($html, 'aria-label="Respostas rápidas"');
        $campo = strpos($html, 'aria-label="Mensagem"');

        $this->assertNotFalse($respostas);
        $this->assertGreaterThan($anexo, $respostas);
        $this->assertLessThan($campo, $respostas);
    }

    public function test_o_botao_abre_a_lista_so_com_as_respostas_do_usuario_e_as_de_todos_os_setores(): void
    {
        $outroSetor = Sector::factory()->create(['name' => 'Financeiro']);
        QuickReply::query()->create(['shortcut' => 'ola', 'body' => 'Olá, tudo bem?']);
        QuickReply::query()->create(['shortcut' => 'boleto', 'body' => 'Segue o boleto', 'sector_id' => $outroSetor->id]);

        $this->inbox()->assertDontSee('Olá, tudo bem?')
            ->call('toggleQuickReplies')
            ->assertSet('showQuickReplies', true)
            ->assertSee('Olá, tudo bem?')
            ->assertSee('/ola')
            ->assertDontSee('Segue o boleto');
    }

    public function test_cada_resposta_entrega_o_texto_ao_alpine_sem_chamar_o_servidor(): void
    {
        QuickReply::query()->create(['shortcut' => 'ola', 'body' => "Olá!\nTudo bem?"]);

        $html = $this->inbox()->call('toggleQuickReplies')->html();

        $this->assertStringContainsString('data-body="'.e("Olá!\nTudo bem?").'"', $html);
        $this->assertStringNotContainsString('wire:click="useQuickReply', substr($html, (int) strpos($html, 'data-testid="quick-replies-popover"')), 'a inserção é no cliente, no cursor');
    }

    public function test_lista_vazia_orienta_a_cadastrar(): void
    {
        $this->inbox()->call('toggleQuickReplies')->assertSee('Nenhuma resposta rápida')->assertSee('Cadastre em Configuração');
    }

    public function test_abrir_e_escolher_nao_envia_mensagem(): void
    {
        QuickReply::query()->create(['shortcut' => 'ola', 'body' => 'Olá']);

        $this->inbox()->call('toggleQuickReplies')->call('toggleQuickReplies');

        $this->assertSame(0, Message::query()->where('direction', 'out')->count());
        Queue::assertNotPushed(SendTicketMessageJob::class);
    }

    public function test_esc_fecha_a_lista_e_devolve_o_foco_ao_campo(): void
    {
        $html = $this->inbox()->html();

        // O rodapé escuta `composer-focus` e foca o campo; ao fechar com Esc a lista dispara esse evento (resources/js/composer-mode.js).
        $this->assertStringContainsString('x-on:composer-focus="focusField()"', $html);
        $this->assertStringContainsString('x-on:keydown.escape.window="closeQuickReplies()"', $html);
        $this->assertStringNotContainsString('emoji-closed', $html);
    }

    public function test_trocar_de_ticket_fecha_a_lista_de_respostas_rapidas(): void
    {
        $outro = Ticket::factory()->create([
            'channel_id' => $this->ticket->channel_id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);

        $this->inbox()->call('toggleQuickReplies')->assertSet('showQuickReplies', true)
            ->call('select', $outro->id)->assertSet('showQuickReplies', false);
    }

    public function test_os_gatilhos_da_lista_declaram_um_popup_generico_e_nao_um_listbox(): void
    {
        $html = $this->inbox()->html();

        // A lista é de botões comuns (cada um insere um texto), não um `listbox` com opções selecionáveis.
        foreach (['Respostas rápidas', 'Mais ações do compositor'] as $label) {
            $this->assertSame(1, preg_match('/<button[^>]*aria-label="'.preg_quote($label, '/').'"[^>]*>/', $html, $m), $label);
            $this->assertStringContainsString('aria-haspopup="true"', $m[0], $label);
        }
    }

    public function test_o_atalho_barra_continua_listando_sugestoes(): void
    {
        QuickReply::query()->create(['shortcut' => 'ola', 'body' => 'Olá, tudo bem?']);

        $this->inbox()->set('draft', '/o')->assertSee('/ola')->assertSee('Olá, tudo bem?');
    }
}
