<?php

namespace Tests\Feature\Inbox;

use App\Events\MessageCreated;
use App\Jobs\ConfirmChannelDownJob;
use App\Jobs\DrainOutboxJob;
use App\Jobs\GoOfflineJob;
use App\Jobs\HandleWahaEvent;
use App\Jobs\ImportHistoryJob;
use App\Jobs\RecoverMessagesJob;
use App\Jobs\ResolveMessageMediaJob;
use App\Jobs\SendTicketMessageJob;
use App\Jobs\SyncContactToGoogleJob;
use App\Models\Message;
use Carbon\CarbonImmutable;
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
            'download de midia em recuperacao' => [new ResolveMessageMediaJob(1, 'media'), 'media'],
            'recuperacao de mensagens' => [new RecoverMessagesJob(1, CarbonImmutable::now(), CarbonImmutable::now()), 'history'],
            'confirmacao de queda' => [new ConfirmChannelDownJob(1), 'realtime'],
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

    public function test_o_worker_da_fila_history_espera_mais_que_o_job_mais_longo(): void
    {
        $now = CarbonImmutable::now();
        $longest = max((new RecoverMessagesJob(1, $now, $now))->timeout, (new ImportHistoryJob(1))->timeout);
        $script = (string) file_get_contents(base_path('docker/worker.sh'));

        $this->assertMatchesRegularExpression('/TIMEOUT=(\d+) start redis_history --queue=history/', $script);
        preg_match('/TIMEOUT=(\d+) start redis_history/', $script, $workerTimeout);
        $retryAfter = (int) config('queue.connections.redis_history.retry_after');

        $this->assertSame('history', config('queue.connections.redis_history.queue'));
        $this->assertGreaterThan((int) $workerTimeout[1], $retryAfter, 'retry_after deve passar do timeout do worker, senão o job é reentregue enquanto roda');
        $this->assertGreaterThan($longest, (int) $workerTimeout[1]);
    }

    public function test_o_worker_consome_todas_as_filas_usadas(): void
    {
        $script = (string) file_get_contents(base_path('docker/worker.sh'));

        foreach (['inbound', 'outbound', 'realtime', 'google', 'default', 'history', 'media'] as $queue) {
            $this->assertMatchesRegularExpression('/--queue=[a-z,]*\b'.$queue.'\b/', $script, "worker.sh não consome a fila {$queue}");
        }
    }
}
