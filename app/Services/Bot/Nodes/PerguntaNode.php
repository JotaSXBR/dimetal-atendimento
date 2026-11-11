<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\Answers\Answers;
use App\Services\Bot\BotMessages;
use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;

/** Pergunta e guarda a resposta numa variável. Resposta fora da validação repete a pergunta até duas vezes. */
final class PerguntaNode extends Node
{
    public function enter(BotRun $run, NodeData $data): Step
    {
        $run->say($data->string('texto'));

        return Step::wait('input', $data->int('timeout_min', 10));
    }

    public function answer(BotRun $run, NodeData $data, string $text): Step
    {
        $variable = $data->string('variavel');

        $value = $variable !== '' ? $this->accepted($data->string('validacao', 'texto'), $text) : null;

        if ($value !== null) {
            $run->set($variable, $value);

            return Step::next();
        }

        $run->session->attempts++;

        if ($run->session->attempts >= ChoiceNode::MAX_ATTEMPTS) {
            return Step::leave('erros');
        }

        $run->say($data->filled('invalido') ? $data->string('invalido') : BotMessages::text($run->flow, 'resposta_invalida'));
        $run->say($data->string('texto'));

        return Step::wait('input', $data->int('timeout_min', 10));
    }

    /** O valor a guardar (já formatado nos tipos de `Answers`), ou nulo quando a resposta não vale. */
    private function accepted(string $kind, string $text): ?string
    {
        return match (true) {
            $kind === 'email' => filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? $text : null,
            $kind === 'numero' => preg_match('/^\d+$/', $text) === 1 ? $text : null,
            in_array($kind, Answers::kinds(), true) => Answers::parse($kind, $text),
            default => $text !== '' ? $text : null,
        };
    }
}
