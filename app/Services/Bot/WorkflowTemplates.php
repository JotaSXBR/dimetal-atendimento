<?php

namespace App\Services\Bot;

use App\Models\Sector;
use App\Services\Bot\Templates\Template;

/** Os modelos que "Novo workflow" oferece, na ordem em que aparecem. Cada um é só um grafo inicial: o resto é do editor. */
final class WorkflowTemplates
{
    public const BLANK = 'em_branco';

    public const STANDARD = 'padrao';

    public const WITH_HOURS = 'horario';

    public const BASIC_GROUP = 'Básicos';

    public const SEGMENT_GROUP = 'Por segmento';

    /** @var list<class-string<Template>> */
    private const SEGMENTS = [];

    /** @return list<array{key: string, name: string, description: string, group: string}> */
    public static function all(): array
    {
        $basic = [
            ['key' => self::BLANK, 'name' => 'Em branco', 'description' => 'Só o Início: você monta tudo.'],
            ['key' => self::STANDARD, 'name' => 'Atendimento padrão', 'description' => 'Saudação, menu de setores e encaminhamento com o protocolo.'],
            ['key' => self::WITH_HOURS, 'name' => 'Atendimento com horário', 'description' => 'Como o padrão, mas fora do expediente avisa o horário e coloca o cliente na fila.'],
        ];

        return [
            ...array_map(fn (array $t): array => [...$t, 'group' => self::BASIC_GROUP], $basic),
            ...array_map(fn (string $t): array => ['key' => $t::key(), 'name' => $t::name(), 'description' => $t::description(), 'group' => self::SEGMENT_GROUP], self::SEGMENTS),
        ];
    }

    /** @return array<string, mixed> o grafo do modelo, com os setores já resolvidos; chave desconhecida vale o em branco */
    public static function graph(string $key): array
    {
        return self::resolve($key)['graph'];
    }

    /**
     * O grafo do modelo com o nome de cada setor trocado pelo id do setor que existe aqui, e os nomes que não existem.
     *
     * @return array{graph: array<string, mixed>, missing: list<string>}
     */
    public static function resolve(string $key): array
    {
        $segment = array_find(self::SEGMENTS, fn (string $t): bool => $t::key() === $key);

        return self::resolveGraph(match (true) {
            $segment !== null => $segment::graph(),
            $key === self::STANDARD => DefaultWorkflow::graph(),
            $key === self::WITH_HOURS => DefaultWorkflow::withHours(),
            default => DefaultWorkflow::blank(),
        });
    }

    /**
     * Troca `transferir.data.setor` (o nome) por `setor_id` e remove o nome do grafo: nunca é gravado. Setor que não existe ou não
     * recebe tickets fica sem `setor_id` (o Transferir cai na fila padrão) e entra em `missing`, uma vez só.
     * shortcut: o nome é comparado só por caixa e espaço, sem acento; trocar por slug se alguém renomear com acento.
     *
     * @param  array<string, mixed>  $graph
     * @return array{graph: array<string, mixed>, missing: list<string>}
     */
    public static function resolveGraph(array $graph): array
    {
        $sectors = Sector::query()->get()->filter(fn (Sector $s): bool => $s->status->receivesTickets())
            ->mapWithKeys(fn (Sector $s): array => [mb_strtolower(trim($s->name)) => $s->id]);
        $missing = [];

        $nodes = array_map(function (mixed $node) use ($sectors, &$missing): mixed {
            if (! is_array($node) || ! is_array($node['data'] ?? null) || ! is_string($node['data']['setor'] ?? null)) {
                return $node;
            }

            $name = trim($node['data']['setor']);
            $id = $sectors->get(mb_strtolower($name));
            unset($node['data']['setor']);

            if ($id !== null) {
                $node['data']['setor_id'] = $id;
            } elseif (! in_array($name, $missing, true)) {
                $missing[] = $name;
            }

            return $node;
        }, is_array($graph['nodes'] ?? null) ? $graph['nodes'] : []);

        return ['graph' => [...$graph, 'nodes' => $nodes], 'missing' => $missing];
    }
}
