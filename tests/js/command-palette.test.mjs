import assert from 'node:assert/strict';
import test from 'node:test';
import { isShortcut, stepActive } from '../../resources/js/command-palette.js';

test('stepActive: circular nos dois sentidos', () => {
    assert.equal(stepActive(0, 3, 'ArrowDown'), 1);
    assert.equal(stepActive(2, 3, 'ArrowDown'), 0);
    assert.equal(stepActive(0, 3, 'ArrowUp'), 2);
    assert.equal(stepActive(1, 3, 'ArrowUp'), 0);
});

test('stepActive: sem seleção, descer vai ao primeiro e subir ao último', () => {
    assert.equal(stepActive(-1, 3, 'ArrowDown'), 0);
    assert.equal(stepActive(-1, 3, 'ArrowUp'), 2);
});

test('stepActive: lista vazia não seleciona nada', () => {
    assert.equal(stepActive(-1, 0, 'ArrowDown'), -1);
    assert.equal(stepActive(0, 0, 'ArrowUp'), -1);
});

test('isShortcut: Ctrl+K e ⌘K, em qualquer caixa, e nada com Alt ou Shift', () => {
    assert.equal(isShortcut({ ctrlKey: true, metaKey: false, altKey: false, shiftKey: false, key: 'k' }), true);
    assert.equal(isShortcut({ ctrlKey: false, metaKey: true, altKey: false, shiftKey: false, key: 'K' }), true);
    assert.equal(isShortcut({ ctrlKey: false, metaKey: false, altKey: false, shiftKey: false, key: 'k' }), false);
    assert.equal(isShortcut({ ctrlKey: true, metaKey: false, altKey: true, shiftKey: false, key: 'k' }), false);
    assert.equal(isShortcut({ ctrlKey: true, metaKey: false, altKey: false, shiftKey: true, key: 'k' }), false);
    assert.equal(isShortcut({ ctrlKey: true, metaKey: false, altKey: false, shiftKey: false, key: 'j' }), false);
});
