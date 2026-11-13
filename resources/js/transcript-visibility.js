// A conversa aberta avisa o servidor se a aba está visível (`visible`) e, quando ela volta a ficar visível, pede para marcar como
// visto (`seen`): em segundo plano a mensagem do cliente segue não lida para a equipe. Ver App\Livewire\Inbox\Transcript.

document.addEventListener('alpine:init', () => {
    window.Alpine.data('transcriptVisibility', () => ({
        change: null,

        init() {
            const visible = () => document.visibilityState === 'visible';

            // `false`: só atualiza a propriedade, sem requisição própria; ela segue junto da próxima (o evento da mensagem).
            this.$wire.$set('visible', visible(), false);

            this.change = () => {
                this.$wire.$set('visible', visible(), false);

                if (visible()) {
                    this.$wire.seen();
                }
            };

            document.addEventListener('visibilitychange', this.change);
        },

        destroy() {
            document.removeEventListener('visibilitychange', this.change);
        },
    }));
});
