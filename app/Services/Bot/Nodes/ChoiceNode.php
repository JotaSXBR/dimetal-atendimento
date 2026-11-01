<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Step;

/**
 * Menu numerado: mostra as opções, espera um número e repete o aviso até duas vezes antes de mandar o cliente à fila.
 * As opções mostradas ficam na sessão (`_choices`), para o número valer para a lista que o cliente viu.
 */
abstract class ChoiceNode extends Node
{
    public const MAX_ATTEMPTS = 3;

    private const DEFAULT_INVALID = 'Opção inválida. Responda com o número de uma das opções.';

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{label: string, value: int|string}>
     */
    abstract protected function choices(BotRun $run, array $data): array;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function picked(BotRun $run, array $data, int|string $value, int $number): Step;

    public function enter(BotRun $run, array $data): Step
    {
        $choices = $this->choices($run, $data);

        if ($choices === []) {
            return Step::leave('erros');
        }

        $run->set('_choices', array_column($choices, 'value'));
        $run->set('_menu', $this->render($run, $data, $choices));
        $run->say((string) $run->get('_menu'));

        return Step::wait('menu', (int) ($data['timeout_min'] ?? 10));
    }

    public function answer(BotRun $run, array $data, string $text): Step
    {
        $values = $run->get('_choices');
        $number = preg_match('/^\d{1,3}$/', $text) === 1 ? (int) $text : 0;

        if (is_array($values) && $number >= 1 && $number <= count($values)) {
            return $this->picked($run, $data, $values[$number - 1], $number);
        }

        $run->session->attempts++;

        if ($run->session->attempts >= self::MAX_ATTEMPTS) {
            return Step::leave('erros');
        }

        $run->say(is_string($data['invalido'] ?? null) && trim($data['invalido']) !== '' ? $data['invalido'] : self::DEFAULT_INVALID);
        $run->say((string) $run->get('_menu'));

        return Step::wait('menu', (int) ($data['timeout_min'] ?? 10));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{label: string, value: int|string}>  $choices
     */
    private function render(BotRun $run, array $data, array $choices): string
    {
        $lines = [];
        foreach ($choices as $i => $choice) {
            $lines[] = ($i + 1).' - '.$choice['label'];
        }

        return trim((string) ($data['texto'] ?? ''))."\n\n".implode("\n", $lines)."\n\nPara falar com um atendente digite #sair";
    }
}
