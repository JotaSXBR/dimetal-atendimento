<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;

/** Pergunta e guarda a resposta numa variável. Resposta fora da validação repete a pergunta até duas vezes. */
final class PerguntaNode extends Node
{
    private const DEFAULT_INVALID = 'Resposta inválida. Tente de novo.';

    public function enter(BotRun $run, NodeData $data): Step
    {
        $run->say($data->string('texto'));

        return Step::wait('input', $data->int('timeout_min', 10));
    }

    public function answer(BotRun $run, NodeData $data, string $text): Step
    {
        $variable = $data->string('variavel');

        if ($variable !== '' && $this->valid($data->string('validacao', 'texto'), $text)) {
            $run->set($variable, $text);

            return Step::next();
        }

        $run->session->attempts++;

        if ($run->session->attempts >= ChoiceNode::MAX_ATTEMPTS) {
            return Step::leave('erros');
        }

        $run->say($data->filled('invalido') ? $data->string('invalido') : self::DEFAULT_INVALID);
        $run->say($data->string('texto'));

        return Step::wait('input', $data->int('timeout_min', 10));
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
