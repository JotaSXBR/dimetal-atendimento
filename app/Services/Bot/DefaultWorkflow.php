<?php

namespace App\Services\Bot;

/** O modelo "Atendimento padrão": saudação, menu de setores e encaminhamento com o protocolo. */
final class DefaultWorkflow
{
    public const NAME = 'Atendimento padrão';

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
     * O fluxo mínimo de um workflow novo e vazio: só o Início.
     *
     * @return array<string, mixed>
     */
    public static function blank(): array
    {
        return ['nodes' => [['id' => 'inicio', 'type' => 'inicio', 'x' => 0, 'y' => 0, 'data' => []]], 'edges' => []];
    }
}
