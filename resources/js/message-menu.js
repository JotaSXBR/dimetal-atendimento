// Balão da conversa: edição inline e menu de ações da mensagem (resources/views/livewire/inbox/transcript.blade.php).
// A lógica fica aqui, e não em `x-data` inline, porque o build CSP do Alpine não aceita método dentro do atributo.

const MARGIN = 8;
const GAP = 4;

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
                this.$nextTick(() => this.place());
            }
        },

        place() {
            const button = this.$refs.button.getBoundingClientRect();
            const menu = this.$refs.menu;
            // O limite é a área da conversa (acima do campo de resposta), não a tela inteira.
            const area = this.$el.closest('.overflow-y-auto')?.getBoundingClientRect();
            const floor = Math.min(area?.bottom ?? window.innerHeight, window.innerHeight) - MARGIN;
            const ceiling = Math.max(area?.top ?? 0, 0) + MARGIN;
            const below = button.bottom + GAP;
            const wanted = below + menu.offsetHeight > floor ? button.top - menu.offsetHeight - GAP : below;
            const top = Math.max(Math.min(wanted, floor - menu.offsetHeight), ceiling);
            const left = Math.min(Math.max(button.right - menu.offsetWidth, MARGIN), window.innerWidth - menu.offsetWidth - MARGIN);

            this.style = `top:${top}px;left:${left}px`;
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
