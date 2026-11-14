// Diálogo de confirmação do app (resources/views/components/confirm-dialog.blade.php), no lugar do `confirm()` do navegador.
// Qualquer elemento com `data-confirm="Título? Texto."` (+ `data-confirm-label`, `data-confirm-tone="danger|primary"`) pede confirmação:
// o clique é interrompido na captura (antes do Livewire/Alpine), o diálogo abre e, ao confirmar, o mesmo elemento é clicado de novo.

/** Título = até o primeiro `?` (inclusive); texto = o resto. Sem `?`, tudo é título. */
export function splitConfirm(message) {
    const text = String(message).trim();
    const cut = text.indexOf('?');

    if (cut === -1) {
        return { title: text, text: '' };
    }

    return { title: text.slice(0, cut + 1).trim(), text: text.slice(cut + 1).trim() };
}

/** O que identifica a ação do elemento: `wire:click` e a mensagem. Se, entre abrir o diálogo e confirmar, o morph do Livewire reaproveitar o nó para outro item, a chave muda. */
export function actionKey(element) {
    return `${element.getAttribute('wire:click') ?? ''}\u001f${element.getAttribute('data-confirm') ?? ''}`;
}

export function sameAction(key, element) {
    return actionKey(element) === key;
}

if (typeof document !== 'undefined') {
    let pending = null;
    let pendingKey = '';

    document.addEventListener(
        'click',
        (event) => {
            const element = event.target instanceof Element ? event.target.closest('[data-confirm]') : null;
            const dialog = document.getElementById('confirm-dialog');

            if (!element) {
                return;
            }

            // Sem o diálogo no layout, nunca deixa a ação passar sem confirmar: cai no confirm() do navegador.
            if (!dialog) {
                if (element.dataset.confirmed !== '1' && !window.confirm(element.dataset.confirm)) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                }

                return;
            }

            if (element.dataset.confirmed === '1') {
                delete element.dataset.confirmed;

                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            const { title, text } = splitConfirm(element.dataset.confirm);
            const tone = element.dataset.confirmTone === 'danger' ? 'danger' : 'primary';
            const label = element.dataset.confirmLabel || 'Confirmar';

            dialog.querySelector('[data-confirm-title]').textContent = title;
            dialog.querySelector('[data-confirm-text]').textContent = text;
            dialog.querySelector('[data-confirm-text]').hidden = text === '';

            dialog.querySelectorAll('[data-confirm-ok]').forEach((button) => {
                button.hidden = button.dataset.confirmOk !== tone;
                button.textContent = label;
            });

            pending = element;
            pendingKey = actionKey(element);
            dialog.returnValue = '';
            dialog.showModal();
            dialog.querySelector('[value=cancel]')?.focus();
        },
        true,
    );

    document.addEventListener('close', (event) => {
        if (event.target?.id !== 'confirm-dialog') {
            return;
        }

        const element = pending;
        pending = null;

        if (element && event.target.returnValue === 'ok' && element.isConnected && sameAction(pendingKey, element)) {
            element.dataset.confirmed = '1';
            element.click();
            // Se o clique não gerou evento (botão desabilitado), a marca não pode sobrar e liberar o próximo clique sem confirmação.
            delete element.dataset.confirmed;
        }
    }, true);
}
