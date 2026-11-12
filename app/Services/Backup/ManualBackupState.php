<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Cache;

/**
 * O andamento do backup manual (botão "Fazer backup agora"), no Redis: a tela consulta enquanto roda e mostra o resultado.
 * Um "em andamento" que ficou para trás (job morto) expira sozinho.
 */
class ManualBackupState
{
    private const KEY = 'backup:manual';

    private const RUNNING_MINUTES = 20;

    private const RESULT_MINUTES = 10;

    public function markStarted(): void
    {
        Cache::put(self::KEY, ['status' => 'running', 'file' => null, 'error' => null], now()->addMinutes(self::RUNNING_MINUTES));
    }

    /** `$error` preenchido = falhou; senão deu certo e `$file` é o nome do arquivo. */
    public function markFinished(?string $file, ?string $error): void
    {
        Cache::put(self::KEY, ['status' => $error === null ? 'done' : 'failed', 'file' => $file, 'error' => $error], now()->addMinutes(self::RESULT_MINUTES));
    }

    public function isRunning(): bool
    {
        return $this->get()['status'] === 'running';
    }

    /** @return array{status: string, file: string|null, error: string|null} */
    public function get(): array
    {
        $state = Cache::get(self::KEY);

        if (! is_array($state) || ! is_string($state['status'] ?? null)) {
            return ['status' => 'idle', 'file' => null, 'error' => null];
        }

        return [
            'status' => $state['status'],
            'file' => is_string($state['file'] ?? null) ? $state['file'] : null,
            'error' => is_string($state['error'] ?? null) ? $state['error'] : null,
        ];
    }
}
