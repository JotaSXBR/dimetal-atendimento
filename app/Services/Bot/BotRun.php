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
        $custom = [];
        foreach ($this->session->vars as $key => $value) {
            if (is_scalar($value) && ! str_starts_with($key, '_')) {
                $custom[$key] = (string) $value;
            }
        }

        $name = trim((string) $this->ticket->contact()->first()?->name);

        return [
            ...$custom,
            'nome' => $name !== '' ? $name : 'cliente',
            'protocolo' => (string) $this->ticket->protocol,
            'setor' => is_scalar($this->get('setor')) ? (string) $this->get('setor') : '',
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
