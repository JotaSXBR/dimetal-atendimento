<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** O seletor de visões da Fila: Todos, Aguardando e Meus fixos; Pendentes e Encerrados no menu "Mais". */
class InboxViewSwitchTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['role' => UserRole::ADMIN]);
        $channel = Channel::factory()->connected()->create();
        Ticket::factory()->count(2)->for($channel)->create(['status' => TicketStatus::AGUARDANDO]);
        Ticket::factory()->for($channel)->create(['status' => TicketStatus::PENDENTE]);
        Ticket::factory()->for($channel)->create(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $this->me->id]);
    }

    private function inbox(string $tab = 'todos'): Testable
    {
        return Livewire::actingAs($this->me)->test(Inbox::class)->set('tab', $tab);
    }

    private function text(Testable $component, string $testId): ?string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$component->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $node = (new \DOMXPath($dom))->query('//*[@data-testid="'.$testId.'"]')->item(0);

        return $node === null ? null : (string) preg_replace('/\s+/', ' ', trim($node->textContent));
    }

    private function html(Testable $component, string $testId): ?string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$component->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $node = (new \DOMXPath($dom))->query('//*[@data-testid="'.$testId.'"]')->item(0);

        return $node === null ? null : $dom->saveHTML($node);
    }

    public function test_tres_visoes_fixas_com_a_contagem_de_aguardando_e_meus(): void
    {
        $inbox = $this->inbox();

        $this->assertSame('Todos', $this->text($inbox, 'tab-todos'));
        $this->assertSame('Aguardando 2', $this->text($inbox, 'tab-aguardando'));
        $this->assertSame('Meus 1', $this->text($inbox, 'tab-meus'));
    }

    public function test_pendentes_e_encerrados_ficam_no_menu_mais(): void
    {
        $inbox = $this->inbox();

        $this->assertSame('Mais', $this->text($inbox, 'tab-more'));
        $this->assertSame('Pendentes 1', $this->text($inbox, 'tab-pendentes'));
        // A contagem vai no `suffix` do item (alinhada à direita), não colada ao nome.
        $this->assertStringContainsString('ms-auto', (string) $this->html($inbox, 'tab-pendentes'));
        $this->assertSame('Encerrados', $this->text($inbox, 'tab-encerrados'));
    }

    public function test_o_rotulo_acessivel_do_botao_mais_comeca_pelo_texto_visivel(): void
    {
        foreach (['todos', 'pendentes', 'encerrados'] as $tab) {
            $inbox = $this->inbox($tab);
            $visible = $this->text($inbox, 'tab-more');

            $this->assertSame(1, preg_match('/data-testid="tab-more"[^>]*aria-label="([^"]+)"|aria-label="([^"]+)"[^>]*data-testid="tab-more"/', $inbox->html(), $m), $tab);
            $label = $m[1] !== '' ? $m[1] : $m[2];

            $this->assertStringStartsWith((string) $visible, $label, "o nome acessível contém o texto visível ({$tab})");
        }
    }

    public function test_o_botao_mais_mostra_a_visao_escolhida_dentro_do_menu(): void
    {
        // Só o nome: com a contagem o botão não cabe ao lado das três visões (a contagem fica no menu e no título da seção).
        $this->assertSame('Pendentes', $this->text($this->inbox('pendentes'), 'tab-more'));
        $this->assertSame('Encerrados', $this->text($this->inbox('encerrados'), 'tab-more'));
    }
}
