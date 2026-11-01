<?php

namespace App\Services\Bot;

use App\Models\BotSession;
use App\Models\Ticket;
use App\Services\Bot\Flow\FlowGraph;
use App\Services\Inbox\BusinessHours;
use Closure;

/** O contexto de uma execução: ticket, sessão e grafo, mais o que os blocos precisam (falar, ler e gravar variáveis). */
final class BotRun
{
    public function __construct(
        public readonly BotEngine $engine,
        public readonly BotSession $session,
        public readonly Ticket $ticket,
        public readonly FlowGraph $flow,
    ) {}

    /** @return array<string, string|int|Closure(): string> */
    public function vars(): array
    {
        $custom = array_filter($this->session->vars, fn (mixed $v, string $k): bool => is_scalar($v) && ! str_starts_with($k, '_'), ARRAY_FILTER_USE_BOTH);
        $name = trim((string) $this->ticket->contact()->first()?->name);

        return [
            ...$custom,
            'nome' => $name !== '' ? $name : 'cliente',
            'protocolo' => (string) $this->ticket->protocol,
            'setor' => (string) ($this->session->vars['setor'] ?? ''),
            'horario' => fn (): string => app(BusinessHours::class)->summary(),
            'proximo_horario' => fn (): string => app(BusinessHours::class)->nextOpeningLabel(now()),
        ];
    }

    public function get(string $key): mixed
    {
        return $this->session->vars[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->session->vars = [...$this->session->vars, $key => $value];
    }

    public function say(string $template): void
    {
        $this->engine->say($this->ticket, Vars::render($template, $this->vars()));
    }
}
