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
