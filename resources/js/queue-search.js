// Lupa da Fila (resources/views/livewire/inbox/inbox.blade.php): abre o campo de busca por nome, telefone ou protocolo; fechar limpa a busca.
// Fica aqui, e não num `x-data` inline, porque o build CSP do Alpine não aceita método dentro do atributo.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('queueSearch', () => ({
        open: false,

        init() {
            // Já há busca no servidor (recarga, volta do histórico): o campo nasce aberto para ela não ficar escondida.
            this.open = this.$el.dataset.open === '1';
        },

        toggle() {
            this.open ? this.close() : this.show();
        },

        show() {
            this.open = true;
            this.$nextTick(() => this.$refs.field.focus());
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
