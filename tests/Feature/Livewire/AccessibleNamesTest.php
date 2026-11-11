<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Automation\Editor;
use App\Livewire\Contacts\Index as ContactsIndex;
use App\Livewire\History\Index as HistoryIndex;
use App\Livewire\Settings\ChannelCard;
use App\Livewire\Settings\Company;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Todo campo e todo botão só com ícone ou símbolo tem nome acessível e alvo de pelo menos 32 px (auditoria de UX). */
class AccessibleNamesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    }

    /** A tag `<input ...>` que traz o texto de espaço reservado pedido. */
    private function inputTag(string $html, string $placeholder): string
    {
        $this->assertSame(1, preg_match('/<input[^>]*placeholder="'.preg_quote($placeholder, '/').'"[^>]*>/', $html, $m), "falta o campo {$placeholder}");

        return $m[0];
    }

    public function test_busca_de_contatos_tem_nome_acessivel(): void
    {
        $html = Livewire::actingAs($this->admin)->test(ContactsIndex::class)->html();

        $this->assertStringContainsString('aria-label="Buscar contatos"', $this->inputTag($html, 'Buscar por nome, telefone, e-mail ou empresa'));
    }

    public function test_busca_do_historico_tem_nome_acessivel(): void
    {
        $html = Livewire::actingAs($this->admin)->test(HistoryIndex::class)->html();

        $this->assertStringContainsString('aria-label="Buscar no histórico"', $this->inputTag($html, 'Contato, protocolo ou texto'));
    }

    public function test_campo_de_dias_da_importacao_tem_nome_acessivel(): void
    {
        $channel = Channel::factory()->connected()->create();

        $html = Livewire::actingAs($this->admin)->test(ChannelCard::class, ['channel' => $channel])->html();

        $this->assertMatchesRegularExpression('/<input[^>]*type="number"[^>]*aria-label="Quantos dias importar"|<input[^>]*aria-label="Quantos dias importar"[^>]*type="number"/', $html);
    }

    public function test_botoes_do_editor_da_assinatura_tem_alvo_de_32px_e_aria_label(): void
    {
        $html = Livewire::actingAs($this->admin)->test(Company::class)->html();

        foreach (['Negrito', 'Itálico', 'Tachado', 'Monoespaçado'] as $label) {
            $this->assertSame(1, preg_match('/<button[^>]*aria-label="'.$label.'"[^>]*>/', $html, $m), "falta {$label}");
            $this->assertStringContainsString('h-8', $m[0], "{$label} precisa de alvo de 32 px (size sm)");
        }
    }

    public function test_o_link_voltar_do_editor_de_automacao_tem_alvo_de_32px(): void
    {
        $html = Livewire::actingAs($this->admin)->test(Editor::class, ['workflow' => Workflow::factory()->create()])->html();

        $this->assertSame(1, preg_match('/<a[^>]*>← Automação<\/a>/', $html, $m));
        $this->assertStringContainsString('min-h-8', $m[0]);
    }
}
