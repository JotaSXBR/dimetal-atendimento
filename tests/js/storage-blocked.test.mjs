import assert from 'node:assert/strict';
import { after, test } from 'node:test';

// localStorage bloqueado (navegador restrito): lê e grava lançam SecurityError; o compositor e o painel de detalhes seguem.
const blocked = { getItem() { throw new Error('SecurityError'); }, setItem() { throw new Error('SecurityError'); }, removeItem() { throw new Error('SecurityError'); } };
const factories = {};
let initHandler;

const originalDocument = globalThis.document;
const originalWindow = globalThis.window;

globalThis.document = { addEventListener: (event, handler) => { initHandler = handler; } };
globalThis.window = { localStorage: blocked, Alpine: { data: (name, factory) => { factories[name] = factory; } } };

await import('../../resources/js/composer-mode.js');
initHandler();
await import('../../resources/js/ticket-details.js');
initHandler();

after(() => {
    // Atribuir `undefined` deixaria a propriedade criada; o original ausente tem de sumir.
    for (const [nome, original] of [['document', originalDocument], ['window', originalWindow]]) {
        if (original === undefined) delete globalThis[nome];
        else globalThis[nome] = original;
    }
});

test('rascunho do compositor sem localStorage não lança', () => {
    const composer = factories.composerMode({}, 1, 2);

    assert.equal(composer.load('reply'), '');
    assert.doesNotThrow(() => composer.save('reply', 'texto'));
    assert.doesNotThrow(() => composer.save('reply', ''));
});

test('painel de detalhes sem localStorage abre e alterna', () => {
    const details = factories.ticketDetails();

    assert.equal(details.details, true);
    assert.doesNotThrow(() => details.toggle());
    assert.equal(details.details, false);
});
