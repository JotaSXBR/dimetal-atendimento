// Remux de uma gravação WebM/Opus (o que o Chrome grava) para OGG/Opus, sem recodificar: os pacotes Opus são os mesmos, só o
// contêiner muda. O WhatsApp aceita voz em OGG/Opus mono, então o WAHA não precisa converter o arquivo (e o gerador de onda dele
// recebe um OGG com assinatura reconhecível). Qualquer coisa fora do esperado devolve `null`: quem chama segue com o WebM original.

const OPUS_RATE_SAMPLES_PER_MS = 48;
const MAX_PACKETS_PER_PAGE = 50;
const MAX_SEGMENTS_PER_PAGE = 255;

// Elementos EBML: os "contêineres" são atravessados sem olhar o tamanho (o Segment e o Cluster de uma gravação ao vivo vêm
// com tamanho desconhecido); os demais são pulados pelo tamanho.
const CONTAINERS = new Set([0x18538067, 0x1654ae6b, 0xae, 0x1f43b675, 0xa0]);
const ID = { trackNumber: 0xd7, codecId: 0x86, codecPrivate: 0x63a2, channels: 0x9f, trackEntry: 0xae, simpleBlock: 0xa3, block: 0xa1 };

/** Lê um número de tamanho variável. Com `keepMarker` o bit de marcação fica (é assim que os IDs são escritos). */
function readVint(bytes, at, keepMarker) {
    const first = bytes[at];

    if (first === undefined || first === 0) {
        return null;
    }

    let length = 1;

    while (!(first & (0x80 >> (length - 1)))) {
        length++;
    }

    if (at + length > bytes.length) {
        return null;
    }

    let value = keepMarker ? first : first & (0xff >> length);
    let allOnes = (first & (0xff >> length)) === 0xff >> length;

    for (let i = 1; i < length; i++) {
        value = value * 256 + bytes[at + i];
        allOnes = allOnes && bytes[at + i] === 0xff;
    }

    return { value, length, unknown: !keepMarker && allOnes };
}

const text = (bytes) => String.fromCharCode(...bytes);

/**
 * Lê a faixa Opus mono de um WebM e devolve o `OpusHead` (o CodecPrivate) e os pacotes, na ordem. `null` se não houver exatamente
 * uma faixa A_OPUS mono, se algum bloco usar lacing, ou se o arquivo acabar antes do primeiro pacote.
 */
export function parseWebmOpus(bytes) {
    const tracks = [];
    const frames = [];
    let at = 0;

    while (at < bytes.length) {
        const id = readVint(bytes, at, true);
        const size = id && readVint(bytes, at + id.length, false);

        if (!id || !size) {
            break;
        }

        const start = at + id.length + size.length;

        if (CONTAINERS.has(id.value)) {
            if (id.value === ID.trackEntry) {
                tracks.push({});
            }

            at = start;

            continue;
        }

        if (size.unknown) {
            return null;
        }

        const end = start + size.value;
        const track = tracks.at(-1);

        if (end > bytes.length && id.value !== ID.simpleBlock && id.value !== ID.block) {
            break;
        }

        if (track && id.value === ID.trackNumber) {
            track.number = bytes.slice(start, end).reduce((n, b) => n * 256 + b, 0);
        } else if (track && id.value === ID.codecId) {
            track.codec = text(bytes.slice(start, end));
        } else if (track && id.value === ID.codecPrivate) {
            track.head = bytes.slice(start, end);
        } else if (track && id.value === ID.channels) {
            track.channels = bytes[start];
        } else if (id.value === ID.simpleBlock || id.value === ID.block) {
            if (end > bytes.length) {
                break;
            }

            const number = readVint(bytes, start, false);

            if (!number || bytes[start + number.length + 2] === undefined) {
                break;
            }

            if ((bytes[start + number.length + 2] >> 1) & 3) {
                return null; // lacing: vários frames num bloco, fora do que o MediaRecorder escreve
            }

            frames.push({ track: number.value, data: bytes.slice(start + number.length + 3, end) });
        }

        at = end;
    }

    const opus = tracks.filter((t) => t.codec === 'A_OPUS');

    if (tracks.length !== 1 || opus.length !== 1) {
        return null;
    }

    const [track] = opus;
    const head = track.head;

    if (!head || head.length < 19 || text(head.slice(0, 8)) !== 'OpusHead' || head[9] !== 1 || (track.channels ?? 1) !== 1) {
        return null;
    }

    const packets = frames.filter((f) => f.track === track.number && f.data.length > 0).map((f) => f.data);

    return packets.length > 0 ? { head, packets } : null;
}

/** Amostras a 48 kHz de um pacote Opus, pelo byte TOC (RFC 6716, seção 3.1). */
export function opusPacketSamples(packet) {
    if (packet.length === 0) {
        return 0;
    }

    const config = packet[0] >> 3;
    const code = packet[0] & 3;
    const ms = config < 12 ? [10, 20, 40, 60][config % 4] : config < 16 ? [10, 20][config % 2] : [2.5, 5, 10, 20][config % 4];
    const frames = code === 0 ? 1 : code === 3 ? (packet[1] ?? 0) & 0x3f : 2;

    return Math.round(ms * OPUS_RATE_SAMPLES_PER_MS) * frames;
}

const CRC_TABLE = Array.from({ length: 256 }, (_, n) => {
    let crc = n << 24;

    for (let bit = 0; bit < 8; bit++) {
        crc = crc & 0x80000000 ? (crc << 1) ^ 0x04c11db7 : crc << 1;
    }

    return crc >>> 0;
});

function oggCrc(bytes) {
    let crc = 0;

    for (const byte of bytes) {
        crc = ((crc << 8) ^ CRC_TABLE[((crc >>> 24) ^ byte) & 0xff]) >>> 0;
    }

    return crc;
}

/** Uma página OGG com o CRC preenchido. `lacing` é a tabela de segmentos; `body` os bytes dos pacotes. */
function page({ type, granule, serial, sequence, lacing, body }) {
    const out = new Uint8Array(27 + lacing.length + body.length);
    const view = new DataView(out.buffer);

    out.set([0x4f, 0x67, 0x67, 0x53, 0, type]);
    view.setBigUint64(6, BigInt(granule), true);
    view.setUint32(14, serial, true);
    view.setUint32(18, sequence, true);
    out[26] = lacing.length;
    out.set(lacing, 27);
    out.set(body, 27 + lacing.length);
    view.setUint32(22, oggCrc(out), true);

    return out;
}

/** Tabela de segmentos de um pacote: blocos de 255 e um último menor (0 quando o pacote é múltiplo de 255). */
function lacingOf(length) {
    return [...Array(Math.floor(length / 255)).fill(255), length % 255];
}

const concat = (parts) => {
    const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
    let at = 0;

    for (const part of parts) {
        out.set(part, at);
        at += part.length;
    }

    return out;
};

/** Monta o OGG: OpusHead, OpusTags e as páginas de áudio (granule = pre-skip + amostras acumuladas; EOS na última). */
export function buildOgg(head, packets, serial = Math.floor(Math.random() * 0xffffffff)) {
    const preSkip = head[10] | (head[11] << 8);
    const vendor = new TextEncoder().encode('Recorder');
    const tags = new Uint8Array(8 + 4 + vendor.length + 4);

    tags.set(new TextEncoder().encode('OpusTags'));
    new DataView(tags.buffer).setUint32(8, vendor.length, true);
    tags.set(vendor, 12);

    const pages = [
        page({ type: 0x02, granule: 0, serial, sequence: 0, lacing: [head.length], body: head }),
        page({ type: 0, granule: 0, serial, sequence: 1, lacing: lacingOf(tags.length), body: tags }),
    ];

    let samples = 0;
    let index = 0;

    while (index < packets.length) {
        const lacing = [];
        const body = [];

        while (index < packets.length && body.length < MAX_PACKETS_PER_PAGE && lacing.length + lacingOf(packets[index].length).length <= MAX_SEGMENTS_PER_PAGE) {
            lacing.push(...lacingOf(packets[index].length));
            body.push(packets[index]);
            samples += opusPacketSamples(packets[index]);
            index++;
        }

        pages.push(page({ type: index >= packets.length ? 0x04 : 0, granule: preSkip + samples, serial, sequence: pages.length, lacing, body: concat(body) }));
    }

    return concat(pages);
}

/** `Blob` OGG/Opus a partir da gravação WebM/Opus, ou `null` se não der (quem chama usa o original). */
export async function webmToOgg(blob) {
    try {
        const parsed = parseWebmOpus(new Uint8Array(await blob.arrayBuffer()));

        return parsed ? new Blob([buildOgg(parsed.head, parsed.packets)], { type: 'audio/ogg' }) : null;
    } catch {
        return null;
    }
}
