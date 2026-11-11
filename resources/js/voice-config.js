// Configuração da gravação de voz e do arquivo que sobe (ver voice-recorder.js).
//
// 32 kbps é o bitrate que o WAHA já usa na conversão e o que o Opus considera praticamente transparente para fala (o padrão do
// Chrome grava ~128 kbps, bem acima do necessário: 5 min passam de 4 MB, contra ~1,2 MB). Mono a 48 kHz é o que a mensagem de voz
// do WhatsApp usa; o navegador pode ignorar o pedido, e o remux só vale se o arquivo gravado for mono. Eco, ruído e ganho
// automático ligados são o padrão dos navegadores e ajudam voz de atendente em sala com barulho.
import { webmToOgg } from './webm-to-ogg.js';

export const VOICE_BITS_PER_SECOND = 32000;

export const VOICE_CONSTRAINTS = {
    audio: { channelCount: { ideal: 1 }, sampleRate: { ideal: 48000 }, echoCancellation: true, noiseSuppression: true, autoGainControl: true },
};

/**
 * O arquivo que vai ao servidor, sempre OGG/Opus mono, ou `null` quando a gravação não pode virar isso (o chamador mostra o
 * erro e não envia: o caminho antigo, convertido pelo WAHA, perdia a onda). O WebM/Opus do Chrome e do Edge vira OGG sem
 * recodificar; o OGG do Firefox só passa se o cabeçalho for Opus mono.
 */
export async function voiceFile(blob, mime) {
    if (mime.includes('webm')) {
        const ogg = await webmToOgg(blob);

        return ogg ? { blob: ogg, mime: 'audio/ogg' } : null;
    }

    if (mime.includes('ogg')) {
        const head = new Uint8Array(await blob.slice(0, 38).arrayBuffer());
        const text = (from, to) => String.fromCharCode(...head.slice(from, to));
        const mono = head.length === 38 && text(0, 4) === 'OggS' && text(28, 36) === 'OpusHead' && head[37] === 1;

        return mono ? { blob, mime: 'audio/ogg' } : null;
    }

    return null;
}
