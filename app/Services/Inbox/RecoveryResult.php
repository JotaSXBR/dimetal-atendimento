<?php

namespace App\Services\Inbox;

/** O que uma recuperação ou importação trouxe de novo: mensagens gravadas e conversas (tickets) tocadas. */
final readonly class RecoveryResult
{
    public function __construct(public int $messages, public int $conversations) {}
}
