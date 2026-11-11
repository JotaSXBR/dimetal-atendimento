<?php

namespace App\Services\Bot;

/** Os modelos que "Novo workflow" oferece, na ordem em que aparecem. Cada um é só um grafo inicial: o resto é do editor. */
final class WorkflowTemplates
{
    public const BLANK = 'em_branco';

    public const STANDARD = 'padrao';

    public const WITH_HOURS = 'horario';

    /** @return list<array{key: string, name: string, description: string}> */
    public static function all(): array
    {
        return [
            ['key' => self::BLANK, 'name' => 'Em branco', 'description' => 'Só o Início: você monta tudo.'],
            ['key' => self::STANDARD, 'name' => 'Atendimento padrão', 'description' => 'Saudação, menu de setores e encaminhamento com o protocolo.'],
            ['key' => self::WITH_HOURS, 'name' => 'Atendimento com horário', 'description' => 'Como o padrão, mas fora do expediente avisa o horário e coloca o cliente na fila.'],
        ];
    }

    /** @return array<string, mixed> o grafo do modelo; chave desconhecida vale o em branco */
    public static function graph(string $key): array
    {
        return match ($key) {
            self::STANDARD => DefaultWorkflow::graph(),
            self::WITH_HOURS => DefaultWorkflow::withHours(),
            default => DefaultWorkflow::blank(),
        };
    }
}
