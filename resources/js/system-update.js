// Tela Sistema: no reinício da atualização o app some por alguns minutos (502/503/504 do proxy, 404 ou sem rede). O `wire:poll` da
// tela segue tentando; aqui só se troca o modal de erro do Livewire por um aviso calmo e, quando o app responde de novo, recarrega a
// página (os assets podem ser de outra versão).

/** Status que querem dizer "o app ainda está subindo". Livewire 4.4.8 entrega falha de rede ao hook `request` como 503. */
export function isComingUp(status) {
    return [0, 404, 502, 503, 504].includes(status);
}

if (typeof document !== 'undefined') {
    const lost = () => document.querySelector('[data-update-lost]');

    document.addEventListener('livewire:init', () => {
        // O hook `request` (não o `commit`) é o que recebe o status e o `preventDefault` que segura o modal de erro do Livewire.
        window.Livewire.hook('request', ({ succeed, fail }) => {
            if (!document.querySelector('[data-system-update]')) {
                return;
            }

            succeed(() => {
                if (lost() && !lost().hidden) {
                    window.location.reload();
                }
            });
            fail(({ status, preventDefault }) => {
                if (!isComingUp(status)) {
                    return;
                }

                preventDefault();
                lost()?.removeAttribute('hidden');
            });
        });
    });

    document.addEventListener('alpine:init', () => {
        // Ao abrir a confirmação, o foco vai para o campo da palavra (o botão que abriu sumiu com o morph).
        window.Alpine.data('updateConfirm', () => ({
            init() {
                this.$nextTick(() => this.$el.querySelector('input')?.focus());
            },
        }));
    });
}
