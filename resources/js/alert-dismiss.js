// O ✕ das faixas fixas do topo (`[data-dismiss]`, ver resources/views/components/alert-dismiss.blade.php): esconde a faixa por um tempo e
// ela volta sozinha. A regra de quando a dispensa vale é pura (alerts-rules.js). Só esconde (`data-dismissed` + CSS): o elemento fica no DOM
// porque o som repetido do alerta (alerts.js) depende dele estar lá.
import { DISMISSALS, dismissEntry, isDismissed } from './alerts-rules';

const keyOf = (kind) => `fluxie-dismiss:${kind}`;
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

/** A faixa voltou depois de dispensada: `display: none` → visível não é anunciado pelo leitor de tela, então diz em voz baixa. */
function announce(text) {
    let region = document.getElementById('alerts-announce');

    if (!region) {
        region = document.createElement('div');
        region.id = 'alerts-announce';
        region.className = 'sr-only';
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        document.body.append(region);
    }

    region.textContent = '';
    setTimeout(() => (region.textContent = `Aviso de volta: ${text}`), 50);
}

export function apply() {
    document.querySelectorAll('[data-dismiss]').forEach((banner) => {
        const kind = banner.dataset.dismiss;
        const was = banner.hasAttribute('data-dismissed');
        const now = isDismissed(read(kind), Date.now(), banner.dataset.signature ?? '', kind);

        banner.toggleAttribute('data-dismissed', now);

        if (was && !now && !banner.hidden) {
            announce(banner.querySelector('strong')?.textContent ?? 'aviso');
        }
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

    // O Livewire re-renderiza a faixa (Reverb) e o servidor não sabe da dispensa: o morph tira `data-dismissed`. Reaplica a cada mudança no
    // documento (inclusive nesse atributo e na assinatura), antes do próximo quadro; `toggleAttribute` com o valor que já está não gera mutação, então converge.
    let frame = null;
    new MutationObserver(() => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(apply);
    }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-signature', 'data-dismissed'] });
    document.addEventListener('livewire:navigated', apply);
    // E a dispensa vence sozinha, sem nada mudar na página.
    setInterval(apply, 5000);
    apply();
}
