import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isComingUp } from '../../resources/js/system-update.js';

test('sem rede e respostas do proxy enquanto o app reinicia contam como "ainda subindo"', () => {
    // Livewire 4.4.8 entrega falha de rede ao hook `request` como 503.
    for (const status of [0, 404, 502, 503, 504]) {
        assert.equal(isComingUp(status), true, String(status));
    }
});

test('erros do próprio app seguem o caminho normal do Livewire', () => {
    for (const status of [403, 419, 422, 500]) {
        assert.equal(isComingUp(status), false, String(status));
    }
});
