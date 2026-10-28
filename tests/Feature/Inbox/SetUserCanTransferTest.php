<?php

namespace Tests\Feature\Inbox;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetUserCanTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_desliga_e_liga_a_opcao_pelo_email(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->fresh()?->can_transfer);

        $this->artisan('app:user-can-transfer', ['email' => $user->email, 'state' => 'off'])->assertSuccessful();
        $this->assertFalse($user->fresh()?->can_transfer);

        $this->artisan('app:user-can-transfer', ['email' => $user->email, 'state' => 'on'])->assertSuccessful();
        $this->assertTrue($user->fresh()?->can_transfer);
    }

    public function test_recusa_estado_invalido_e_usuario_inexistente(): void
    {
        $user = User::factory()->create();

        $this->artisan('app:user-can-transfer', ['email' => $user->email, 'state' => 'talvez'])->assertFailed();
        $this->artisan('app:user-can-transfer', ['email' => 'ninguem@example.com', 'state' => 'off'])->assertFailed();

        $this->assertTrue($user->fresh()?->can_transfer);
    }
}
