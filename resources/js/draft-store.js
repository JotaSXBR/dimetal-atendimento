// Funções puras dos rascunhos do compositor (`localStorage`, chave `rascunho:{userId}:{ticketId}:{modo}`); sem DOM, testáveis no node.

/** Lê o valor guardado: JSON `{text, at}` ou, nos rascunhos antigos, o texto cru (sem data). */
export function parseDraft(raw) {
    if (raw === null || raw === undefined) {
        return { text: '', at: null };
    }

    try {
        const data = JSON.parse(raw);

        if (data !== null && typeof data === 'object' && typeof data.text === 'string') {
            return { text: data.text, at: Number.isFinite(data.at) ? data.at : null };
        }
    } catch {
        // texto cru
    }

    return { text: raw, at: null };
}

const prefix = (userId) => `rascunho:${userId}:`;

/** Chaves do usuário com data mais velha que `maxAgeMs`; o formato antigo, sem data, não vence pela idade. */
export function staleDraftKeys(entries, userId, now, maxAgeMs) {
    return entries
        .filter(([key, raw]) => {
            const { at } = parseDraft(raw);

            return key.startsWith(prefix(userId)) && at !== null && at < now - maxAgeMs;
        })
        .map(([key]) => key);
}

/** Ids de ticket (únicos) das chaves de rascunho do usuário. */
export function draftTicketIds(keys, userId) {
    const ids = keys
        .filter((key) => key.startsWith(prefix(userId)))
        .map((key) => Number(key.split(':')[2]))
        .filter((id) => Number.isInteger(id) && id > 0);

    return [...new Set(ids)];
}
