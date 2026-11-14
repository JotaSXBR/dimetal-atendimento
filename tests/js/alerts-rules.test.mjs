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

test('DISMISSALS: os tempos combinados (1, 2 e 3 min; som só até a próxima visita)', async () => {
    const { DISMISSALS } = await import('../../resources/js/alerts-rules.js');

    assert.equal(DISMISSALS.offline.ms, 60000);
    assert.equal(DISMISSALS.whatsapp.ms, 120000);
    assert.equal(DISMISSALS.sla.ms, 180000);
    assert.equal(DISMISSALS.audio.ms, null);
    assert.equal(DISMISSALS.audio.store, 'session');
    assert.equal(DISMISSALS.offline.store, 'local');
});

test('isDismissed: vale dentro do prazo e volta quando vence', async () => {
    const { dismissEntry, isDismissed } = await import('../../resources/js/alerts-rules.js');
    const entry = dismissEntry('offline', NOW, '1');

    assert.equal(entry.until, NOW + 60000);
    assert.equal(isDismissed(entry, NOW + 59999, '1', 'offline'), true);
    assert.equal(isDismissed(entry, NOW + 60000, '1', 'offline'), false);
});

test('isDismissed: assinatura diferente (conexão caiu de novo, outro canal) volta antes do prazo', async () => {
    const { dismissEntry, isDismissed } = await import('../../resources/js/alerts-rules.js');

    assert.equal(isDismissed(dismissEntry('offline', NOW, '1'), NOW + 1000, '2', 'offline'), false);
    assert.equal(isDismissed(dismissEntry('whatsapp', NOW, '3@100'), NOW + 1000, '3@100,4@200', 'whatsapp'), false);
    assert.equal(isDismissed(dismissEntry('whatsapp', NOW, '3@100'), NOW + 1000, '3@100', 'whatsapp'), true);
});

test('isDismissed: SLA só volta quando o número aumenta (15 → 14 segue escondida)', async () => {
    const { dismissEntry, isDismissed } = await import('../../resources/js/alerts-rules.js');
    const entry = dismissEntry('sla', NOW, '15');

    assert.equal(isDismissed(entry, NOW + 1000, '15', 'sla'), true);
    assert.equal(isDismissed(entry, NOW + 1000, '14', 'sla'), true);
    assert.equal(isDismissed(entry, NOW + 1000, '16', 'sla'), false);
});

test('isDismissed: o som dispensado não vence no mesmo acesso', async () => {
    const { dismissEntry, isDismissed } = await import('../../resources/js/alerts-rules.js');
    const entry = dismissEntry('audio', NOW, '');

    assert.equal(entry.until, null);
    assert.equal(isDismissed(entry, NOW + 10 * 3600 * 1000, '', 'audio'), true);
});

test('isDismissed: entrada ausente, corrompida ou de tipo errado não esconde nada', async () => {
    const { isDismissed } = await import('../../resources/js/alerts-rules.js');

    for (const entry of [null, undefined, 'x', 1, {}, { until: 'amanhã', signature: '1' }, { until: NOW + 1000 }, { until: NaN, signature: '1' }]) {
        assert.equal(isDismissed(entry, NOW, '1', 'offline'), false);
    }

    assert.equal(isDismissed({ until: NOW + 1000, signature: '1' }, NOW, '1', 'desconhecido'), false);
});

test('isDismissed: prazo absurdamente no futuro (relógio mudou) não esconde para sempre', async () => {
    const { isDismissed } = await import('../../resources/js/alerts-rules.js');

    assert.equal(isDismissed({ until: NOW + 365 * 24 * 3600 * 1000, signature: '1' }, NOW, '1', 'offline'), false);
});
