// O ✕ das faixas fixas do topo (`[data-dismiss]`, ver resources/views/components/alert-dismiss.blade.php): esconde a faixa por um tempo e
// ela volta sozinha. A regra de quando a dispensa vale é pura (alerts-rules.js). Só esconde (`data-dismissed` + CSS): o elemento fica no DOM
// porque o som repetido do alerta (alerts.js) depende dele estar lá.
import { DISMISSALS, dismissEntry, isDismissed } from './alerts-rules';

const keyOf = (kind) => `dimetal-dismiss:${kind}`;
// Sem storage (modo privado, bloqueado), a dispensa vale só nesta visita, em memória.
const memory = new Map();

const storageFor = (kind) => {
    try {
        return DISMISSALS[kind].store === 'session' ? window.sessionStorage : window.localStorage;
    } catch {
        return null;
    }
};

function read(kind) {
    try {
        return JSON.parse(storageFor(kind)?.getItem(keyOf(kind)) ?? 'null') ?? memory.get(kind) ?? null;
    } catch {
        return memory.get(kind) ?? null;
    }
}

function write(kind, entry) {
    memory.set(kind, entry);

    try {
        storageFor(kind)?.setItem(keyOf(kind), JSON.stringify(entry));
    } catch {
        // sem armazenamento: fica só em memória
    }
}

export function apply() {
    document.querySelectorAll('[data-dismiss]').forEach((banner) => {
        const kind = banner.dataset.dismiss;

        banner.toggleAttribute('data-dismissed', isDismissed(read(kind), Date.now(), banner.dataset.signature ?? '', kind));
    });
}

if (typeof document !== 'undefined') {
    document.addEventListener('click', (event) => {
        const banner = event.target instanceof Element ? event.target.closest('[data-dismiss-button]')?.closest('[data-dismiss]') : null;

        if (banner && DISMISSALS[banner.dataset.dismiss]) {
            write(banner.dataset.dismiss, dismissEntry(banner.dataset.dismiss, Date.now(), banner.dataset.signature ?? ''));
            apply();
        }
    });

    // O Livewire re-renderiza a faixa (Reverb) e o servidor não sabe da dispensa: reaplica a cada mudança no documento, antes do próximo quadro.
    let frame = null;
    new MutationObserver(() => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(apply);
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-signature'] });
    document.addEventListener('livewire:navigated', apply);
    // E a dispensa vence sozinha, sem nada mudar na página.
    setInterval(apply, 5000);
    apply();
}
