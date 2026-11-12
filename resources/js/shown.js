// Espera o elemento aparecer (até ~12 quadros) antes de focar ou medir. No build CSP do Alpine (`livewire.csp.js`, chave
// LIVEWIRE_CSP_SAFE) o `x-show` aplica o `display` alguns milissegundos DEPOIS do `$nextTick`, e foco ou medida num elemento ainda
// escondido falham em silêncio. No Alpine normal o elemento já está visível e a espera é nula.
export async function shown(el, frames = 12) {
    while (el.getClientRects().length === 0 && frames > 0) {
        frames -= 1;
        await new Promise((resolve) => requestAnimationFrame(resolve));
    }
}
