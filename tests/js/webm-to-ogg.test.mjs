import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { buildOgg, opusPacketSamples, parseWebmOpus, webmToOgg } from '../../resources/js/webm-to-ogg.js';

const fixture = new Uint8Array(readFileSync(new URL('./fixtures/voice-live.webm', import.meta.url)));

// ---- Leitor de OGG só para os testes (independente do escritor) -------------------------------------------------------------

function crc32Ogg(bytes) {
    let crc = 0;

    for (const byte of bytes) {
        crc ^= byte << 24;

        for (let bit = 0; bit < 8; bit++) {
            crc = crc & 0x80000000 ? (crc << 1) ^ 0x04c11db7 : crc << 1;
        }
    }

    return crc >>> 0;
}

function readPages(ogg) {
    const pages = [];
    let at = 0;

    while (at < ogg.length) {
        assert.equal(String.fromCharCode(...ogg.slice(at, at + 4)), 'OggS', `OggS na posição ${at}`);
        const view = new DataView(ogg.buffer, ogg.byteOffset + at);
        const count = ogg[at + 26];
        const lacing = [...ogg.slice(at + 27, at + 27 + count)];
        const size = lacing.reduce((a, b) => a + b, 0);
        const length = 27 + count + size;
        const page = ogg.slice(at, at + length);
        const stored = new DataView(page.buffer, page.byteOffset).getUint32(22, true);
        const zeroed = page.slice();
        zeroed.fill(0, 22, 26);

        pages.push({
            type: ogg[at + 5],
            granule: view.getBigUint64(6, true),
            serial: view.getUint32(14, true),
            sequence: view.getUint32(18, true),
            crcOk: crc32Ogg(zeroed) === stored,
            lacing,
            body: ogg.slice(at + 27 + count, at + length),
        });
        at += length;
    }

    return pages;
}

function packetsOf(pages) {
    const packets = [];
    let current = [];

    for (const page of pages) {
        let offset = 0;

        for (const size of page.lacing) {
            current.push(page.body.slice(offset, offset + size));
            offset += size;

            if (size < 255) {
                packets.push(Uint8Array.from(current.flatMap((part) => [...part])));
                current = [];
            }
        }
    }

    return packets;
}

// ---- Construtor de WebM sintético ---------------------------------------------------------------------------------------------

const idBytes = (id) => [...id.toString(16).padStart(id > 0xffffff ? 8 : id > 0xffff ? 6 : id > 0xff ? 4 : 2, '0').match(/../g)].map((h) => parseInt(h, 16));
const sizeBytes = (n) => (n < 127 ? [0x80 | n] : [0x40 | (n >> 8), n & 0xff]);
const element = (id, payload, unknown = false) => [...idBytes(id), ...(unknown ? [0x01, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff, 0xff] : sizeBytes(payload.length)), ...payload];
const ascii = (text) => [...text].map((c) => c.charCodeAt(0));

function synthWebm({ channels = 1, codec = 'A_OPUS', lacing = 0, truncate = false } = {}) {
    const head = [...ascii('OpusHead'), 1, channels, 0x38, 0x01, 0x80, 0xbb, 0, 0, 0, 0, 0];
    const track = element(0xae, [...element(0xd7, [1]), ...element(0x86, ascii(codec)), ...element(0x63a2, head), ...element(0x9f, [channels])]);
    const block = (n) => element(0xa3, [0x81, 0x00, n * 20, 0x80 | lacing, 0xf8, n, n]);
    const cluster = element(0x1f43b675, [...element(0xe7, [0]), ...block(0), ...block(1), ...block(2)], true);
    const bytes = [...element(0x1a45dfa3, element(0x4282, ascii('webm'))), ...element(0x18538067, [...element(0x1654ae6b, track), ...cluster], true)];

    return Uint8Array.from(truncate ? bytes.slice(0, bytes.length - 12) : bytes);
}

// ---- Testes -------------------------------------------------------------------------------------------------------------------

test('parse: lê a faixa Opus mono e os pacotes do WebM de fixture (Segment e Cluster de tamanho desconhecido)', () => {
    const parsed = parseWebmOpus(fixture);

    assert.ok(parsed, 'o fixture precisa ser reconhecido');
    assert.equal(String.fromCharCode(...parsed.head.slice(0, 8)), 'OpusHead');
    assert.equal(parsed.head[9], 1, 'mono');
    assert.ok(parsed.packets.length >= 60 && parsed.packets.length <= 80, `pacotes: ${parsed.packets.length}`);
});

test('parse: WebM sintético de 3 pacotes e recusas (estéreo, outro codec, lacing, truncado, lixo)', () => {
    assert.equal(parseWebmOpus(synthWebm())?.packets.length, 3);
    assert.equal(parseWebmOpus(synthWebm({ channels: 2 })), null);
    assert.equal(parseWebmOpus(synthWebm({ codec: 'A_VORBIS' })), null);
    assert.equal(parseWebmOpus(synthWebm({ lacing: 0x02 })), null);
    const truncated = parseWebmOpus(synthWebm({ truncate: true }));
    assert.ok(truncated === null || truncated.packets.length <= 2);
    assert.equal(parseWebmOpus(new Uint8Array([1, 2, 3, 4, 5, 6, 7, 8])), null);
    assert.equal(parseWebmOpus(new Uint8Array(0)), null);
    assert.equal(parseWebmOpus(fixture.slice(0, 100)), null);
});

test('opusPacketSamples: 20 ms, 60 ms, dois frames e frames pelo código 3', () => {
    assert.equal(opusPacketSamples(Uint8Array.of(31 << 3)), 960);
    assert.equal(opusPacketSamples(Uint8Array.of(3 << 3)), 2880);
    assert.equal(opusPacketSamples(Uint8Array.of((31 << 3) | 1)), 1920);
    assert.equal(opusPacketSamples(Uint8Array.of((31 << 3) | 2)), 1920);
    assert.equal(opusPacketSamples(Uint8Array.of((31 << 3) | 3, 3)), 2880);
    assert.equal(opusPacketSamples(Uint8Array.of(16 << 3)), 120, 'CELT 2,5 ms');
    assert.equal(opusPacketSamples(new Uint8Array(0)), 0);
});

test('buildOgg: assinatura, CRC de toda página, granule crescente, EOS só na última e mesmos pacotes', () => {
    const { head, packets } = parseWebmOpus(fixture);
    const ogg = buildOgg(head, packets, 1234);
    const pages = readPages(ogg);

    assert.equal(String.fromCharCode(...ogg.slice(0, 4)), 'OggS');
    assert.equal(String.fromCharCode(...ogg.slice(28, 36)), 'OpusHead');
    assert.equal(ogg[37], 1, 'canais no byte 37');
    assert.ok(pages.every((p) => p.crcOk), 'CRC de toda página confere');
    assert.ok(pages.every((p) => p.serial === 1234));
    assert.deepEqual(pages.map((p) => p.sequence), pages.map((_, i) => i));
    assert.equal(pages[0].type & 0x02, 0x02, 'BOS na primeira');
    assert.equal(String.fromCharCode(...pages[1].body.slice(0, 8)), 'OpusTags');
    assert.ok(pages.slice(0, -1).every((p) => (p.type & 0x04) === 0), 'EOS só na última');
    assert.equal(pages.at(-1).type & 0x04, 0x04);

    const audio = pages.slice(2);
    const granules = audio.map((p) => p.granule);
    assert.ok(granules.every((g, i) => i === 0 || g > granules[i - 1]), 'granule crescente');

    const preSkip = head[10] | (head[11] << 8);
    const total = packets.reduce((sum, p) => sum + opusPacketSamples(p), 0);
    assert.equal(pages.at(-1).granule, BigInt(preSkip + total));

    const out = packetsOf(audio);
    assert.equal(out.length, packets.length);
    assert.deepEqual(out[0], packets[0]);
    assert.deepEqual(out.at(-1), packets.at(-1));
});

test('buildOgg: pacote de 255 bytes ou mais usa o laçado 255 e fecha com um segmento menor', () => {
    const { head } = parseWebmOpus(fixture);
    const big = Uint8Array.from({ length: 600 }, (_, i) => (i === 0 ? 31 << 3 : i % 251));
    const ogg = buildOgg(head, [big, big], 7);
    const out = packetsOf(readPages(ogg).slice(2));

    assert.equal(out.length, 2);
    assert.deepEqual(out[0], big);
});

test('webmToOgg: devolve um Blob audio/ogg ou nulo', async () => {
    const ogg = await webmToOgg(new Blob([fixture], { type: 'audio/webm' }));

    assert.ok(ogg instanceof Blob);
    assert.equal(ogg.type, 'audio/ogg');
    assert.ok(ogg.size > 1000);
    assert.equal(await webmToOgg(new Blob([new Uint8Array([9, 9, 9, 9])], { type: 'audio/webm' })), null);
    assert.equal(await webmToOgg(new Blob([synthWebm({ channels: 2 })], { type: 'audio/webm' })), null);
});
