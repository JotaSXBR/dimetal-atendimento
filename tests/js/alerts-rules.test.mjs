import assert from 'node:assert/strict';
import test from 'node:test';
import { GRAVE_REPEAT_MS, CRITICAL_REPEAT_MS, MAX_REPEATS, audioStripHidden, isMuted, openAudio, repeatMs, soundFor, wantsBalloon } from '../../resources/js/alerts-rules.js';

const base = () => ({
    sound: { message: true, queue: true, assigned: true, sla: true },
    volume: 70,
    repeat: true,
    desktop_notifications: false,
    mute_critical_until: null,
});
const NOW = Date.parse('2026-10-30T12:00:00Z');

test('mensagem toca, mas não para a conversa aberta e em foco', () => {
    assert.equal(soundFor('message', base(), { ticketId: 5, openTicketId: 7, focused: true }), 'comum');
    assert.equal(soundFor('message', base(), { ticketId: 5, openTicketId: 5, focused: true }), null);
    assert.equal(soundFor('message', base(), { ticketId: 5, openTicketId: 5, focused: false }), 'comum');
});

test('cada tipo respeita o seu interruptor', () => {
    const s = base();
    s.sound.queue = false;
    s.sound.sla = false;
    assert.equal(soundFor('queue', s), null);
    assert.equal(soundFor('assigned', s), 'comum');
    assert.equal(soundFor('sla_warn', s), null);
    assert.equal(soundFor('sla_breached', s), null);
});

test('o crítico toca com o som crítico e o silêncio por tempo o cala', () => {
    const s = base();
    assert.equal(soundFor('connection_down', s, { now: NOW }), 'critico');
    s.mute_critical_until = '2026-10-30T12:15:00Z';
    assert.equal(isMuted(s, NOW), true);
    assert.equal(soundFor('connection_down', s, { now: NOW }), null);
    assert.equal(isMuted(s, Date.parse('2026-10-30T12:15:01Z')), false);
});

test('prazo de silêncio ilegível não cala nada', () => {
    const s = base();
    s.mute_critical_until = 'lixo';
    assert.equal(isMuted(s, NOW), false);
});

test('só crítico e SLA estourado repetem, e só se a repetição estiver ligada', () => {
    const s = base();
    assert.equal(repeatMs('connection_down', s), CRITICAL_REPEAT_MS);
    assert.equal(repeatMs('sla_breached', s), GRAVE_REPEAT_MS);
    assert.equal(repeatMs('message', s), null);
    assert.equal(repeatMs('sla_warn', s), null);
    s.repeat = false;
    assert.equal(repeatMs('connection_down', s), null);
    assert.equal(CRITICAL_REPEAT_MS, 60_000);
    assert.equal(GRAVE_REPEAT_MS, 120_000);
    assert.equal(MAX_REPEATS, 30);
});

test('balão só para os graves, com permissão, ligado e sem SLA desligado', () => {
    const s = base();
    s.desktop_notifications = true;
    assert.equal(wantsBalloon('connection_down', s, 'granted'), true);
    assert.equal(wantsBalloon('sla_breached', s, 'granted'), true);
    assert.equal(wantsBalloon('message', s, 'granted'), false);
    assert.equal(wantsBalloon('connection_down', s, 'denied'), false);
    s.sound.sla = false;
    assert.equal(wantsBalloon('sla_breached', s, 'granted'), false);
    assert.equal(wantsBalloon('connection_down', s, 'granted'), true);
    s.desktop_notifications = false;
    assert.equal(wantsBalloon('connection_down', s, 'granted'), false);
});

test('alerta de backup não toca, não repete e não vira balão', () => {
    assert.equal(soundFor('backup', base()), null);
    assert.equal(repeatMs('backup', base()), null);
    assert.equal(wantsBalloon('backup', { ...base(), desktop_notifications: true }, 'granted'), false);
});

test('o áudio é aberto já no carregamento, para o navegador dizer se o som está liberado', () => {
    class Liberado {
        constructor() {
            this.state = 'running';
        }
    }

    assert.equal(openAudio(Liberado)?.state, 'running');
    assert.equal(openAudio(undefined), null);
    assert.equal(openAudio(class { constructor() { throw new Error('bloqueado'); } }), null);
});

test('a faixa de som só aparece quando há som configurado e o navegador ainda não o liberou', () => {
    const comSom = { sound: { message: true } };

    assert.equal(audioStripHidden(comSom, { state: 'running' }), true);
    assert.equal(audioStripHidden(comSom, { state: 'suspended' }), false);
    assert.equal(audioStripHidden(comSom, null), false);
    assert.equal(audioStripHidden({ sound: { message: false } }, null), true);
});

test('quem já liberou o som neste navegador não vê a faixa de novo a cada refresh', () => {
    const comSom = { sound: { message: true } };

    assert.equal(audioStripHidden(comSom, { state: 'suspended' }, true), true);
    assert.equal(audioStripHidden(comSom, null, true), true);
    assert.equal(audioStripHidden(comSom, { state: 'suspended' }, false), false);
});

test('com alerta crítico ativo e o áudio ainda bloqueado, a faixa volta mesmo para quem já liberou o som antes', () => {
    const comSom = { sound: { message: true } };

    assert.equal(audioStripHidden(comSom, { state: 'suspended' }, true, true), false);
    assert.equal(audioStripHidden(comSom, null, true, true), false);
    assert.equal(audioStripHidden(comSom, { state: 'running' }, true, true), true);
    assert.equal(audioStripHidden({ sound: {} }, { state: 'suspended' }, true, true), true);
});
