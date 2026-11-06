<?php

namespace App\Services\Inbox;

/** O que uma recuperação ou importação trouxe de novo: mensagens gravadas, conversas (tickets) tocadas e o que foi ignorado, por motivo. */
final readonly class RecoveryResult
{
    /** @param  array<string, int>  $ignored */
    public function __construct(public int $messages, public int $conversations, public array $ignored = []) {}
}
