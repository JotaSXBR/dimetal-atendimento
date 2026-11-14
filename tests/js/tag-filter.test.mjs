import assert from 'node:assert/strict';
import test from 'node:test';
import { filterTags } from '../../resources/js/tag-filter.js';

const TAGS = [
    { id: 1, name: 'Orçamento' },
    { id: 2, name: 'Urgente' },
];

test('filterTags: ignora acento e maiúscula', () => {
    assert.deepEqual(filterTags(TAGS, 'orcamento').map((t) => t.id), [1]);
    assert.deepEqual(filterTags(TAGS, 'URG').map((t) => t.id), [2]);
});

test('filterTags: consulta vazia ou só com espaços devolve todas na ordem recebida', () => {
    assert.deepEqual(filterTags(TAGS, '').map((t) => t.id), [1, 2]);
    assert.deepEqual(filterTags(TAGS, '   ').map((t) => t.id), [1, 2]);
});

test('filterTags: sem correspondência devolve lista vazia', () => {
    assert.deepEqual(filterTags(TAGS, 'zzz'), []);
});

import { activeForQuery, buildRows, stepActive } from '../../resources/js/tag-filter.js';

const GROUPED = [
    { id: 1, name: 'Revenda', color: 'azul', group: 'Do contato' },
    { id: 2, name: 'Fornecedor', color: 'ceu', group: 'Do contato' },
    { id: 3, name: 'Orçamento', color: 'rosa', group: 'Da conversa' },
];

test('buildRows: o título do grupo vem só na primeira linha de cada grupo', () => {
    const rows = buildRows(GROUPED, '', { listId: 'tp' });

    assert.deepEqual(rows.map((r) => r.header), [true, false, true]);
    assert.deepEqual(rows.map((r) => r.domId), ['tp-0', 'tp-1', 'tp-2']);
});

test('buildRows: no modo filtro a primeira linha limpa o filtro, mas só com a consulta vazia', () => {
    const semConsulta = buildRows(GROUPED, '', { filtering: true, clearLabel: 'Todas as etiquetas' });
    const comConsulta = buildRows(GROUPED, 'orca', { filtering: true, clearLabel: 'Todas as etiquetas' });

    assert.equal(semConsulta[0].id, '');
    assert.equal(semConsulta[0].name, 'Todas as etiquetas');
    assert.equal(semConsulta[0].hasDot, false);
    assert.deepEqual(comConsulta.map((r) => r.id), [3]);
});

test('buildRows: marca só a linha ativa e dá a cor da etiqueta ao ponto', () => {
    const rows = buildRows(GROUPED, '', { active: 1 });

    assert.deepEqual(rows.map((r) => r.active), [false, true, false]);
    assert.equal(rows[1].rowClass, 'bg-canvas');
    assert.equal(rows[0].rowClass, '');
    assert.equal(rows[2].dotClass, 'tag-rosa');
});

test('buildRows: sem correspondência não há linhas, nem a de limpar (consulta não vazia)', () => {
    assert.deepEqual(buildRows(GROUPED, 'zzz', { filtering: true, clearLabel: 'Todas' }), []);
});

test('stepActive: sem linha ativa, para baixo vai à primeira e para cima à última', () => {
    assert.equal(stepActive(-1, 1, 3), 0);
    assert.equal(stepActive(-1, -1, 3), 2);
});

test('stepActive: anda uma linha e para nas pontas', () => {
    assert.equal(stepActive(0, 1, 3), 1);
    assert.equal(stepActive(2, 1, 3), 2);
    assert.equal(stepActive(1, -1, 3), 0);
    assert.equal(stepActive(0, -1, 3), 0);
});

test('stepActive: lista vazia nunca tem linha ativa', () => {
    assert.equal(stepActive(-1, 1, 0), -1);
    assert.equal(stepActive(0, -1, 0), -1);
});

test('activeForQuery: só há linha pré-escolhida depois de digitar (Enter não aplica nada ao apenas focar)', () => {
    assert.equal(activeForQuery(''), -1);
    assert.equal(activeForQuery('   '), -1);
    assert.equal(activeForQuery('or'), 0);
});
