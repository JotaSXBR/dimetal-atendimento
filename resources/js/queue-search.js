// Lupa da Fila (resources/views/livewire/inbox/inbox.blade.php): abre o campo de busca por nome, telefone ou protocolo; fechar limpa a busca.
// Fica aqui, e não num `x-data` inline, porque o build CSP do Alpine não aceita método dentro do atributo.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('queueSearch', () => ({
        open: false,

        init() {
            // Defensivo: se o servidor entregar o componente já com busca, o campo nasce aberto para o filtro não ficar escondido.
            this.open = this.$el.dataset.open === '1';
        },

        toggle() {
            this.open ? this.close() : this.show();
        },

        show() {
            this.open = true;
            // O `x-show` só mostra o campo alguns ms depois, e foco em campo oculto falha sem aviso: foca quando ele já está visível.
            const focusWhenShown = () => {
                if (!this.open) {
                    return;
                }

                this.$refs.field.offsetParent === null ? requestAnimationFrame(focusWhenShown) : this.$refs.field.focus();
            };

            requestAnimationFrame(focusWhenShown);
        },

        close() {
            this.open = false;
            this.$wire.$set('search', '');
        },

        /** Esc fecha só com o campo aberto: fora dele a tecla segue para o resto da tela. */
        escape() {
            if (this.open) {
                this.close();
            }
        },
    }));
});
