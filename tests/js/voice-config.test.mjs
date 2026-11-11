import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { VOICE_BITS_PER_SECOND, VOICE_CONSTRAINTS, voiceFile } from '../../resources/js/voice-config.js';

const webm = new Uint8Array(readFileSync(new URL('./fixtures/voice-live.webm', import.meta.url)));

test('a configuração pede 32 kbps, mono a 48 kHz e o processamento de voz do navegador ligado', () => {
    assert.equal(VOICE_BITS_PER_SECOND, 32000);
    assert.deepEqual(VOICE_CONSTRAINTS, {
        audio: { channelCount: { ideal: 1 }, sampleRate: { ideal: 48000 }, echoCancellation: true, noiseSuppression: true, autoGainControl: true },
    });
});

test('voiceFile troca o WebM por OGG quando o remux funciona', async () => {
    const result = await voiceFile(new Blob([webm], { type: 'audio/webm;codecs=opus' }), 'audio/webm;codecs=opus');

    assert.equal(result.mime, 'audio/ogg');
    assert.equal(result.blob.type, 'audio/ogg');
    assert.ok(result.blob.size > 1000);
});

test('voiceFile mantém o original quando o remux falha ou o formato é outro', async () => {
    const garbage = new Blob([new Uint8Array([1, 2, 3, 4])], { type: 'audio/webm' });
    const failed = await voiceFile(garbage, 'audio/webm');

    assert.equal(failed.blob, garbage);
    assert.equal(failed.mime, 'audio/webm');

    for (const mime of ['audio/mp4', 'audio/ogg;codecs=opus', 'audio/ogg']) {
        const blob = new Blob([webm], { type: mime });
        const same = await voiceFile(blob, mime);

        assert.equal(same.blob, blob, mime);
        assert.equal(same.mime, mime);
    }
});
