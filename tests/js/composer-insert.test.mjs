import assert from 'node:assert/strict';
import test from 'node:test';
import { insertAt } from '../../resources/js/composer-insert.js';

test('insertAt: no meio, no início e no fim, com o cursor logo depois do texto', () => {
    assert.deepEqual(insertAt('Olá mundo', 3, 3, ' 😀'), { value: 'Olá 😀 mundo', caret: 6 });
    assert.deepEqual(insertAt('mundo', 0, 0, 'Oi '), { value: 'Oi mundo', caret: 3 });
    assert.deepEqual(insertAt('Oi', 2, 2, '!'), { value: 'Oi!', caret: 3 });
});

test('insertAt: a seleção é substituída', () => {
    assert.deepEqual(insertAt('Bom dia, Maria', 9, 14, 'João'), { value: 'Bom dia, João', caret: 13 });
});

test('insertAt: índices fora do intervalo são limitados ao texto', () => {
    assert.deepEqual(insertAt('abc', -5, 99, 'X'), { value: 'X', caret: 1 });
    assert.deepEqual(insertAt('abc', 99, 99, 'X'), { value: 'abcX', caret: 4 });
    assert.deepEqual(insertAt('', 0, 0, 'X'), { value: 'X', caret: 1 });
});

test('insertAt: início maior que o fim vale como cursor simples', () => {
    assert.deepEqual(insertAt('abcd', 3, 1, 'X'), { value: 'abcXd', caret: 4 });
});
