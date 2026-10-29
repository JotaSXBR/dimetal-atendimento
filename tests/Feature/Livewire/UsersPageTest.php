<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\Users;
use App\Models\Channel;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UsersPageTest extends TestCase
{
    use RefreshDatabase;

    private function as(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_atendente_nao_entra(): void
    {
        $this->actingAs($this->as(UserRole::ATENDENTE))->get('/configuracoes/usuarios')->assertForbidden();
    }

    public function test_supervisor_e_admin_entram(): void
    {
        $this->actingAs($this->as(UserRole::SUPERVISOR))->get('/configuracoes/usuarios')->assertOk();
        $this->actingAs($this->as(UserRole::ADMIN))->get('/configuracoes/usuarios')->assertOk();
    }

    public function test_supervisor_cria_atendente_com_setores_e_permissao_de_transferir(): void
    {
        $sector = Sector::factory()->create();

        Livewire::actingAs($this->as(UserRole::SUPERVISOR))->test(Users::class)
            ->call('newUser')
            ->set('name', 'Ana Atendente')
            ->set('email', 'ana@dimetal.com.br')
            ->set('password', 'senha-boa-123')
            ->set('sectorIds', [$sector->id])
            ->set('canTransfer', true)
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'ana@dimetal.com.br')->sole();
        $this->assertSame(UserRole::ATENDENTE, $user->role);
        $this->assertTrue($user->active);
        $this->assertTrue($user->can_transfer);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('senha-boa-123', $user->password));
        $this->assertSame([$sector->id], $user->sectors()->pluck('sectors.id')->all());
    }

    public function test_admin_cria_supervisor(): void
    {
        Livewire::actingAs($this->as(UserRole::ADMIN))->test(Users::class)
            ->call('newUser')
            ->set('name', 'Sergio Supervisor')
            ->set('email', 'sergio@dimetal.com.br')
            ->set('role', 'supervisor')
            ->set('password', 'senha-boa-123')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(UserRole::SUPERVISOR, User::query()->where('email', 'sergio@dimetal.com.br')->sole()->role);
    }

    public function test_supervisor_forjando_o_papel_admin_leva_403_e_nada_e_criado(): void
    {
        Livewire::actingAs($this->as(UserRole::SUPERVISOR))->test(Users::class)
            ->call('newUser')
            ->set('name', 'Intruso')
            ->set('email', 'intruso@dimetal.com.br')
            ->set('role', 'admin')
            ->set('password', 'senha-boa-123')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(0, User::query()->where('email', 'intruso@dimetal.com.br')->count());
    }

    public function test_supervisor_nao_abre_para_editar_admin_nem_supervisor(): void
    {
        $actor = $this->as(UserRole::SUPERVISOR);

        Livewire::actingAs($actor)->test(Users::class)->call('edit', $this->as(UserRole::ADMIN)->id)->assertForbidden();
        Livewire::actingAs($actor)->test(Users::class)->call('edit', $this->as(UserRole::SUPERVISOR)->id)->assertForbidden();
    }

    public function test_supervisor_nao_promove_um_atendente_forjando_o_papel(): void
    {
        $actor = $this->as(UserRole::SUPERVISOR);
        $target = $this->as(UserRole::ATENDENTE);

        Livewire::actingAs($actor)->test(Users::class)
            ->call('edit', $target->id)
            ->set('role', 'admin')
            ->call('save')
            ->assertForbidden();

        $this->assertSame(UserRole::ATENDENTE, $target->fresh()?->role);
    }

    public function test_email_duplicado_e_senha_curta_mostram_erro(): void
    {
        $existing = $this->as(UserRole::ATENDENTE);

        Livewire::actingAs($this->as(UserRole::SUPERVISOR))->test(Users::class)
            ->call('newUser')
            ->set('name', 'Dup')
            ->set('email', $existing->email)
            ->set('password', 'curta')
            ->call('save')
            ->assertHasErrors(['email', 'password']);
    }

    public function test_editar_sem_senha_mantem_a_senha_e_troca_setores_e_nome(): void
    {
        $target = User::factory()->create(['role' => UserRole::ATENDENTE, 'password' => 'senha-antiga-1']);
        $old = Sector::factory()->create();
        $new = Sector::factory()->create();
        $target->sectors()->attach($old);
        $hash = $target->password;

        Livewire::actingAs($this->as(UserRole::SUPERVISOR))->test(Users::class)
            ->call('edit', $target->id)
            ->set('name', 'Novo Nome')
            ->set('sectorIds', [$new->id])
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $target->fresh();
        $this->assertSame('Novo Nome', $fresh?->name);
        $this->assertSame($hash, $fresh?->password);
        $this->assertSame([$new->id], $fresh?->sectors()->pluck('sectors.id')->all());
    }

    public function test_senha_nova_na_edicao_troca_a_senha(): void
    {
        $target = $this->as(UserRole::ATENDENTE);

        Livewire::actingAs($this->as(UserRole::SUPERVISOR))->test(Users::class)
            ->call('edit', $target->id)
            ->set('password', 'outra-senha-456')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('outra-senha-456', (string) $target->fresh()?->password));
    }

    public function test_desativar_apaga_as_sessoes_da_pessoa_e_mantem_os_tickets(): void
    {
        $target = $this->as(UserRole::ATENDENTE);
        $ticket = Ticket::factory()->create(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $target->id]);
        DB::table('sessions')->insert([
            ['id' => 's1', 'user_id' => $target->id, 'payload' => 'x', 'last_activity' => time()],
            ['id' => 's2', 'user_id' => $this->as(UserRole::ATENDENTE)->id, 'payload' => 'x', 'last_activity' => time()],
        ]);

        Livewire::actingAs($this->as(UserRole::SUPERVISOR))->test(Users::class)->call('toggleActive', $target->id);

        $this->assertFalse((bool) $target->fresh()?->active);
        $this->assertSame(['s2'], DB::table('sessions')->pluck('id')->all());
        $this->assertSame($target->id, $ticket->fresh()?->assigned_user_id);
    }

    public function test_reativar_nao_mexe_em_sessoes(): void
    {
        $target = User::factory()->create(['role' => UserRole::ATENDENTE, 'active' => false]);

        Livewire::actingAs($this->as(UserRole::SUPERVISOR))->test(Users::class)->call('toggleActive', $target->id);

        $this->assertTrue((bool) $target->fresh()?->active);
    }

    public function test_ninguem_desativa_a_si_mesmo(): void
    {
        $admin = $this->as(UserRole::ADMIN);
        $this->as(UserRole::ADMIN);

        Livewire::actingAs($admin)->test(Users::class)->call('toggleActive', $admin->id)->assertForbidden();

        $this->assertTrue((bool) $admin->fresh()?->active);
    }

    public function test_ultimo_admin_nao_se_rebaixa(): void
    {
        $admin = $this->as(UserRole::ADMIN);

        Livewire::actingAs($admin)->test(Users::class)
            ->call('edit', $admin->id)
            ->set('role', 'supervisor')
            ->call('save')
            ->assertHasErrors('role');

        $this->assertSame(UserRole::ADMIN, $admin->fresh()?->role);
    }

    public function test_a_lista_mostra_quantos_tickets_abertos_cada_pessoa_tem(): void
    {
        $target = User::factory()->create(['role' => UserRole::ATENDENTE, 'name' => 'Carlos Ocupado']);
        $channel = Channel::factory()->create();
        Ticket::factory()->count(2)->create(['channel_id' => $channel->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $target->id]);

        Livewire::actingAs($this->as(UserRole::SUPERVISOR))->test(Users::class)
            ->assertSee('Carlos Ocupado')
            ->assertSee('2 tickets abertos');
    }

    public function test_supervisor_nao_ve_botoes_de_edicao_de_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'name' => 'Chefe Admin']);

        Livewire::actingAs($this->as(UserRole::SUPERVISOR))->test(Users::class)
            ->assertSee('Chefe Admin')
            ->assertDontSeeHtml('wire:click="edit('.$admin->id.')"');
    }
}
