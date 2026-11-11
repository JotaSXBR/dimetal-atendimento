import assert from 'node:assert/strict';
import test from 'node:test';
import { CATEGORIES, History, ICONS, arrange, categoryOf, duplicate, errorNodeIds, previewOf } from '../../resources/js/flow-editor/appearance.js';
import { BLOCKS } from '../../resources/js/flow-editor/nodes.js';

const lookups = { sectors: [{ id: 1, name: 'Vendas' }], tags: [{ id: 7, name: 'VIP' }] };

test('todo bloco tem categoria e ícone existentes', () => {
    for (const [type, block] of Object.entries(BLOCKS)) {
        assert.ok(CATEGORIES[categoryOf(type)], type);
        assert.ok(ICONS[block.icon], type);
    }
    assert.equal(categoryOf('desconhecido'), 'acao');
});

test('prévia: textos, vazio e corte em 60', () => {
    assert.deepEqual(previewOf('mensagem', { texto: 'Olá' }, lookups), ['Olá']);
    assert.deepEqual(previewOf('mensagem', { texto: '  ' }, lookups), ['(sem texto)']);
    const long = previewOf('pergunta', { texto: 'a'.repeat(200) }, lookups)[0];
    assert.equal(long.length, 60);
    assert.ok(long.endsWith('…'));
    assert.deepEqual(previewOf('midia', {}, lookups), ['(sem texto)']);
});

test('prévia: menus, condição, esperar, salvar, início e fim', () => {
    assert.deepEqual(previewOf('menu_setores', {}, lookups), ['Lista os setores do menu']);
    assert.deepEqual(previewOf('menu', { opcoes: ['A', 'B'] }, lookups), ['1 - A', '2 - B']);
    assert.deepEqual(previewOf('menu', { opcoes: ['A', 'B', 'C', 'D', 'E'] }, lookups), ['1 - A', '2 - B', '+3']);
    assert.deepEqual(previewOf('condicao', { kind: 'horario' }, lookups), ['Dentro do horário?']);
    assert.deepEqual(previewOf('condicao', { kind: 'variavel', var: 'x', op: 'igual', valor: '1' }, lookups), ['x é igual a 1']);
    assert.deepEqual(previewOf('condicao', { kind: 'nome' }, lookups), ['O contato já tem nome?']);
    assert.deepEqual(previewOf('condicao', { kind: 'retorno' }, lookups), ['Já foi atendido antes?']);
    assert.deepEqual(previewOf('esperar', { segundos: 4 }, lookups), ['Digitando por 4 s']);
    assert.deepEqual(previewOf('salvar_contato', { campo: 'name', variavel: 'resposta' }, lookups), ['name ← resposta']);
    assert.deepEqual(previewOf('inicio', {}, lookups), ['Começa aqui']);
    assert.deepEqual(previewOf('encerrar', {}, lookups), ['Encerra o ticket']);
});

test('prévia: setor e etiqueta, inclusive os que sumiram', () => {
    assert.deepEqual(previewOf('transferir', { setor_id: 1 }, lookups), ['Vendas']);
    assert.deepEqual(previewOf('transferir', { setor_id: null }, lookups), ['Setor do menu']);
    assert.deepEqual(previewOf('transferir', { setor_id: 99 }, lookups), ['Setor removido']);
    assert.deepEqual(previewOf('etiquetar', { tag_id: 7 }, lookups), ['VIP']);
    assert.deepEqual(previewOf('etiquetar', { tag_id: 99 }, lookups), ['Etiqueta removida']);
    assert.deepEqual(previewOf('transferir', {}, {}), ['Setor do menu']);
});

test('errorNodeIds só devolve blocos que existem', () => {
    const ids = errorNodeIds(['menu: sem ligação', 'fantasma: x', 'Nenhum caminho termina em Transferir.'], ['menu', 'a']);
    assert.deepEqual([...ids], ['menu']);
});

test('arrange: linear, ramo, ciclo e bloco solto', () => {
    const n = (id, type = 'mensagem') => ({ id, type });
    const linear = arrange([n('i', 'inicio'), n('a'), n('b')], [{ from: 'i', port: 'out', to: 'a' }, { from: 'a', port: 'out', to: 'b' }]);
    assert.deepEqual([linear.i.x, linear.a.x, linear.b.x], [0, 300, 600]);

    const ramo = arrange([n('i', 'inicio'), n('a'), n('b')], [{ from: 'i', port: 'out', to: 'a' }, { from: 'i', port: 'out', to: 'b' }]);
    assert.equal(ramo.a.x, ramo.b.x);
    assert.notEqual(ramo.a.y, ramo.b.y);

    const ciclo = arrange([n('i', 'inicio'), n('m', 'menu')], [{ from: 'i', port: 'out', to: 'm' }, { from: 'm', port: 'opt:1', to: 'm' }]);
    for (const p of Object.values(ciclo)) assert.ok(Number.isFinite(p.x) && Number.isFinite(p.y));

    const solto = arrange([n('i', 'inicio'), n('a'), n('s')], [{ from: 'i', port: 'out', to: 'a' }, { from: 'a', port: 'out', to: 'fantasma' }]);
    assert.equal(solto.s.x, 600);
    assert.ok(Object.values(solto).every((p) => Number.isFinite(p.x) && Number.isFinite(p.y)));
});

test('duplicate: id novo, +40, dados clonados; início recusado', () => {
    const node = { id: 'a', type: 'menu', x: 10, y: 20, data: { opcoes: ['A'] } };
    const copy = duplicate(node, 'b');
    assert.equal(copy.id, 'b');
    assert.equal(copy.x, 50);
    assert.equal(copy.y, 60);
    assert.deepEqual(copy.data, node.data);
    assert.notEqual(copy.data, node.data);
    assert.notEqual(copy.data.opcoes, node.data.opcoes);
    assert.equal(duplicate({ id: 'i', type: 'inicio', x: 0, y: 0, data: {} }, 'z'), null);
});

test('History: ida e volta, igual ignorado, limite e refazer zerado', () => {
    const h = new History();
    h.push('a');
    h.push('a');
    assert.equal(h.canUndo(), true);
    assert.equal(h.undo('b'), 'a');
    assert.equal(h.canUndo(), false);
    assert.equal(h.canRedo(), true);
    assert.equal(h.redo('a'), 'b');
    assert.equal(h.undo('b'), 'a');
    h.push('c');
    assert.equal(h.canRedo(), false);
    assert.equal(h.undo('x'), 'c');
    assert.equal(new History().undo('x'), null);
    assert.equal(new History().redo('x'), null);

    const lim = new History(50);
    for (let i = 0; i < 80; i++) lim.push(String(i));
    let n = 0;
    let cur = 'fim';
    while ((cur = lim.undo(cur)) !== null) n++;
    assert.equal(n, 50);
});
