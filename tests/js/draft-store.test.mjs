import assert from 'node:assert/strict';
import test from 'node:test';
import { draftTicketId, draftTicketIds, parseDraft, staleDraftKeys } from '../../resources/js/draft-store.js';

const DAY = 86_400_000;
const WEEK = 7 * DAY;
const NOW = 10 * WEEK;

test('parseDraft lê o formato antigo (texto cru) e o novo (JSON com data)', () => {
    assert.deepEqual(parseDraft('oi'), { text: 'oi', at: null });
    assert.deepEqual(parseDraft('{"text":"oi","at":1}'), { text: 'oi', at: 1 });
    assert.deepEqual(parseDraft('{quebrado'), { text: '{quebrado', at: null });
    assert.deepEqual(parseDraft(null), { text: '', at: null });
});

test('staleDraftKeys devolve só as chaves do usuário vencidas; sem data não vence', () => {
    const old = JSON.stringify({ text: 'a', at: NOW - WEEK - 1 });
    const fresh = JSON.stringify({ text: 'b', at: NOW - DAY });
    const entries = [
        ['rascunho:1:10:reply', old],
        ['rascunho:1:11:reply', fresh],
        ['rascunho:1:12:note', 'texto antigo sem data'],
        ['rascunho:2:13:reply', old],
        ['outra-chave', old],
    ];
    assert.deepEqual(staleDraftKeys(entries, 1, NOW, WEEK), ['rascunho:1:10:reply']);
});

test('draftTicketId lê o id da chave do usuário, ou null', () => {
    assert.equal(draftTicketId('rascunho:1:10:reply', 1), 10);
    assert.equal(draftTicketId('rascunho:2:10:reply', 1), null);
    assert.equal(draftTicketId('rascunho:1:x:reply', 1), null);
});

test('draftTicketIds ordena do mais antigo e põe o formato antigo primeiro', () => {
    const at = (n) => JSON.stringify({ text: 'a', at: n });
    const entries = [
        ['rascunho:1:30:reply', at(300)],
        ['rascunho:1:10:reply', at(100)],
        ['rascunho:1:20:reply', 'texto cru'],
        ['rascunho:1:10:note', at(500)],
        ['rascunho:2:40:reply', at(50)],
    ];
    assert.deepEqual(draftTicketIds(entries, 1), [20, 10, 30]);
});
