// Rolagem horizontal que põe o item no meio do contêiner (menu de Configuração abaixo de lg; ver settings-ui.js). Puro,
// para testar sem navegador. Recebe as caixas de getBoundingClientRect; o navegador limita o valor aos extremos.

/**
 * @param {{ container: {left: number, width: number}, item: {left: number, width: number}, scrollLeft: number }} input
 * @returns {number}
 */
export function centerScrollLeft({ container, item, scrollLeft }) {
    return scrollLeft + item.left - container.left - (container.width - item.width) / 2;
}
