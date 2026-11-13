import assert from 'node:assert/strict';
import test from 'node:test';
import { label } from '../../resources/js/relative-time.js';

const NOW = Date.parse('2026-11-07T12:00:00Z');
const at = (seconds) => new Date(NOW - seconds * 1000).toISOString();

// O servidor escreve estes mesmos textos (App\Support\WaitLabel::narrow, testado em tests/Unit/WaitLabelTest.php).
test('o texto curto do navegador é o que o servidor escreve no card', () => {
    assert.equal(label(at(0), 'narrow', NOW), 'há 0 seg.');
    assert.equal(label(at(5), 'narrow', NOW), 'há 5 seg.');
    assert.equal(label(at(59), 'narrow', NOW), 'há 59 seg.');
    assert.equal(label(at(60), 'narrow', NOW), 'há 1 min.');
    assert.equal(label(at(12 * 60), 'narrow', NOW), 'há 12 min.');
    assert.equal(label(at(59 * 60), 'narrow', NOW), 'há 59 min.');
    assert.equal(label(at(3600), 'narrow', NOW), 'há 1 h');
    assert.equal(label(at(23 * 3600 + 3540), 'narrow', NOW), 'há 23 h');
    assert.equal(label(at(86400), 'narrow', NOW), 'há 1 dia');
    assert.equal(label(at(2 * 86400), 'narrow', NOW), 'há 2 dias');
    assert.equal(label(at(40 * 86400), 'narrow', NOW), 'há 40 dias');
});

test('instante no futuro não vira tempo negativo', () => {
    assert.equal(label(new Date(NOW + 300000).toISOString(), 'narrow', NOW), 'há 0 seg.');
});
