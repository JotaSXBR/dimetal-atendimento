<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageCreated;
use App\Jobs\DrainOutboxJob;
use App\Jobs\GoOfflineJob;
use App\Jobs\HandleWahaEvent;
use App\Jobs\ResolveMessageMediaJob;
use App\Jobs\SendTicketMessageJob;
use App\Jobs\SyncContactToGoogleJob;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Cada fila tem um consumidor em `docker/worker.sh`; job numa fila que ninguém lê é perda silenciosa. */
class QueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{object, string}> */
    public static function rotas(): array
    {
        return [
            'webhook do WAHA' => [new HandleWahaEvent(1), 'inbound'],
            'download de midia' => [new ResolveMessageMediaJob(1), 'inbound'],
            'envio ao cliente' => [new SendTicketMessageJob(1), 'outbound'],
            'caixa de saida' => [new DrainOutboxJob(1), 'outbound'],
            'offline' => [new GoOfflineJob(1, 'x'), 'outbound'],
            'google' => [new SyncContactToGoogleJob(1), 'google'],
        ];
    }

    #[DataProvider('rotas')]
    public function test_cada_job_vai_para_a_fila_certa(object $job, string $queue): void
    {
        $this->assertSame($queue, $job->queue);
    }

    public function test_eventos_de_tempo_real_vao_para_a_fila_realtime(): void
    {
        $message = Message::factory()->create();

        $this->assertSame('realtime', (new MessageCreated($message))->broadcastQueue());
    }

    public function test_o_worker_consome_todas_as_filas_usadas(): void
    {
        $script = (string) file_get_contents(base_path('docker/worker.sh'));

        foreach (['inbound', 'outbound', 'realtime', 'google', 'default'] as $queue) {
            $this->assertMatchesRegularExpression('/--queue=[a-z,]*\b'.$queue.'\b/', $script, "worker.sh não consome a fila {$queue}");
        }
    }
}
