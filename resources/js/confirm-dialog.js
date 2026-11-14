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

if (typeof document !== 'undefined') {
    let pending = null;

    document.addEventListener(
        'click',
        (event) => {
            const element = event.target instanceof Element ? event.target.closest('[data-confirm]') : null;
            const dialog = document.getElementById('confirm-dialog');

            if (!element || !dialog) {
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

        if (element && event.target.returnValue === 'ok' && element.isConnected) {
            element.dataset.confirmed = '1';
            element.click();
            // Se o clique não gerou evento (botão desabilitado), a marca não pode sobrar e liberar o próximo clique sem confirmação.
            delete element.dataset.confirmed;
        }
    }, true);
}
