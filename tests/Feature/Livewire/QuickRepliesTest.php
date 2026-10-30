<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Settings\QuickReplies;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\QuickReply;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuickRepliesTest extends TestCase
{
    use RefreshDatabase;

    private function page(UserRole $role = UserRole::SUPERVISOR): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => $role]))->test(QuickReplies::class);
    }

    public function test_so_supervisor_e_admin_configuram(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/configuracoes/respostas-rapidas')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->get('/configuracoes/respostas-rapidas')->assertOk();
    }

    public function test_cria_edita_e_apaga(): void
    {
        $sector = Sector::factory()->create();

        $this->page()->call('newReply')->set('shortcut', '/Preco')->set('body', 'Tabela de precos')->set('sectorId', $sector->id)->call('save')->assertHasNoErrors();

        $reply = QuickReply::query()->sole();
        $this->assertSame('preco', $reply->shortcut, 'a barra inicial e as maiúsculas saem');
        $this->assertSame($sector->id, $reply->sector_id);

        $this->page()->call('edit', $reply->id)->set('body', 'Nova tabela')->set('sectorId', null)->call('save')->assertHasNoErrors();
        $this->assertSame('Nova tabela', $reply->fresh()?->body);
        $this->assertNull($reply->fresh()?->sector_id);

        $this->page()->call('delete', $reply->id);
        $this->assertSame(0, QuickReply::query()->count());
    }

    /** @return array<string, array{string, string}> */
    public static function entradasInvalidas(): array
    {
        return [
            'atalho vazio' => ['shortcut', ''],
            'atalho com espaco' => ['shortcut', 'meu atalho'],
            'atalho com acento' => ['shortcut', 'preço'],
            'atalho longo' => ['shortcut', str_repeat('a', 31)],
            'texto vazio' => ['body', ''],
            'texto longo' => ['body', str_repeat('a', 1001)],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_validacao(string $field, string $value): void
    {
        $this->page()->call('newReply')->set('shortcut', 'ok')->set('body', 'texto')->set($field, $value)->call('save')->assertHasErrors([$field]);
    }

    public function test_atalho_repetido_e_recusado(): void
    {
        QuickReply::query()->create(['shortcut' => 'preco', 'body' => 'x']);

        $this->page()->call('newReply')->set('shortcut', 'PRECO')->set('body', 'y')->call('save')->assertHasErrors(['shortcut']);
    }

    private function sendBox(User $user): Testable
    {
        $ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $user->id,
        ]);

        return Livewire::actingAs($user)->test(Inbox::class)->set('ticketId', $ticket->id);
    }

    public function test_a_lista_do_composer_mostra_so_as_do_setor_do_usuario_e_as_de_todos(): void
    {
        $mine = Sector::factory()->create();
        $other = Sector::factory()->create();
        $user = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $user->sectors()->attach($mine);
        QuickReply::query()->create(['shortcut' => 'preco', 'body' => 'Tabela do meu setor', 'sector_id' => $mine->id]);
        QuickReply::query()->create(['shortcut' => 'prazo', 'body' => 'Prazo de todos']);
        QuickReply::query()->create(['shortcut' => 'prem', 'body' => 'Segredo do outro setor', 'sector_id' => $other->id]);
        QuickReply::query()->create(['shortcut' => 'horario', 'body' => 'Nao comeca com pr']);

        $this->sendBox($user)->set('draft', '/pr')
            ->assertSee('Tabela do meu setor')->assertSee('Prazo de todos')
            ->assertDontSee('Segredo do outro setor')->assertDontSee('Nao comeca com pr');
    }

    public function test_rascunho_com_espaco_ou_sem_barra_nao_lista_nada(): void
    {
        QuickReply::query()->create(['shortcut' => 'preco', 'body' => 'Tabela']);
        $user = User::factory()->create(['role' => UserRole::ATENDENTE]);

        $box = $this->sendBox($user);

        $box->set('draft', '/preco agora')->assertDontSee('Tabela');
        $box->set('draft', 'preco')->assertDontSee('Tabela');
    }

    public function test_a_lista_traz_no_maximo_6(): void
    {
        foreach (range(1, 8) as $n) {
            QuickReply::query()->create(['shortcut' => 'a'.$n, 'body' => 'Corpo numero '.$n]);
        }

        $this->assertCount(6, QuickReply::forUser(User::factory()->create(['role' => UserRole::SUPERVISOR]))->matching('/a')->get());
    }

    public function test_usar_a_resposta_poe_o_texto_no_rascunho_editavel(): void
    {
        $reply = QuickReply::query()->create(['shortcut' => 'preco', 'body' => 'Tabela de precos']);

        $this->sendBox(User::factory()->create(['role' => UserRole::ATENDENTE]))->set('draft', '/pre')->call('useQuickReply', $reply->id)->assertSet('draft', 'Tabela de precos');
    }

    public function test_nao_usa_resposta_de_outro_setor(): void
    {
        $other = Sector::factory()->create();
        $reply = QuickReply::query()->create(['shortcut' => 'prem', 'body' => 'Segredo', 'sector_id' => $other->id]);

        $this->sendBox(User::factory()->create(['role' => UserRole::ATENDENTE]))
            ->set('draft', '/prem')
            ->call('useQuickReply', $reply->id)
            ->assertNotFound();
    }
}
