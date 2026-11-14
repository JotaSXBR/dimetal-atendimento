// Popover próprio (resources/views/components/popover.blade.php): painel ancorado num botão, que fecha com Esc (devolvendo o foco ao botão),
// com "Aplicar"/"Limpar" (idem) e com clique fora (sem roubar o foco de onde a pessoa clicou). Os campos do painel são `wire:model` adiado:
// o valor só vai ao servidor numa requisição. Para ele não vazar numa requisição qualquer, qualquer fechamento com mudança nos campos
// (`touch`) aplica (`$wire.$refresh()`); "Limpar" descarta (`reset`). Visual e comportamento inspirados no Popover do Flux Pro; código próprio. Fica aqui, e não num `x-data` inline, porque o build CSP do Alpine não aceita método dentro do atributo.
// `this.$el` muda conforme o elemento que dispara o evento: a raiz do componente fica guardada em `root`.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('popover', () => ({
        root: null,
        open: false,
        dirty: false,

        init() {
            this.root = this.$el;
        },

        toggle() {
            this.open ? this.close() : this.show();
        },

        /** Abre e leva o foco ao primeiro campo, assim que o painel estiver de fato visível (o `x-show` demora alguns ms; foco em oculto falha sem aviso). */
        show() {
            this.open = true;

            const focusWhenShown = () => {
                if (!this.open) {
                    return;
                }

                const panel = this.root.querySelector('[data-popover-panel]');

                panel.offsetParent === null ? requestAnimationFrame(focusWhenShown) : panel.querySelector('input, select, textarea, button')?.focus();
            };

            requestAnimationFrame(focusWhenShown);
        },

        /** Um campo do painel mudou: fechar passa a aplicar. */
        touch() {
            this.dirty = true;
        },

        /** Envia as mudanças dos campos (uma requisição só); sem mudança não faz nada. */
        flush() {
            if (this.dirty) {
                this.dirty = false;
                this.$wire.$refresh();
            }
        },

        /** "Aplicar": envia sempre e fecha. */
        apply() {
            this.dirty = true;
            this.close();
        },

        /** "Limpar": o servidor já devolve os padrões, então descarta o que estava pendente e fecha. */
        reset() {
            this.dirty = false;
            this.close();
        },

        /** Fecha (aplicando o que mudou) e devolve o foco ao botão que abriu. */
        close() {
            if (!this.open) {
                return;
            }

            this.flush();
            this.open = false;
            this.root.querySelector('[data-popover-trigger]')?.focus();
        },

        /** Clique fora: fecha (aplicando o que mudou) sem mexer no foco, porque a pessoa já clicou em outra coisa. */
        dismiss() {
            if (!this.open) {
                return;
            }

            this.flush();
            this.open = false;
        },

        /** Esc com o painel aberto fecha só ele: o evento não sobe para quem está em volta. */
        escape(event) {
            if (this.open) {
                event.stopPropagation();
                this.close();
            }
        },
    }));
});
