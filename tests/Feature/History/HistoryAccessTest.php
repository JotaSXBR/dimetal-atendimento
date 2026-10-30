<?php

namespace Tests\Feature\History;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HistoryAccessTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private Sector $mine;

    private Sector $other;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
        $this->mine = Sector::factory()->create();
        $this->other = Sector::factory()->create();
        $this->agent = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->agent->sectors()->attach($this->mine);
    }

    private function closed(array $override = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'channel_id' => $this->channel->id,
            'status' => TicketStatus::ENCERRADO,
            'closed_at' => now(),
            'sector_id' => $this->mine->id,
        ], $override));
    }

    /** @return list<int> */
    private function historyIds(User $user): array
    {
        return Ticket::query()->inHistoryOf($user)->pluck('id')->all();
    }

    public function test_atendente_ve_encerrado_do_proprio_setor_e_o_que_atendeu(): void
    {
        $fromMySector = $this->closed();
        $handledByMe = $this->closed(['sector_id' => $this->other->id, 'assigned_user_id' => $this->agent->id]);

        $this->assertEqualsCanonicalizing([$fromMySector->id, $handledByMe->id], $this->historyIds($this->agent));
    }

    public function test_atendente_nao_ve_encerrado_de_outro_setor(): void
    {
        $this->closed(['sector_id' => $this->other->id]);

        $this->assertSame([], $this->historyIds($this->agent));
    }

    public function test_encerrado_sem_setor_so_aparece_para_supervisor_e_admin_e_para_quem_atendeu(): void
    {
        $noSector = $this->closed(['sector_id' => null]);
        $handled = $this->closed(['sector_id' => null, 'assigned_user_id' => $this->agent->id]);

        $this->assertSame([$handled->id], $this->historyIds($this->agent));

        foreach ([UserRole::SUPERVISOR, UserRole::ADMIN] as $role) {
            $this->assertContains($noSector->id, $this->historyIds(User::factory()->create(['role' => $role])));
        }
    }

    public function test_usuario_inativo_nao_ve_nada(): void
    {
        $this->closed();
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR, 'active' => false]);
        $this->agent->forceFill(['active' => false])->save();

        $this->assertSame([], $this->historyIds($supervisor));
        $this->assertSame([], $this->historyIds($this->agent));
        $this->assertSame([], Ticket::query()->readableBy($supervisor)->pluck('id')->all());
    }

    public function test_ticket_aberto_nao_entra_no_historico(): void
    {
        Ticket::factory()->create(['channel_id' => $this->channel->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'sector_id' => $this->mine->id, 'assigned_user_id' => $this->agent->id]);

        $this->assertSame([], $this->historyIds($this->agent));
    }

    public function test_readable_by_soma_a_fila_e_o_historico(): void
    {
        $open = Ticket::factory()->create(['channel_id' => $this->channel->id, 'status' => TicketStatus::AGUARDANDO, 'sector_id' => $this->mine->id]);
        $closed = $this->closed();
        $foreign = $this->closed(['sector_id' => $this->other->id]);
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);

        $this->assertEqualsCanonicalizing([$open->id, $closed->id], Ticket::query()->readableBy($this->agent)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$open->id, $closed->id, $foreign->id], Ticket::query()->readableBy($supervisor)->pluck('id')->all());
    }

    public function test_a_midia_de_encerrado_responde_200_no_escopo_e_403_fora_dele(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('outbound/x.jpg', 'x');

        $inScope = $this->closed();
        $foreign = $this->closed(['sector_id' => $this->other->id]);
        $mine = Message::factory()->for($inScope)->create(['type' => 'media', 'media_mime' => 'image/jpeg', 'local_path' => 'outbound/x.jpg']);
        $theirs = Message::factory()->for($foreign)->create(['type' => 'media', 'media_mime' => 'image/jpeg', 'local_path' => 'outbound/x.jpg']);

        $this->actingAs($this->agent)->get(route('midia', $mine))->assertOk();
        $this->actingAs($this->agent)->get(route('midia', $theirs))->assertForbidden();
    }
}
