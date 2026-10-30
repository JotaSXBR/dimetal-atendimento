import assert from 'node:assert/strict';
import test from 'node:test';
import { GRAVE_REPEAT_MS, CRITICAL_REPEAT_MS, MAX_REPEATS, isMuted, repeatMs, soundFor, wantsBalloon } from '../../resources/js/alerts-rules.js';

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
