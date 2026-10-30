<?php

namespace Tests\Feature\Alertas;

use App\Enums\UserRole;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Alerts\AlertRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private Sector $x;

    private Sector $y;

    protected function setUp(): void
    {
        parent::setUp();
        $this->x = Sector::factory()->create();
        $this->y = Sector::factory()->create();
    }

    private function atendente(Sector $sector, bool $active = true): User
    {
        $user = User::factory()->create(['role' => UserRole::ATENDENTE, 'active' => $active]);
        $user->sectors()->attach($sector);

        return $user;
    }

    /** @return array<int, int> */
    private function ids(iterable $users): array
    {
        return collect($users)->pluck('id')->sort()->values()->all();
    }

    public function test_fila_vai_so_para_o_setor_e_quem_ve_tudo(): void
    {
        $doX = $this->atendente($this->x);
        $this->atendente($this->y);
        $this->atendente($this->x, active: false);
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $ticket = Ticket::factory()->create(['sector_id' => $this->x->id]);

        $this->assertSame($this->ids([$doX, $supervisor]), $this->ids(app(AlertRecipients::class)->queue($ticket)));
    }

    public function test_sla_com_responsavel_avisa_ele_e_a_supervisao_sem_repetir(): void
    {
        $dono = $this->atendente($this->x);
        $this->atendente($this->x);
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        User::factory()->create(['role' => UserRole::ADMIN, 'active' => false]);
        $ticket = Ticket::factory()->create(['sector_id' => $this->x->id, 'assigned_user_id' => $dono->id]);

        $this->assertSame($this->ids([$dono, $supervisor, $admin]), $this->ids(app(AlertRecipients::class)->sla($ticket)));
    }

    public function test_sla_sem_responsavel_avisa_quem_ve_a_fila_e_a_supervisao(): void
    {
        $doX = $this->atendente($this->x);
        $this->atendente($this->y);
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $ticket = Ticket::factory()->create(['sector_id' => $this->x->id, 'assigned_user_id' => null]);

        $this->assertSame($this->ids([$doX, $supervisor]), $this->ids(app(AlertRecipients::class)->sla($ticket)));
    }

    public function test_todos_os_ativos_para_a_conexao(): void
    {
        $a = $this->atendente($this->x);
        $this->atendente($this->x, active: false);

        $this->assertSame([$a->id], $this->ids(app(AlertRecipients::class)->everyone()));
    }
}
