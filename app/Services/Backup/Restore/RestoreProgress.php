<?php

namespace App\Services\Backup\Restore;

use Illuminate\Support\Facades\Cache;

/**
 * O andamento da restauração, no Redis (cache) e não no banco, porque o banco troca no meio. Não guarda usuário, arquivo nem
 * segredo: a página de andamento é pública de propósito (ela precisa abrir durante a manutenção).
 */
class RestoreProgress
{
    public const STEPS = [
        'inspect' => 'Conferindo o arquivo',
        'safety' => 'Backup de segurança',
        'restore' => 'Restaurando em um banco novo',
        'verify' => 'Verificando o banco restaurado',
        'swap' => 'Trocando o banco',
        'migrate' => 'Atualizando o esquema',
        'resume' => 'Voltando ao ar',
        'recover' => 'Recuperando mensagens do WhatsApp',
    ];

    private const KEY = 'restore:state';

    /** Um estado "em andamento" que ficou para trás (processo morto) expira e destrava a tela. */
    private const RUNNING_MINUTES = 60;

    private const FINISHED_HOURS = 24;

    /** @param  list<string>  $keys passos desta restauração, na ordem (chaves de {@see self::STEPS}) */
    public function begin(string $title, array $keys): void
    {
        $steps = array_map(fn (string $key): array => ['key' => $key, 'label' => self::STEPS[$key] ?? $key, 'state' => 'pending'], $keys);

        $this->put(['status' => 'running', 'title' => $title, 'steps' => $steps, 'message' => '']);
    }

    public function running(string $key): void
    {
        $this->mark($key, 'running');
    }

    public function done(string $key): void
    {
        $this->mark($key, 'done');
    }

    public function finish(string $message): void
    {
        $state = $this->get();
        $state['status'] = 'done';
        $state['message'] = $message;

        $this->put($state, finished: true);
    }

    /** O passo em andamento (se houver) vira `failed`. */
    public function fail(string $message): void
    {
        $state = $this->get();
        $state['status'] = 'failed';
        $state['message'] = $message;
        $state['steps'] = array_map(fn (array $step): array => $step['state'] === 'running' ? [...$step, 'state' => 'failed'] : $step, $state['steps']);

        $this->put($state, finished: true);
    }

    public function isRunning(): bool
    {
        return $this->get()['status'] === 'running';
    }

    /** @return array{status: string, title: string, steps: list<array{key: string, label: string, state: string}>, message: string} */
    public function get(): array
    {
        $state = Cache::get(self::KEY);
        $idle = ['status' => 'idle', 'title' => '', 'steps' => [], 'message' => ''];

        if (! is_array($state) || ! isset($state['status'], $state['title'], $state['steps'], $state['message']) || ! is_array($state['steps'])) {
            return $idle;
        }

        $steps = [];

        foreach ($state['steps'] as $step) {
            if (is_array($step) && is_string($step['key'] ?? null) && is_string($step['label'] ?? null) && is_string($step['state'] ?? null)) {
                $steps[] = ['key' => $step['key'], 'label' => $step['label'], 'state' => $step['state']];
            }
        }

        $text = fn (mixed $value): string => is_string($value) ? $value : '';

        return ['status' => $text($state['status']), 'title' => $text($state['title']), 'steps' => $steps, 'message' => $text($state['message'])];
    }

    private function mark(string $key, string $to): void
    {
        $state = $this->get();
        $state['steps'] = array_map(fn (array $step): array => $step['key'] === $key ? [...$step, 'state' => $to] : $step, $state['steps']);

        $this->put($state);
    }

    /** @param  array<string, mixed>  $state */
    private function put(array $state, bool $finished = false): void
    {
        Cache::put(self::KEY, $state, $finished ? now()->addHours(self::FINISHED_HOURS) : now()->addMinutes(self::RUNNING_MINUTES));
    }
}
