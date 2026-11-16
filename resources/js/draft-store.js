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

/** Id do ticket da chave de rascunho do usuário, ou `null` (chave de outro usuário ou malformada). */
export function draftTicketId(key, userId) {
    const id = key.startsWith(prefix(userId)) ? Number(key.split(':')[2]) : NaN;

    return Number.isInteger(id) && id > 0 ? id : null;
}

/** Ids de ticket (únicos) das entradas `[chave, valor]` do usuário, do rascunho mais antigo ao mais novo; sem data conta como o mais antigo. */
export function draftTicketIds(entries, userId) {
    const oldest = new Map();

    entries.forEach(([key, raw]) => {
        const id = draftTicketId(key, userId);
        const at = parseDraft(raw).at ?? -Infinity;

        if (id !== null) {
            oldest.set(id, Math.min(oldest.get(id) ?? Infinity, at));
        }
    });

    // sort é estável: empate fica na ordem de entrada
    return [...oldest.keys()].sort((a, b) => (oldest.get(a) < oldest.get(b) ? -1 : Number(oldest.get(a) > oldest.get(b))));
}
