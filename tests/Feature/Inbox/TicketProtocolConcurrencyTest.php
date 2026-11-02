<?php

namespace Tests\Feature\Inbox;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Corrida real: dois processos PHP abrindo tickets ao mesmo tempo contra o mesmo contador de protocolo.
 * Não usa RefreshDatabase porque a transação do teste esconderia os dados dos outros processos.
 */
class TicketProtocolConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private const TICKETS_PER_PROCESS = 5;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_dois_processos_abrindo_tickets_ao_mesmo_tempo_nunca_repetem_protocolo(): void
    {
        $channel = Channel::factory()->create();
        $contacts = Contact::factory()->count(self::TICKETS_PER_PROCESS * 2)->create()->pluck('id');

        $startAt = microtime(true) + 3;
        $workers = $contacts->chunk(self::TICKETS_PER_PROCESS)->map(function ($ids) use ($channel, $startAt): Process {
            // Em teste paralelo cada processo tem o próprio banco (só na configuração): o filho precisa receber o nome dele.
            $process = new Process([
                PHP_BINARY, base_path('tests/Support/create_tickets_worker.php'),
                (string) $channel->id, $ids->implode(','), (string) $startAt,
            ], null, ['DB_DATABASE' => (string) config('database.connections.pgsql.database')]);
            $process->start();

            return $process;
        });

        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput().$worker->getOutput());
            // Os filhos precisam ter usado o banco de teste, e não o .env de desenvolvimento.
            $this->assertSame('db='.config('database.connections.pgsql.database'), trim($worker->getOutput()));
        }

        $year = now()->timezone(config()->string('app.display_timezone'))->year;
        $expected = collect(range(1, self::TICKETS_PER_PROCESS * 2))->map(fn (int $n) => sprintf('%d-%06d', $year, $n))->all();

        // Dez tickets, dez protocolos distintos e consecutivos, sem buraco nem repetição.
        $this->assertEquals($expected, Ticket::query()->orderBy('protocol')->pluck('protocol')->all());
    }
}
