<?php

namespace App\Services\Bot\Nodes;

use App\Actions\Contacts\SaveContact;
use App\Services\Bot\BotRun;
use App\Services\Bot\Step;

/** Grava uma variável num campo do contato (só nome, e-mail e empresa) e deixa o contato elegível ao envio ao Google. */
final class SalvarContatoNode extends Node
{
    public const FIELDS = ['name', 'email', 'company'];

    public function enter(BotRun $run, array $data): Step
    {
        $field = (string) ($data['campo'] ?? '');
        $value = $run->get((string) ($data['variavel'] ?? ''));

        if (in_array($field, self::FIELDS, true) && is_scalar($value) && trim((string) $value) !== '') {
            (new SaveContact)($run->ticket->contact()->firstOrFail(), [$field => (string) $value]);
        }

        return Step::next();
    }
}
