<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Settings\Retention;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class RetentionPageTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private function attachment(string $path, int $size, int $ageDays): Message
    {
        Storage::disk('local')->put($path, 'x');

        $message = Message::factory()->for($this->ticket)->create(['type' => 'media', 'local_path' => $path, 'media_size' => $size]);
        $message->forceFill(['created_at' => now()->subDays($ageDays)])->saveQuietly();

        return $message;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->ticket = Ticket::factory()->create();
    }

    public function test_so_admin_entra(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->get('/configuracoes/retencao')->assertForbidden();
        $this->actingAs($this->admin())->get('/configuracoes/retencao')->assertOk();
    }

    public function test_mostra_prazo_espaco_usado_e_o_que_ja_venceu(): void
    {
        $this->attachment('outbound/novo.jpg', 2_000_000, 5);
        $this->attachment('outbound/velho.pdf', 3_000_000, 120);
        Message::factory()->for($this->ticket)->create(['type' => 'media', 'local_path' => null, 'media_size' => 9_000_000]);

        Livewire::actingAs($this->admin())->test(Retention::class)
            ->assertSee('90 dias')
            ->assertSee('2 anexos')
            ->assertSee('5,0 MB')
            ->assertSee('1 já passou do prazo');
    }

    public function test_executar_o_expurgo_agora_apaga_so_o_vencido_e_mostra_o_resultado(): void
    {
        $new = $this->attachment('outbound/novo.jpg', 2_000_000, 5);
        $old = $this->attachment('outbound/velho.pdf', 3_000_000, 120);

        Livewire::actingAs($this->admin())->test(Retention::class)->call('purgeNow')->assertSee('Expurgo iniciado');

        $this->assertNull($old->fresh()?->local_path);
        Storage::disk('local')->assertMissing('outbound/velho.pdf');
        $this->assertSame('outbound/novo.jpg', $new->fresh()?->local_path);
        Storage::disk('local')->assertExists('outbound/novo.jpg');
    }

    public function test_o_expurgo_manual_vai_para_a_fila_e_nao_roda_no_request(): void
    {
        Queue::fake();
        $old = $this->attachment('outbound/velho.pdf', 3_000_000, 120);

        Livewire::actingAs($this->admin())->test(Retention::class)->call('purgeNow');

        Queue::assertPushed(QueuedCommand::class);
        $this->assertSame('outbound/velho.pdf', $old->fresh()?->local_path);
    }

    public function test_quem_nao_e_admin_nao_executa(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->test(Retention::class)->assertForbidden();
    }
}
