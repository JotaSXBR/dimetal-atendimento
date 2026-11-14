// Filtro do seletor de etiquetas (resources/js/tag-picker.js). Puro, para testar sem navegador.

const fold = (text) => text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();

/**
 * Etiquetas cujo nome contém a consulta, sem diferenciar maiúscula nem acento. Consulta vazia devolve todas; a ordem recebida se mantém.
 *
 * @param {{ id: number, name: string }[]} tags
 * @param {string} query
 */
export function filterTags(tags, query) {
    const wanted = fold(query.trim());

    return wanted === '' ? tags : tags.filter((tag) => fold(tag.name).includes(wanted));
}
