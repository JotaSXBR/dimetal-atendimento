// Busca Ctrl+K (resources/views/livewire/search/palette.blade.php, num <dialog id="command-palette"> do layout). Ctrl+K / ⌘K e o botão
// `[data-palette-open]` abrem; ↑/↓ marcam o resultado (`data-active`), Enter o abre, Esc fecha (nativo do <dialog>). Os resultados vêm do servidor.

/** Próximo resultado marcado, circular; `current = -1` é "nenhum" e lista vazia não marca nada. */
export function stepActive(current, count, key) {
    if (count === 0) {
        return -1;
    }

    if (current < 0) {
        return key === 'ArrowUp' ? count - 1 : 0;
    }

    return (current + (key === 'ArrowUp' ? count - 1 : 1)) % count;
}

/** Ctrl+K ou ⌘K, sem Alt nem Shift. */
export function isShortcut(event) {
    return (event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey && String(event.key).toLowerCase() === 'k';
}

/** O rótulo do atalho na barra: ⌘K no Mac e no iOS, Ctrl K no resto. */
export function shortcutLabel(platform) {
    return /mac|iphone|ipad|ipod/i.test(String(platform ?? '')) ? '⌘K' : 'Ctrl K';
}

if (typeof document !== 'undefined') {
    const dialog = () => document.getElementById('command-palette');
    const options = (root) => [...root.querySelectorAll('[data-palette-option]')];

    // Enquanto uma busca nova não voltou do servidor, a lista na tela é a antiga: o Enter espera a resposta em vez de abrir o resultado velho.
    let pending = false;
    let enterQueued = false;

    const activate = (root) => {
        const items = options(root);
        const current = items.findIndex((item) => item.hasAttribute('data-active'));
        const target = items[current] ?? items[0];

        if (target) {
            target.click();
        }
    };

    const settle = () => {
        pending = false;

        const root = dialog();

        if (enterQueued && root?.open) {
            enterQueued = false;
            activate(root);
        }

        enterQueued = false;
    };

    document.addEventListener('livewire:init', () => {
        window.Livewire.hook('commit', ({ succeed, fail }) => {
            succeed(settle);
            fail(settle);
        });
    });

    // O primeiro resultado já vem marcado (é o que o Enter abre): refeito a cada atualização da lista pelo servidor.
    const markFirst = (root) => {
        const items = options(root);

        if (items.length === 0 || items.some((item) => item.hasAttribute('data-active'))) {
            return;
        }

        items[0].setAttribute('data-active', '');
        root.querySelector('input[type="search"]')?.setAttribute('aria-activedescendant', items[0].id);
    };

    let watching = false;

    const watch = (root) => {
        const list = root.querySelector('[data-palette-list]');

        if (!watching && list) {
            watching = true;
            new MutationObserver(() => markFirst(root)).observe(list, { childList: true, subtree: true });
        }
    };

    const applyHint = () => document.querySelectorAll('[data-palette-hint]').forEach((hint) => (hint.textContent = shortcutLabel(navigator.platform)));

    applyHint();
    document.addEventListener('livewire:navigated', applyHint);

    const open = () => {
        const root = dialog();

        // Com qualquer outro diálogo aberto (confirmação, ficha em gaveta), o atalho não empilha a busca por cima.
        if (!root || root.open || document.querySelector('dialog[open]')) {
            return;
        }

        const input = root.querySelector('input[type="search"]');

        if (input && input.value !== '') {
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            pending = true;
        }

        root.showModal();
        watch(root);
        markFirst(root);
        input?.focus();
    };

    document.addEventListener('keydown', (event) => {
        if (isShortcut(event)) {
            event.preventDefault();
            open();

            return;
        }

        const root = dialog();

        if (!root?.open || !root.contains(event.target)) {
            return;
        }

        // No campo de busca o Esc do navegador só limpa o texto; aqui ele sempre fecha.
        if (event.key === 'Escape') {
            event.preventDefault();
            root.close();

            return;
        }

        const items = options(root);
        const current = items.findIndex((item) => item.hasAttribute('data-active'));

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();

            const next = stepActive(current, items.length, event.key);

            const input = root.querySelector('input[type="search"]');

            items.forEach((item, index) => item.toggleAttribute('data-active', index === next));
            items[next]?.scrollIntoView({ block: 'nearest' });
            input?.setAttribute('aria-activedescendant', items[next]?.id ?? '');
        } else if (event.key === 'Enter' && !event.isComposing) {
            event.preventDefault();

            if (pending) {
                enterQueued = true;
                // Rede de segurança: se nenhuma resposta chegar (conexão caída), não deixa o Enter preso para sempre.
                setTimeout(settle, 2000);
            } else {
                activate(root);
            }
        }
    });

    document.addEventListener('input', (event) => {
        if (dialog()?.contains(event.target)) {
            pending = true;
        }
    });

    document.addEventListener('click', (event) => {
        const root = dialog();

        if (event.target instanceof Element && event.target.closest('[data-palette-open]')) {
            open();
        } else if (root?.open && (event.target === root || event.target.closest?.('[data-palette-option]'))) {
            root.close();
        }
    });
}
