<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Notifications\MessagesRecovered;
use App\Services\Alerts\AlertRecipients;
use App\Services\Inbox\ImportHistory;
use App\Services\Inbox\RecoveryResult;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** A importação inicial do histórico de um canal: roda uma vez, na primeira conexão, na fila `history`. */
class ImportHistoryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    /** Erro do WAHA (por exemplo 422 enquanto a sessão reconecta) espera 60 s e desiste na 3ª exceção, em vez de repetir sem pausa. */
    public int $backoff = 60;

    public int $maxExceptions = 3;

    /** Quanto de uma rodada se usa antes de parar e continuar na seguinte (abaixo do `timeout`). */
    private const BUDGET_SECONDS = 780;

    /** O lock de unicidade expira sozinho: um worker morto no meio do job (deploy) não pode travar o canal para sempre. */
    public int $uniqueFor = 900;

    public function __construct(public int $channelId)
    {
        $this->onQueue('history');
    }

    /** A importação grande continua em várias rodadas (`release`); o limite é de tempo, não de tentativas. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(3);
    }

    public function uniqueId(): string
    {
        return (string) $this->channelId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('history:'.$this->channelId))->releaseAfter(60)->expireAfter(900)];
    }

    public function handle(ImportHistory $import): void
    {
        $channel = Channel::query()->find($this->channelId);

        if ($channel === null || ! $channel->active) {
            return;
        }

        // Oscilou (ou ainda não voltou): a importação é de uma vez só e já foi reservada, então espera em vez de se perder.
        if (! $channel->isConnected()) {
            $this->release(60);

            return;
        }

        $result = $import($channel, now()->toImmutable()->addSeconds(self::BUDGET_SECONDS));
        $totals = $this->accumulate($result, $import->open);

        if (! $import->complete) {
            $this->release(30);

            return;
        }

        array_map(fn (string $name) => Cache::forget($this->counter($name)), ['messages', 'conversations', 'open']);
        Notification::send(app(AlertRecipients::class)->everyone(), new MessagesRecovered($channel, $totals['messages'], $totals['conversations'], 'imported', $totals['open'], $import->truncated ? ImportHistory::MAX_MESSAGES : null, $result->ignored));
    }

    /**
     * Soma os totais das rodadas em contadores de cache (1 dia). Um ticket que atravessou duas rodadas conta duas vezes em
     * `conversations` (aproximação aceita: o aviso é um resumo).
     *
     * @return array{messages: int, conversations: int, open: int}
     */
    private function accumulate(RecoveryResult $result, int $open): array
    {
        foreach (['messages' => $result->messages, 'conversations' => $result->conversations, 'open' => $open] as $name => $amount) {
            Cache::add($this->counter($name), 0, now()->addDay());
            Cache::increment($this->counter($name), $amount);
        }

        return ['messages' => $this->total('messages'), 'conversations' => $this->total('conversations'), 'open' => $this->total('open')];
    }

    private function counter(string $name): string
    {
        return 'import-totals:'.$this->channelId.':'.$name;
    }

    private function total(string $name): int
    {
        $value = Cache::get($this->counter($name));

        // O Redis devolve o contador como texto ("12"), o cache em memória como inteiro.
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * A importação não terminou: libera a próxima conexão para tentar de novo.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function failed(Throwable $e): void
    {
        Channel::query()->whereKey($this->channelId)->update(['backfill_last_run_at' => null]);
    }
}
