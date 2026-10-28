<?php

namespace Tests\Feature\Inbox;

use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Corrida real: dois processos PHP, duas conexões ao Postgres, assumindo o mesmo ticket no mesmo instante.
 * Não usa RefreshDatabase porque a transação do teste esconderia os dados dos outros processos.
 */
class ClaimConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_dois_processos_assumindo_ao_mesmo_tempo_so_um_vence(): void
    {
        $ticket = Ticket::factory()->for(Channel::factory())->create();
        [$a, $b] = User::factory()->count(2)->create();

        $startAt = microtime(true) + 3;
        $workers = array_map(function (User $user) use ($ticket, $startAt): Process {
            $process = new Process([PHP_BINARY, base_path('tests/Support/claim_worker.php'), (string) $ticket->id, (string) $user->id, (string) $startAt]);
            $process->start();

            return $process;
        }, [$a, $b]);

        $results = [];

        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput().$worker->getOutput());
            $results[] = trim($worker->getOutput());
        }

        // Os filhos precisam ter usado o banco de teste, e não o .env de desenvolvimento.
        $database = config('database.connections.pgsql.database');

        foreach ($results as $result) {
            $this->assertStringEndsWith(' db='.$database, $result);
        }

        $this->assertSame(['LOST', 'WON'], collect($results)->map(fn (string $r) => strtok($r, ' '))->sort()->values()->all());

        $winner = str_starts_with($results[0], 'WON') ? $a : $b;
        $this->assertSame($winner->id, $ticket->fresh()->assigned_user_id);
        $this->assertSame(1, $ticket->events()->where('type', 'claimed')->count());
    }
}
