<?php

namespace App\Services\Bot;

/** O modelo "Atendimento padrão": saudação, menu de setores e encaminhamento com o protocolo. */
final class DefaultWorkflow
{
    public const NAME = 'Atendimento padrão';

    /** O aviso de fora do expediente, usado pelos modelos com horário. */
    public const OUT_OF_HOURS = 'Olá, {{nome}}! Nosso horário de atendimento é {{horario}}. Retornamos {{proximo_horario}} e você já está na fila para ser atendido.';

    /** @return array<string, mixed> */
    public static function graph(): array
    {
        $node = fn (string $id, string $type, int $x, array $data = []): array => ['id' => $id, 'type' => $type, 'x' => $x, 'y' => 0, 'data' => $data];

        return [
            'nodes' => [
                $node('inicio', 'inicio', 0),
                $node('saudacao', 'mensagem', 260, ['texto' => 'Olá, {{nome}}! Bem-vindo(a) ao nosso atendimento.']),
                $node('menu', 'menu_setores', 520, ['texto' => 'Com qual setor você quer falar? Responda com o número da opção.']),
                $node('encaminhamento', 'mensagem', 780, ['texto' => 'Certo! Seu protocolo é {{protocolo}}. Você entrou na fila de {{setor}} e um atendente responde em instantes.']),
                $node('transferir', 'transferir', 1040),
            ],
            'edges' => [
                ['from' => 'inicio', 'port' => 'out', 'to' => 'saudacao'],
                ['from' => 'saudacao', 'port' => 'out', 'to' => 'menu'],
                ['from' => 'menu', 'port' => 'setor', 'to' => 'encaminhamento'],
                ['from' => 'encaminhamento', 'port' => 'out', 'to' => 'transferir'],
            ],
        ];
    }

    /**
     * O modelo "Atendimento com horário": dentro do expediente, saudação, menu de setores e encaminhamento; fora dele, o aviso
     * do horário e a entrega à fila geral, sem setor (o SLA só começa a contar quando o expediente abre).
     *
     * @return array<string, mixed>
     */
    public static function withHours(): array
    {
        $node = fn (string $id, string $type, int $x, int $y = 0, array $data = []): array => ['id' => $id, 'type' => $type, 'x' => $x, 'y' => $y, 'data' => $data];

        return [
            'nodes' => [
                $node('inicio', 'inicio', 0),
                $node('horario', 'condicao', 260, 0, ['kind' => 'horario']),
                $node('saudacao', 'mensagem', 520, 0, ['texto' => 'Olá, {{nome}}! Bem-vindo a {{empresa}}.']),
                $node('menu', 'menu_setores', 780, 0, ['texto' => 'Com qual setor você quer falar? Responda com o número da opção:', 'timeout_min' => 5]),
                $node('encaminhamento', 'mensagem', 1040, 0, ['texto' => 'Certo! Seu protocolo é {{protocolo}}. Você entrou na fila de {{setor}} e um atendente responde em instantes.']),
                $node('transferir', 'transferir', 1300),
                $node('fora', 'mensagem', 520, 240, ['texto' => self::OUT_OF_HOURS]),
                $node('transferir_fora', 'transferir', 780, 240, ['fila_geral' => true]),
            ],
            'edges' => [
                ['from' => 'inicio', 'port' => 'out', 'to' => 'horario'],
                ['from' => 'horario', 'port' => 'true', 'to' => 'saudacao'],
                ['from' => 'horario', 'port' => 'false', 'to' => 'fora'],
                ['from' => 'saudacao', 'port' => 'out', 'to' => 'menu'],
                ['from' => 'menu', 'port' => 'setor', 'to' => 'encaminhamento'],
                ['from' => 'encaminhamento', 'port' => 'out', 'to' => 'transferir'],
                ['from' => 'fora', 'port' => 'out', 'to' => 'transferir_fora'],
            ],
        ];
    }

    /**
     * O fluxo mínimo de um workflow novo e vazio: só o Início.
     *
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return ['nodes' => [['id' => 'inicio', 'type' => 'inicio', 'x' => 0, 'y' => 0, 'data' => []]], 'edges' => []];
    }
}
