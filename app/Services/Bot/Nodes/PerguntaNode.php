<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Step;

/** Pergunta e guarda a resposta numa variável. Resposta fora da validação repete a pergunta até duas vezes. */
final class PerguntaNode extends Node
{
    private const DEFAULT_INVALID = 'Resposta inválida. Tente de novo.';

    public function enter(BotRun $run, array $data): Step
    {
        $run->say((string) ($data['texto'] ?? ''));

        return Step::wait('input', (int) ($data['timeout_min'] ?? 10));
    }

    public function answer(BotRun $run, array $data, string $text): Step
    {
        $variable = (string) ($data['variavel'] ?? '');

        if ($variable !== '' && $this->valid((string) ($data['validacao'] ?? 'texto'), $text)) {
            $run->set($variable, $text);

            return Step::next();
        }

        $run->session->attempts++;

        if ($run->session->attempts >= ChoiceNode::MAX_ATTEMPTS) {
            return Step::leave('erros');
        }

        $run->say(is_string($data['invalido'] ?? null) && trim($data['invalido']) !== '' ? $data['invalido'] : self::DEFAULT_INVALID);
        $run->say((string) ($data['texto'] ?? ''));

        return Step::wait('input', (int) ($data['timeout_min'] ?? 10));
    }

    private function valid(string $kind, string $text): bool
    {
        return match ($kind) {
            'email' => filter_var($text, FILTER_VALIDATE_EMAIL) !== false,
            'numero' => preg_match('/^\d+$/', $text) === 1,
            default => $text !== '',
        };
    }
}
