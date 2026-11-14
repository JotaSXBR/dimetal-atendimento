// Posição do menu da mensagem (resources/js/message-menu.js): abaixo do botão, ou acima se não couber, sempre dentro da área da
// conversa e da tela. `above` diz ao menu de que lado crescer na animação (transform-origin). Puro, para testar sem navegador.

const MARGIN = 8;
const GAP = 4;

/**
 * @param {{ button: {top: number, bottom: number, right: number}, menuWidth: number, menuHeight: number, area: {top: number, bottom: number} | null, viewportWidth: number, viewportHeight: number }} input
 * @returns {{ top: number, left: number, above: boolean }}
 */
export function placeMenu({ button, menuWidth, menuHeight, area, viewportWidth, viewportHeight }, margin = MARGIN, gap = GAP) {
    // O limite é a área da conversa (acima do campo de resposta), não a tela inteira.
    const floor = Math.min(area?.bottom ?? viewportHeight, viewportHeight) - margin;
    const ceiling = Math.max(area?.top ?? 0, 0) + margin;
    const below = button.bottom + gap;
    const wanted = below + menuHeight > floor ? button.top - menuHeight - gap : below;
    const top = Math.max(Math.min(wanted, floor - menuHeight), ceiling);
    const left = Math.min(Math.max(button.right - menuWidth, margin), viewportWidth - menuWidth - margin);

    return { top, left, above: wanted !== below };
}
