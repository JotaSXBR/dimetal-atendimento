<?php

namespace App\Services\Bot\Simulation;

use App\Models\BotSession;

/**
 * A conversa de teste do editor: o que a sessão guardaria, o que o bot "disse" e "fez" e como terminou. Vai e volta como
 * array para viver numa propriedade do componente Livewire (nada disto vai para o banco).
 *
 * @phpstan-type SessionShape array{node_id: ?string, vars: array<string, mixed>, attempts: int, waiting: ?string, state: string}
 * @phpstan-type LogShape list<array{kind: string, text: string}>
 * @phpstan-type StateShape array{name: string, clock: string, session: SessionShape, log: LogShape, trail: list<string>, outcome: ?string, tags?: array<int, true>}
 */
final class SimulationState
{
    public const IN_QUEUE = 'fila';

    public const CLOSED = 'encerrada';

    /**
     * @param  SessionShape  $session
     * @param  LogShape  $log  `bot`, `cliente`, `efeito` ou `sistema`
     * @param  list<string>  $trail  os blocos percorridos, na ordem
     */
    public function __construct(
        public string $name,
        public string $clock,
        public array $session = ['node_id' => null, 'vars' => [], 'attempts' => 0, 'waiting' => null, 'state' => BotSession::ACTIVE],
        public array $log = [],
        public array $trail = [],
        public ?string $outcome = null,
        /** @var array<int, true> as etiquetas "aplicadas" na conversa de teste (id → true) */
        public array $tags = [],
    ) {}

    public function add(string $kind, string $text): void
    {
        $this->log[] = ['kind' => $kind, 'text' => $text];
    }

    /** Copia o estado da sessão em memória (que nunca é gravada); conversa encerrada fica encerrada. */
    public function capture(BotSession $session): void
    {
        $this->session = [
            'node_id' => $session->node_id,
            'vars' => $session->vars,
            'attempts' => $session->attempts,
            'waiting' => $session->waiting,
            'state' => $this->outcome === self::CLOSED ? BotSession::CLOSED : $session->state,
        ];
    }

    public function toSession(): BotSession
    {
        return new BotSession($this->session);
    }

    /** @return StateShape */
    public function toArray(): array
    {
        return ['name' => $this->name, 'clock' => $this->clock, 'session' => $this->session, 'log' => $this->log, 'trail' => $this->trail, 'outcome' => $this->outcome, 'tags' => $this->tags];
    }

    /** @param  StateShape  $data */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['clock'], $data['session'], $data['log'], $data['trail'], $data['outcome'], $data['tags'] ?? []);
    }
}
