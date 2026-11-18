import assert from 'node:assert/strict';
import test from 'node:test';
import { centerScrollLeft } from '../../resources/js/scroll-center.js';

test('centerScrollLeft: item fora da vista à direita fica no meio do contêiner', () => {
    // Contêiner de 294 px em x 48; item de 107 px em x 605 (rolagem 0): meio do item vai para o meio do contêiner.
    assert.equal(centerScrollLeft({ container: { left: 48, width: 294 }, item: { left: 605, width: 107 }, scrollLeft: 0 }), 463.5);
});

test('centerScrollLeft: soma a rolagem que o contêiner já tem', () => {
    assert.equal(centerScrollLeft({ container: { left: 0, width: 300 }, item: { left: 100, width: 100 }, scrollLeft: 200 }), 200);
});
