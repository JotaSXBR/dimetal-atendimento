<?php

namespace App\Services\Bot;

/**
 * O modelo "Atendimento padrão", o fluxo do beta: dentro do expediente, saudação com o nome da empresa, menu de setores e
 * encaminhamento com o protocolo; fora dele, o aviso do horário e a entrega à fila geral, sem setor (o SLA só começa a contar
 * quando o expediente abre).
 */
final class DefaultWorkflow
{
    public const NAME = 'Atendimento padrão';

    /** O aviso de fora do expediente, usado pelos modelos por segmento. */
    public const OUT_OF_HOURS = 'Olá, {{nome}}! Nosso horário de atendimento é {{horario}}. Retornamos {{proximo_horario}} e você já está na fila para ser atendido.';

    /** @return array<string, mixed> */
    public static function graph(): array
    {
        $node = fn (string $id, string $type, int $x, int $y = 0, array $data = []): array => ['id' => $id, 'type' => $type, 'x' => $x, 'y' => $y, 'data' => $data];

        return [
            'nodes' => [
                $node('inicio', 'inicio', 0),
                $node('horario', 'condicao', 260, 0, ['kind' => 'horario']),
                $node('saudacao', 'mensagem', 520, 0, ['texto' => 'Olá, {{nome}}! Bem-vindo a {{empresa}}.']),
                $node('menu', 'menu_setores', 780, 0, ['texto' => 'Com qual setor você quer falar? Responda com o número da opção:', 'invalido' => 'Resposta inválida.', 'timeout_min' => 5]),
                $node('encaminhamento', 'mensagem', 1040, 0, ['texto' => 'Certo! Seu protocolo é {{protocolo}}. Você entrou na fila de {{setor}} e um atendente já te responde em instantes.']),
                $node('transferir', 'transferir', 1300),
                $node('fora', 'mensagem', 520, 240, ['texto' => "Olá, {{nome}}! \nBem-vindo a {{empresa}}. \nNosso horário de atendimento é {{horario}}. Retornamos {{proximo_horario}} e você já está na fila para ser atendido."]),
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
            'settings' => ['mensagens' => ['encaminhamento_setor' => "Vou encaminhar você para o setor: {{setor}} \nSeu protocolo é {{protocolo}}"]],
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
