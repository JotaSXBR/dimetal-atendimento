<?php

namespace Tests\Feature\History;

use App\Enums\CloseOutcome;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\HistorySearch;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HistorySearchTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
        $this->supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
    }

    private function closed(array $override = [], ?string $contactName = null): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => $contactName ?? 'Cliente'])->id,
            'status' => TicketStatus::ENCERRADO,
            'closed_at' => now(),
        ], $override));
    }

    private function say(Ticket $ticket, string $body): void
    {
        Message::factory()->for($ticket)->create(['body' => $body]);
    }

    /** @return list<int> */
    private function ids(string $q = '', array $filters = [], ?User $user = null): array
    {
        return (new HistorySearch)->query(
            $user ?? $this->supervisor,
            $q,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            $filters['sectorId'] ?? null,
            $filters['userId'] ?? null,
            $filters['outcome'] ?? null,
        )->pluck('tickets.id')->all();
    }

    public function test_acha_por_nome_do_contato_e_por_protocolo(): void
    {
        $joaquim = $this->closed(contactName: 'Joaquim Pereira');
        $this->closed(contactName: 'Maria Souza');

        $this->assertSame([$joaquim->id], $this->ids('joaquim'));
        $this->assertSame([$joaquim->id], $this->ids((string) $joaquim->protocol));
    }

    public function test_acha_por_texto_da_mensagem_e_nao_duplica_quando_varias_casam(): void
    {
        $ticket = $this->closed();
        $this->say($ticket, 'preciso de cotacao de chapa');
        $this->say($ticket, 'segue a cotacao de inox');
        $this->say($this->closed(), 'bom dia');

        $this->assertSame([$ticket->id], $this->ids('cotacao'));
    }

    public function test_termo_curto_nao_busca_no_texto(): void
    {
        $this->say($this->closed(), 'ok pode enviar');

        $this->assertSame([], $this->ids('ok'));
    }

    public function test_percent_underscore_e_barra_valem_como_texto(): void
    {
        $percent = $this->closed();
        $this->say($percent, 'desconto de 100% hoje');
        $underscore = $this->closed();
        $this->say($underscore, 'codigo a_b123');
        $backslash = $this->closed();
        $this->say($backslash, 'pasta c:\\dados');
        $this->say($this->closed(), 'cem por cento e axb123');

        $this->assertSame([$percent->id], $this->ids('100%'));
        $this->assertSame([$underscore->id], $this->ids('a_b1'));
        $this->assertSame([$backslash->id], $this->ids('c:\\d'));
        $this->assertSame([], $this->ids("'; drop table messages; --"));
    }

    public function test_periodo_usa_o_dia_de_brasilia(): void
    {
        // 02:30 UTC de 30/10 é 23:30 de 29/10 em Brasília.
        $ticket = $this->closed(['closed_at' => CarbonImmutable::parse('2026-10-30 02:30:00', 'UTC')]);

        $this->assertSame([$ticket->id], $this->ids(filters: ['from' => '2026-10-29', 'to' => '2026-10-29']));
        $this->assertSame([], $this->ids(filters: ['from' => '2026-10-30', 'to' => '2026-10-30']));
        $this->assertSame([$ticket->id], $this->ids(filters: ['from' => '2026-10-29']));
        $this->assertSame([], $this->ids(filters: ['to' => '2026-10-28']));
    }

    public function test_filtros_de_setor_atendente_e_motivo(): void
    {
        $sector = Sector::factory()->create();
        $agent = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $match = $this->closed(['sector_id' => $sector->id, 'assigned_user_id' => $agent->id, 'close_outcome' => CloseOutcome::RESOLVIDO]);
        $this->closed(['close_outcome' => CloseOutcome::SEM_RESPOSTA]);

        $this->assertSame([$match->id], $this->ids(filters: ['sectorId' => $sector->id]));
        $this->assertSame([$match->id], $this->ids(filters: ['userId' => $agent->id]));
        $this->assertSame([$match->id], $this->ids(filters: ['outcome' => 'resolvido']));
        $this->assertCount(2, $this->ids(filters: ['outcome' => 'inventado']));
    }

    public function test_filtro_de_atendente_e_ignorado_para_atendente(): void
    {
        $sector = Sector::factory()->create();
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $me->sectors()->attach($sector);
        $colleague = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $mine = $this->closed(['sector_id' => $sector->id, 'assigned_user_id' => $me->id]);
        $theirs = $this->closed(['sector_id' => $sector->id, 'assigned_user_id' => $colleague->id]);

        $this->assertEqualsCanonicalizing([$mine->id, $theirs->id], $this->ids(filters: ['userId' => $colleague->id], user: $me));
    }

    public function test_so_devolve_o_que_o_usuario_pode_ver_e_ordena_pelo_encerramento(): void
    {
        $older = $this->closed(['closed_at' => now()->subDays(2)]);
        $newer = $this->closed(['closed_at' => now()->subDay()]);
        Ticket::factory()->create(['channel_id' => $this->channel->id, 'status' => TicketStatus::AGUARDANDO]);

        $this->assertSame([$newer->id, $older->id], $this->ids());
    }

    public function test_a_migracao_cria_o_indice_trigrama(): void
    {
        $this->assertNotNull(DB::selectOne("select indexname from pg_indexes where indexname = 'messages_body_trgm'"));
    }
}
