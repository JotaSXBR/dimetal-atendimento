// Painel de detalhes do ticket (Inbox e Histórico): aberto ou recolhido, lembrado neste navegador (`localStorage`), e a "folha" do
// celular. No Inbox também semeia o estado de "digitando…" do cliente que o servidor guardou (resources/js/client-presence.js).
// Fica aqui, e não num `x-data` inline, porque o build CSP do Alpine não aceita método dentro do atributo.

const KEY = 'ticketDetailsOpen';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('ticketDetails', () => ({
        details: window.localStorage.getItem(KEY) !== '0',
        sheet: false,

        init() {
            const presence = this.$el.dataset.clientPresence;

            if (presence !== undefined) {
                window.Alpine.store('clientPresence').seed(JSON.parse(presence), Number(this.$el.dataset.presenceTtl));
            }
        },

        toggle() {
            this.details = !this.details;
            window.localStorage.setItem(KEY, this.details ? '1' : '0');
        },
    }));

    // Miniatura que a mídia expirou: some em vez de mostrar o ícone de imagem quebrada.
    window.Alpine.data('brokenImage', () => ({
        hide() {
            this.$el.style.display = 'none';
        },
    }));
});
