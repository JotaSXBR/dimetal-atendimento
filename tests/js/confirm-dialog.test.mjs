import assert from 'node:assert/strict';
import test from 'node:test';
import { splitConfirm } from '../../resources/js/confirm-dialog.js';

test('splitConfirm: título até o primeiro ? e texto com o resto', () => {
    assert.deepEqual(splitConfirm('Apagar o setor Vendas? Os tickets antigos continuam mostrando o nome.'), {
        title: 'Apagar o setor Vendas?',
        text: 'Os tickets antigos continuam mostrando o nome.',
    });
});

test('splitConfirm: sem ? o título é a mensagem inteira', () => {
    assert.deepEqual(splitConfirm('Apagar tudo'), { title: 'Apagar tudo', text: '' });
});

test('splitConfirm: ? no fim deixa o texto vazio', () => {
    assert.deepEqual(splitConfirm('Remover este feriado?'), { title: 'Remover este feriado?', text: '' });
});

test('splitConfirm: apara espaços sobrando', () => {
    assert.deepEqual(splitConfirm('  Apagar?   Sem volta.  '), { title: 'Apagar?', text: 'Sem volta.' });
});

const el = (attrs) => ({ getAttribute: (name) => attrs[name] ?? null, attributes: Object.entries(attrs).map(([name, value]) => ({ name, value })) });

test('actionKey/sameAction: o mesmo elemento confirma; outro item no mesmo nó (morph) não', async () => {
    const { actionKey, sameAction } = await import('../../resources/js/confirm-dialog.js');
    const key = actionKey(el({ 'wire:click': 'delete(1)', 'data-confirm': 'Apagar a etiqueta A?' }));

    assert.equal(sameAction(key, el({ 'wire:click': 'delete(1)', 'data-confirm': 'Apagar a etiqueta A?' })), true);
    assert.equal(sameAction(key, el({ 'wire:click': 'delete(2)', 'data-confirm': 'Apagar a etiqueta B?' })), false);
    assert.equal(sameAction(key, el({ 'wire:click': 'delete(1)', 'data-confirm': 'Apagar a etiqueta B?' })), false);
    assert.equal(sameAction(key, el({})), false);
});

test('actionKey: lê também wire:click com modificador (wire:click.prevent)', async () => {
    const { actionKey, sameAction } = await import('../../resources/js/confirm-dialog.js');
    const key = actionKey(el({ 'wire:click.prevent': 'delete(1)', 'data-confirm': 'Apagar?' }));

    assert.equal(sameAction(key, el({ 'wire:click.prevent': 'delete(1)', 'data-confirm': 'Apagar?' })), true);
    assert.equal(sameAction(key, el({ 'wire:click.prevent': 'delete(2)', 'data-confirm': 'Apagar?' })), false);
});
