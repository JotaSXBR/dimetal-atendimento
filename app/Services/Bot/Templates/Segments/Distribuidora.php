<?php

namespace App\Services\Bot\Templates\Segments;

use App\Services\Bot\Templates\FlowBuilder;
use App\Services\Bot\Templates\Template;

/** Distribuidora: cada opção pede o CNPJ e o que o setor precisa, deixa tudo numa nota para a equipe e entrega ao setor certo. */
final class Distribuidora implements Template
{
    public static function key(): string
    {
        return 'distribuidora';
    }

    public static function name(): string
    {
        return 'Distribuidora';
    }

    public static function description(): string
    {
        return 'Menu de pedido ou orçamento, 2ª via de boleto, entrega, troca ou devolução e falar com o vendedor. Pede o CNPJ e os dados de cada caso, deixa uma nota para a equipe e entrega a Vendas, Financeiro ou SAC; não consulta pedido, boleto nem estoque.';
    }

    public static function graph(): array
    {
        $builder = FlowBuilder::start()->opening(
            'Olá, {{nome}}! Bem-vindo a {{empresa}}.',
            'Como podemos ajudar? Responda com o número da opção:',
            ['Pedido ou orçamento', '2ª via de boleto', 'Entrega', 'Troca ou devolução', 'Falar com meu vendedor'],
        );

        $cnpj = ['cnpj', 'Para localizar seu cadastro, informe o CNPJ da empresa:', 'cnpj'];
        $invoice = ['nota', 'Qual o número da nota fiscal?', 'numero'];

        self::route($builder, 1, 'pedido', [$cnpj, ['itens', 'Quais produtos e quantidades você precisa?']], 'Pedido/orçamento. CNPJ {{cnpj}}. Itens: {{itens}}', 'Vendas');
        self::route($builder, 2, 'boleto', [$cnpj, $invoice], '2ª via de boleto. CNPJ {{cnpj}}. Nota {{nota}}', 'Financeiro');
        self::route($builder, 3, 'entrega', [$cnpj, $invoice], 'Consulta de entrega. CNPJ {{cnpj}}. Nota {{nota}}', 'SAC');
        self::route($builder, 4, 'troca', [$cnpj, $invoice, ['motivo', 'Qual o motivo da troca ou devolução?']], 'Troca/devolução. CNPJ {{cnpj}}. Nota {{nota}}. Motivo: {{motivo}}', 'SAC');
        self::route($builder, 5, 'vendedor', [$cnpj], 'Cliente pede o vendedor. CNPJ {{cnpj}}', 'Vendas');

        return $builder->graph();
    }

    /**
     * A opção `$option` do menu: as perguntas, a nota, o aviso com o protocolo e a entrega ao setor.
     *
     * @param  list<array{0: string, 1: string, 2?: string}>  $questions  `[variável, texto, validação]`
     */
    private static function route(FlowBuilder $builder, int $option, string $slug, array $questions, string $note, string $sector): void
    {
        $steps = array_map(fn (array $q): array => [
            "{$slug}_{$q[0]}",
            'pergunta',
            ['texto' => $q[1], 'variavel' => $q[0], ...(isset($q[2]) ? ['validacao' => $q[2]] : []), 'timeout_min' => 10],
        ], $questions);

        $builder->branch('menu', "opt:{$option}", [
            ...$steps,
            ["{$slug}_registro", 'nota', ['texto' => $note]],
            ["{$slug}_aviso", 'mensagem', ['texto' => 'Certo! Seu protocolo é {{protocolo}}. Você entrou na fila e um atendente responde em instantes.']],
            ["{$slug}_transferir", 'transferir', ['setor' => $sector]],
        ]);
    }
}
