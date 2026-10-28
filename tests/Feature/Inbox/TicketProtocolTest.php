<?php

namespace Tests\Feature\Inbox;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Services\Inbox\TicketProtocol;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketProtocolTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_28_000007_add_routing_columns.php';

    public function test_protocolos_seguem_a_sequencia_do_ano_sem_repetir(): void
    {
        $at = CarbonImmutable::parse('2026-06-10 15:00:00', 'UTC');

        $this->assertSame('2026-000001', TicketProtocol::next($at));
        $this->assertSame('2026-000002', TicketProtocol::next($at));
        $this->assertSame('2027-000001', TicketProtocol::next($at->addYear()));
        $this->assertSame('2026-000003', TicketProtocol::next($at));
    }

    public function test_o_ano_do_protocolo_e_o_de_sao_paulo_e_nao_o_de_utc(): void
    {
        // 01/01 01:00 UTC ainda é 31/12 22:00 em São Paulo.
        $this->assertSame('2025-000001', TicketProtocol::next(CarbonImmutable::parse('2026-01-01 01:00:00', 'UTC')));
        // 01/01 03:00 UTC já é 01/01 00:00 em São Paulo.
        $this->assertSame('2026-000001', TicketProtocol::next(CarbonImmutable::parse('2026-01-01 03:00:00', 'UTC')));
    }

    public function test_todo_ticket_criado_recebe_protocolo_unico(): void
    {
        $channel = Channel::factory()->create();

        $tickets = Ticket::factory()->count(3)->create(['channel_id' => $channel->id, 'opened_at' => '2026-03-01 12:00:00']);

        $this->assertSame(
            ['2026-000001', '2026-000002', '2026-000003'],
            $tickets->pluck('protocol')->sort()->values()->all(),
        );
    }

    public function test_ticket_que_nao_e_gravado_nao_consome_numero(): void
    {
        $at = CarbonImmutable::parse('2026-06-10 15:00:00', 'UTC');

        try {
            DB::transaction(function () use ($at): void {
                TicketProtocol::next($at);

                throw new \RuntimeException('falhou ao gravar o ticket');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame('2026-000001', TicketProtocol::next($at));
    }

    public function test_migracao_numera_os_tickets_que_ja_existem_no_fuso_de_sao_paulo(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);

        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create();

        $ids = array_map(fn (string $openedAt): int => DB::table('tickets')->insertGetId([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'status' => 'encerrado',
            'opened_at' => $openedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]), [
            '2025-12-20 12:00:00',
            '2026-01-01 01:00:00', // 31/12/2025 22:00 em São Paulo: ainda 2025
            '2026-02-10 12:00:00',
            '2026-02-11 12:00:00',
        ]);

        Artisan::call('migrate', ['--path' => self::MIGRATION, '--force' => true]);

        $this->assertSame(
            ['2025-000001', '2025-000002', '2026-000001', '2026-000002'],
            array_map(fn (int $id) => DB::table('tickets')->where('id', $id)->value('protocol'), $ids),
        );
        $this->assertSame([2025 => 2, 2026 => 2], DB::table('ticket_sequences')->orderBy('year')->pluck('last', 'year')->all());
        $this->assertSame('2026-000003', TicketProtocol::next(CarbonImmutable::parse('2026-03-01 12:00:00', 'UTC')));
    }
}
