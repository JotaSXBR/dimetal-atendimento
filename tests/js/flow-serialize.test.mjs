import assert from 'node:assert/strict';
import test from 'node:test';
import { BLOCKS, PALETTE_SHORTCUTS, defaultData, outputsFor } from '../../resources/js/flow-editor/nodes.js';
import { fromGraph, messagesOf, toGraph, withMessages } from '../../resources/js/flow-editor/serialize.js';

const graph = {
    nodes: [
        { id: 'i', type: 'inicio', x: 0, y: 0, data: {} },
        { id: 'm', type: 'menu', x: 200, y: 10, data: { texto: 'Escolha', opcoes: ['A', 'B'] } },
        { id: 't', type: 'transferir', x: 400, y: 20, data: {} },
    ],
    edges: [
        { from: 'i', port: 'out', to: 'm' },
        { from: 'm', port: 'opt:1', to: 'm' },
        { from: 'm', port: 'opt:2', to: 't' },
    ],
};

test('ida e volta do grafo não perde nada', () => {
    const { nodes, connections } = fromGraph(graph);

    assert.equal(nodes.length, 3);
    assert.deepEqual(connections[1], { source: 'm', sourceOutput: 'opt:1', target: 'm' });
    assert.deepEqual(toGraph(nodes, connections), graph);
});

test('toGraph descarta ligação com bloco que não existe e arredonda a posição', () => {
    const out = toGraph(
        [{ id: 'a', type: 'mensagem', x: 10.7, y: 3.2, data: { texto: 'x' } }],
        [{ source: 'a', sourceOutput: 'out', target: 'fantasma' }],
    );

    assert.deepEqual(out.edges, []);
    assert.equal(out.nodes[0].x, 11);
    assert.equal(out.nodes[0].y, 3);
});

test('as portas de saída seguem as mesmas regras do servidor', () => {
    assert.deepEqual(outputsFor('mensagem', {}).map((o) => o.port), ['out']);
    assert.deepEqual(outputsFor('menu_setores', {}).map((o) => o.port), ['setor']);
    assert.deepEqual(outputsFor('condicao', {}).map((o) => o.port), ['true', 'false']);
    assert.deepEqual(outputsFor('condicao', { kind: 'horario' }), [{ port: 'true', label: 'Aberto' }, { port: 'false', label: 'Fechado' }]);
    assert.deepEqual(outputsFor('condicao', { kind: 'variavel' }).map((o) => o.label), ['Sim', 'Não']);
    assert.deepEqual(outputsFor('condicao', {}).map((o) => o.label), ['Sim', 'Não']);
    assert.deepEqual(outputsFor('menu', { opcoes: ['A', 'B', 'C'] }).map((o) => o.port), ['opt:1', 'opt:2', 'opt:3']);
    assert.deepEqual(outputsFor('menu', { opcoes: [] }).map((o) => o.port), ['opt:1']);
    assert.deepEqual(outputsFor('transferir', {}), []);
    assert.deepEqual(outputsFor('encerrar', {}), []);
});

test('todo bloco da paleta tem rótulo e dados iniciais; só o início não entra na paleta', () => {
    const types = Object.keys(BLOCKS);

    assert.equal(types.length, 13);
    assert.ok(types.every((t) => BLOCKS[t].label.length > 0));
    assert.deepEqual(defaultData('menu').opcoes, ['Opção 1', 'Opção 2']);
    assert.equal(defaultData('pergunta').validacao, 'texto');
    assert.equal(BLOCKS.inicio.palette, false);
    assert.equal(BLOCKS.mensagem.palette, true);
});

test('withMessages junta as mensagens ao grafo sem mexer em blocos e ligações', () => {
    const out = withMessages(graph, { opcao_invalida: 'Tente de novo' });

    assert.deepEqual(out.nodes, graph.nodes);
    assert.deepEqual(out.edges, graph.edges);
    assert.deepEqual(out.settings, { mensagens: { opcao_invalida: 'Tente de novo' } });
});

test('withMessages descarta o que não é texto e mantém o rodapé vazio', () => {
    const out = withMessages(graph, { a: 'ok', b: undefined, c: null, d: 5, e: ['x'], rodape_sair: '' });

    assert.deepEqual(out.settings.mensagens, { a: 'ok', rodape_sair: '' });
});

test('messagesOf devolve as mensagens do grafo ou um objeto vazio', () => {
    assert.deepEqual(messagesOf(graph), {});
    assert.deepEqual(messagesOf({ ...graph, settings: { mensagens: { a: 'x' } } }), { a: 'x' });
    assert.deepEqual(messagesOf({ ...graph, settings: { mensagens: 'quebrado' } }), {});
    assert.deepEqual(messagesOf(null), {});
});

test('atalhos da paleta criam um bloco já configurado', () => {
    const horario = PALETTE_SHORTCUTS.find((s) => s.key === 'horario');

    assert.equal(horario.label, 'Horário de atendimento');
    assert.equal(horario.type, 'condicao');
    assert.deepEqual(horario.data, { kind: 'horario' });
    assert.ok(BLOCKS[horario.type], 'o tipo do atalho existe');
});
