<?php

namespace App\Services\Bot;

/** O que um bloco decidiu: seguir por uma porta, esperar o cliente, sair para a fila ou parar (o próprio bloco já encerrou a sessão). */
final class Step
{
    public const NEXT = 'next';

    public const WAIT = 'wait';

    public const LEAVE = 'leave';

    public const DONE = 'done';

    private function __construct(
        public readonly string $kind,
        public readonly string $port = 'out',
        public readonly string $waiting = '',
        public readonly int $minutes = 10,
        public readonly string $reason = '',
    ) {}

    public static function next(string $port = 'out'): self
    {
        return new self(self::NEXT, port: $port);
    }

    /** `$kind`: `menu` ou `input`. */
    public static function wait(string $kind, int $minutes = 10): self
    {
        return new self(self::WAIT, waiting: $kind, minutes: max(1, $minutes));
    }

    /** Encaminha à fila padrão. `$reason`: `erros`, `sair` ou `timeout`. */
    public static function leave(string $reason): self
    {
        return new self(self::LEAVE, reason: $reason);
    }

    public static function done(): self
    {
        return new self(self::DONE);
    }
}
