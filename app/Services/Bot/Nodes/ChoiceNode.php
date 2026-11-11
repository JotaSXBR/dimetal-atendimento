<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotMessages;
use App\Services\Bot\BotRun;
use App\Services\Bot\ChoiceReply;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;

/**
 * Menu numerado: mostra as opções, espera um número e repete o aviso até duas vezes antes de mandar o cliente à fila.
 * As opções mostradas ficam na sessão (`_choices`), para o número valer para a lista que o cliente viu.
 */
abstract class ChoiceNode extends Node
{
    public const MAX_ATTEMPTS = 3;

    /** @return list<array{label: string, value: int|string}> */
    abstract protected function choices(BotRun $run, NodeData $data): array;

    abstract protected function picked(BotRun $run, NodeData $data, int|string $value, int $number): Step;

    public function enter(BotRun $run, NodeData $data): Step
    {
        $choices = $this->choices($run, $data);

        if ($choices === []) {
            return Step::leave('erros');
        }

        $menu = $this->render($run, $data, $choices);

        $run->set('_choices', array_column($choices, 'value'));
        $run->set('_menu', $menu);
        $run->say($menu);

        return Step::wait('menu', $data->int('timeout_min', 10));
    }

    public function answer(BotRun $run, NodeData $data, string $text): Step
    {
        $values = $run->get('_choices');
        $number = ChoiceReply::number($text) ?? 0;
        $picked = is_array($values) ? ($values[$number - 1] ?? null) : null;

        if (is_int($picked) || is_string($picked)) {
            return $this->picked($run, $data, $picked, $number);
        }

        $run->session->attempts++;

        if ($run->session->attempts >= self::MAX_ATTEMPTS) {
            return Step::leave('erros');
        }

        $menu = $run->get('_menu');
        $run->say($data->filled('invalido') ? $data->string('invalido') : BotMessages::text($run->flow, 'opcao_invalida'));
        $run->say(is_string($menu) ? $menu : '');

        return Step::wait('menu', $data->int('timeout_min', 10));
    }

    /** @param  list<array{label: string, value: int|string}>  $choices */
    private function render(BotRun $run, NodeData $data, array $choices): string
    {
        $lines = [];
        foreach ($choices as $i => $choice) {
            $lines[] = ($i + 1).' - '.$choice['label'];
        }

        $footer = BotMessages::text($run->flow, 'rodape_sair');

        return trim($data->string('texto'))."\n\n".implode("\n", $lines).($footer === '' ? '' : "\n\n".$footer);
    }
}
