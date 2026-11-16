import assert from 'node:assert/strict';
import test from 'node:test';
import { draftTicketIds, parseDraft, staleDraftKeys } from '../../resources/js/draft-store.js';

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

test('draftTicketIds extrai os ids únicos do usuário', () => {
    const keys = ['rascunho:1:10:reply', 'rascunho:1:10:note', 'rascunho:1:11:reply', 'rascunho:2:12:reply', 'x'];
    assert.deepEqual(draftTicketIds(keys, 1), [10, 11]);
});
