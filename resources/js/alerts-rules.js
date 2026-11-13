// Regras puras dos alertas: o que toca, repete e vira balão. Só `kind` e as preferências entregues pelo servidor
// (ver App\Support\AlertSettings::toArray()); sem DOM, para dar para testar com `node --test tests/js`.

export const CRITICAL_REPEAT_MS = 60_000;
export const GRAVE_REPEAT_MS = 120_000;
// Depois disto o som para sozinho: um aviso que repete sem parar vira atrito com a equipe.
export const MAX_REPEATS = 30;

export function isMuted(settings, now = Date.now()) {
    const until = Date.parse(settings.mute_critical_until ?? '');

    return Number.isFinite(until) && until > now;
}

/** O som a tocar (`comum`, `sla` ou `critico`), ou null para ficar em silêncio. */
export function soundFor(kind, settings, ctx = {}) {
    const sound = settings.sound ?? {};

    switch (kind) {
        case 'message': {
            // A conversa que já está aberta na tela, com a aba em foco, não precisa de som.
            const open = ctx.openTicketId != null && ctx.ticketId === ctx.openTicketId && ctx.focused;

            return sound.message && !open ? 'comum' : null;
        }
        case 'queue':
            return sound.queue ? 'comum' : null;
        case 'assigned':
            return sound.assigned ? 'comum' : null;
        case 'sla_warn':
        case 'sla_breached':
            return sound.sla ? 'sla' : null;
        case 'connection_down':
            return isMuted(settings, ctx.now) ? null : 'critico';
        default:
            return null;
    }
}

/** Intervalo da repetição em ms, ou null quando o tipo não repete (ou o usuário desligou a repetição). */
export function repeatMs(kind, settings) {
    if (!settings.repeat) {
        return null;
    }

    return { connection_down: CRITICAL_REPEAT_MS, sla_breached: GRAVE_REPEAT_MS }[kind] ?? null;
}

/** Balão do navegador: só os graves, só com permissão e ligado; o SLA desligado cala também o balão do SLA. */
export function wantsBalloon(kind, settings, permission) {
    if (!settings.desktop_notifications || permission !== 'granted') {
        return false;
    }

    return kind === 'connection_down' || (kind === 'sla_breached' && Boolean(settings.sound?.sla));
}

/**
 * Abre o contexto de áudio já no carregamento da página (criar não exige gesto): é ele quem diz se o navegador deixa tocar
 * (`running`) ou ainda bloqueia (`suspended`). Sem isso a faixa "Som desligado" aparecia a cada refresh, antes de alguém perguntar.
 * `null` quando não há Web Audio ou o navegador recusa criar.
 */
export function openAudio(AudioContextClass) {
    try {
        return AudioContextClass ? new AudioContextClass() : null;
    } catch {
        return null;
    }
}

/**
 * A faixa "Som desligado neste navegador" some se nada está configurado para tocar, se o áudio já está liberado ou se este navegador
 * já o liberou antes (`remembered`): depois de um refresh o navegador pede um gesto de novo, mas o primeiro clique em qualquer lugar
 * da tela o libera, e pedir o clique a cada refresh só incomoda. Com um alerta crítico ativo (conexão caída, SLA estourado) e o áudio
 * ainda bloqueado, a faixa volta: o som não vai tocar e a pessoa precisa saber.
 */
export function audioStripHidden(settings, audio, remembered = false, critical = false) {
    const silent = !Object.values(settings.sound ?? {}).some(Boolean) || audio?.state === 'running';

    return silent || (remembered && !critical);
}
