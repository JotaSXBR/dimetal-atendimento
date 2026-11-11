<?php

namespace App\Services\Bot;

use App\Models\BotSession;
use App\Services\Bot\Effects\BotEffects;
use App\Services\Bot\Flow\FlowGraph;
use App\Services\Inbox\BusinessHours;
use App\Settings\CompanySettings;
use Closure;

/** O contexto de uma execução: sessão, grafo e efeitos, mais o que os blocos precisam (falar, ler e gravar variáveis). */
final class BotRun
{
    public function __construct(
        public readonly BotEngine $engine,
        public readonly BotSession $session,
        public readonly FlowGraph $flow,
        public readonly BotEffects $fx,
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

        $name = $this->fx->contactName();

        return [
            ...$custom,
            'nome' => $name !== '' ? $name : 'cliente',
            'empresa' => CompanySettings::name(),
            'protocolo' => $this->fx->protocol(),
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
        $this->fx->say(Vars::render($template, $this->vars()));
    }
}
