<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PurgeMediaTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->ticket = Ticket::factory()->for(Channel::factory()->connected()->create())->for(Contact::factory()->create())->create(['status' => TicketStatus::EM_ATENDIMENTO]);
    }

    private function attachment(string $path, int $ageDays): Message
    {
        Storage::disk('local')->put($path, 'conteudo');

        $message = Message::factory()->create([
            'ticket_id' => $this->ticket->id,
            'direction' => MessageDirection::OUT,
            'origin' => MessageOrigin::PAINEL,
            'type' => 'media',
            'body' => '',
            'media_mime' => 'application/pdf',
            'media_name' => 'orcamento.pdf',
            'local_path' => $path,
        ]);
        Message::query()->whereKey($message->id)->update(['created_at' => now()->subDays($ageDays)]);

        return $message->fresh() ?? $message;
    }

    public function test_apaga_so_o_que_passou_de_90_dias_e_registra_o_evento(): void
    {
        $old = $this->attachment('outbound/2026/07/velho.pdf', 91);
        $recent = $this->attachment('outbound/2026/10/novo.pdf', 89);

        $this->artisan('app:purge-media')->assertSuccessful();

        Storage::disk('local')->assertMissing('outbound/2026/07/velho.pdf');
        Storage::disk('local')->assertExists('outbound/2026/10/novo.pdf');
        $this->assertNull($old->fresh()?->local_path);
        $this->assertNotNull($old->fresh()?->media_purged_at);
        $this->assertNotNull($recent->fresh()?->local_path);
        $this->assertNull($recent->fresh()?->media_purged_at);
        $this->assertSame(1, TicketEvent::query()->where('type', 'media_purged')->count());
        $this->assertSame($old->id, TicketEvent::query()->where('type', 'media_purged')->sole()->payload['message_id'] ?? null);
    }

    public function test_e_idempotente(): void
    {
        $this->attachment('outbound/2026/07/velho.pdf', 120);

        $this->artisan('app:purge-media')->assertSuccessful();
        $this->artisan('app:purge-media')->assertSuccessful();

        $this->assertSame(1, TicketEvent::query()->where('type', 'media_purged')->count());
    }

    public function test_copia_que_ja_sumiu_do_disco_tambem_expira_sem_erro(): void
    {
        $message = $this->attachment('outbound/2026/07/sumiu.pdf', 100);
        Storage::disk('local')->delete('outbound/2026/07/sumiu.pdf');

        $this->artisan('app:purge-media')->assertSuccessful();

        $this->assertNotNull($message->fresh()?->media_purged_at);
    }

    public function test_texto_e_a_linha_da_mensagem_permanecem(): void
    {
        $message = $this->attachment('outbound/2026/07/velho.pdf', 100);
        $message->update(['body' => 'Legenda que fica para sempre']);

        $this->artisan('app:purge-media')->assertSuccessful();

        $this->assertSame('Legenda que fica para sempre', $message->fresh()?->body);
        $this->assertSame('orcamento.pdf', $message->fresh()?->media_name);
    }

    public function test_a_tela_avisa_que_o_arquivo_expirou(): void
    {
        $this->attachment('outbound/2026/07/velho.pdf', 100);
        $this->artisan('app:purge-media')->assertSuccessful();
        $viewer = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($viewer)->test(Inbox::class)
            ->call('select', $this->ticket->id)
            ->assertSee('Arquivo expirado');
    }

    public function test_esta_agendado_para_rodar_todo_dia(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'app:purge-media')));
    }
}
