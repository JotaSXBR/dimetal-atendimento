// Filtro e linhas do seletor de etiquetas (resources/js/tag-picker.js). Puro, para testar sem navegador.

const fold = (text) => text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();

/**
 * Etiquetas cujo nome contém a consulta, sem diferenciar maiúscula nem acento. Consulta vazia devolve todas; a ordem recebida se mantém.
 *
 * @param {{ id: number|string, name: string }[]} tags
 * @param {string} query
 */
export function filterTags(tags, query) {
    const wanted = fold(query.trim());

    return wanted === '' ? tags : tags.filter((tag) => fold(tag.name).includes(wanted));
}

/**
 * As linhas da lista, já com tudo o que o modelo precisa (o build CSP do Alpine não faz conta dentro do atributo). No modo filtro
 * (`filtering`) a primeira linha, "Todas", limpa o filtro; ela some quando há consulta. `header` marca a primeira linha de cada grupo.
 *
 * @param {{ id: number|string, name: string, color: string, group?: string }[]} tags
 * @param {string} query
 * @param {{ filtering?: boolean, clearLabel?: string, active?: number, listId?: string }} [options]
 */
export function buildRows(tags, query, { filtering = false, clearLabel = '', active = 0, listId = '' } = {}) {
    const found = filterTags(tags, query);
    const rows = filtering && query.trim() === '' ? [{ id: '', name: clearLabel, color: '', group: '' }, ...found] : found;
    const groupOf = (tag) => tag.group ?? '';

    return rows.map((tag, index) => ({
        ...tag,
        index,
        key: `${tag.id}`,
        domId: `${listId}-${index}`,
        header: groupOf(tag) !== '' && (index === 0 || groupOf(rows[index - 1]) !== groupOf(tag)),
        active: index === active,
        hasDot: tag.color !== '',
        dotClass: `tag-${tag.color}`,
        rowClass: index === active ? 'bg-canvas' : '',
    }));
}

/**
 * A próxima linha ativa ao apertar seta: sem linha ativa (-1), para baixo vai à primeira e para cima à última; fora isso anda uma linha
 * e para nas pontas. Lista vazia nunca tem linha ativa.
 *
 * @param {number} active
 * @param {1|-1} direction
 * @param {number} length
 */
export function stepActive(active, direction, length) {
    if (length === 0) {
        return -1;
    }

    if (active < 0) {
        return direction > 0 ? 0 : length - 1;
    }

    return Math.min(Math.max(active + direction, 0), length - 1);
}

/** Só digitar pré-escolhe a primeira linha: apenas focar o campo e apertar Enter não aplica nada que ninguém escolheu. */
export function activeForQuery(query) {
    return query.trim() === '' ? -1 : 0;
}
