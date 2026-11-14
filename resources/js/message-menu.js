// Balão da conversa: edição inline e menu de ações da mensagem (resources/views/livewire/inbox/transcript.blade.php).
// A lógica fica aqui, e não em `x-data` inline, porque o build CSP do Alpine não aceita método dentro do atributo.

import { placeMenu } from './menu-placement.js';
import { shown } from './shown.js';

document.addEventListener('alpine:init', () => {
    // Estado de edição do balão (o menu, que é filho, chama `startEdit` pelo escopo).
    window.Alpine.data('messageEdit', () => ({
        editing: false,
        text: '',

        startEdit(body) {
            this.text = body;
            this.editing = true;
        },

        saveEdit(id) {
            this.$wire.$parent.editMessage(id, this.text);
            this.editing = false;
        },
    }));

    // O menu é `fixed`: dentro da área rolável da conversa ele seria cortado pelo `overflow` e passaria por baixo do campo de resposta.
    // A posição vem do botão: abaixo dele, ou acima se não couber, e sempre dentro da tela.
    window.Alpine.data('messageMenu', () => ({
        open: false,
        info: false,
        style: '',

        toggle() {
            this.open = !this.open;
            this.info = false;

            if (this.open) {
                this.style = 'top:0;left:0;visibility:hidden';
                this.$nextTick(async () => {
                    await shown(this.$refs.menu);
                    this.place();
                });
            }
        },

        place() {
            const menu = this.$refs.menu;
            const { top, left, above } = placeMenu({
                button: this.$refs.button.getBoundingClientRect(),
                menuWidth: menu.offsetWidth,
                menuHeight: menu.offsetHeight,
                area: this.$el.closest('.overflow-y-auto')?.getBoundingClientRect() ?? null,
                viewportWidth: window.innerWidth,
                viewportHeight: window.innerHeight,
            });

            // A animação cresce do lado do botão: o menu fica alinhado à direita dele, abaixo ou acima.
            this.style = `top:${top}px;left:${left}px;transform-origin:${above ? 'bottom' : 'top'} right`;
        },

        copy(body) {
            navigator.clipboard.writeText(body);
        },

        // "Adicionar texto às notas": leva o texto ao compositor, no modo nota (resources/js/composer-mode.js).
        noteFrom(body) {
            this.$dispatch('note-from-message', body);
            this.open = false;
        },
    }));
});
