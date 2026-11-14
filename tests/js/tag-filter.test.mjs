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
