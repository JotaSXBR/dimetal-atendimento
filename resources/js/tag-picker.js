// Seletor de etiquetas com busca (resources/views/components/tag-picker.blade.php). Fica aqui, e não num `x-data` inline, porque o build
// CSP do Alpine não aceita método dentro do atributo. A lista é lida de `data-tags` a cada uso: o Livewire pode trocá-la ao renderizar.
// `this.$el` muda conforme o elemento que dispara o evento (o campo, a opção): a raiz do componente fica guardada em `root`.
import { shown } from './shown.js';
import { activeForQuery, buildRows, stepActive } from './tag-filter.js';

document.addEventListener('alpine:init', () => {
    // Depois de remover um chip o botão some e o foco cairia no BODY: volta ao seletor "Adicionar…" do bloco indicado em `data-focus-target` (a ficha do contato abriga também o da conversa). `.self` no
    // listener: o evento do componente filho (etiquetas da conversa) também sobe até a ficha do contato, e não deve focar o seletor dela.
    window.Alpine.data('focusTagPicker', () => ({
        async focus() {
            await this.$nextTick();
            const field = this.$el.querySelector(this.$el.dataset.focusTarget);
            if (field === null) {
                return;
            }
            await shown(field);
            field.focus();
        },
    }));

    window.Alpine.data('tagPicker', () => ({
        root: null,
        all: [],
        observer: null,
        open: false,
        query: '',
        active: -1,

        init() {
            this.root = this.$el;
            this.sync();
            // O Livewire troca `data-tags` ao aplicar ou tirar uma etiqueta: a lista acompanha, sem esperar o usuário digitar.
            this.observer = new MutationObserver(() => this.sync());
            this.observer.observe(this.root, { attributes: true, attributeFilter: ['data-tags'] });
        },

        destroy() {
            this.observer.disconnect();
        },

        sync() {
            this.all = JSON.parse(this.root.dataset.tags);
        },

        /** Linhas da lista: o filtro, o título dos grupos e a linha "Todas" do modo filtro (ver `buildRows`). */
        get items() {
            return buildRows(this.all, this.query, {
                filtering: this.root.dataset.model !== undefined,
                clearLabel: this.root.dataset.clear,
                active: this.active,
                listId: this.root.dataset.listId,
            });
        },

        get empty() {
            return this.open && this.items.length === 0;
        },

        get activeId() {
            const row = this.items[this.active];

            return this.open && row !== undefined ? row.domId : '';
        },

        show() {
            this.open = true;
            this.active = Math.min(this.active, this.items.length - 1);
        },

        /** Digitou: abre e pré-escolhe a primeira correspondência (Enter a aplica). */
        typed() {
            this.open = true;
            this.active = activeForQuery(this.query);
        },

        close() {
            this.open = false;
            this.query = '';
            this.active = -1;
        },

        /** Esc com a lista aberta fecha só a lista: o evento não sobe para quem está em volta (a busca da Fila, por exemplo). */
        escape(event) {
            if (this.open) {
                event.stopPropagation();
                this.close();
            }
        },

        /** O foco saiu do conjunto (campo e lista): fecha. Clicar numa opção não tira o foco do campo (`mousedown.prevent`). */
        leave(event) {
            if (!this.root.contains(event.relatedTarget)) {
                this.close();
            }
        },

        next() {
            this.open = true;
            this.active = stepActive(this.active, 1, this.items.length);
        },

        prev() {
            this.open = true;
            this.active = stepActive(this.active, -1, this.items.length);
        },

        hover(index) {
            this.active = index;
        },

        /** Enter: aplica o item destacado; sem item destacado (lista vazia) não faz nada. */
        choose() {
            const row = this.items[this.active];

            if (this.open && row !== undefined) {
                this.pick(row.id);
            }
        },

        pick(id) {
            const { method, model } = this.root.dataset;

            if (method !== undefined) {
                this.$wire[method](id);
            } else {
                this.$wire.$set(model, id === '' ? null : id);
            }

            // O foco fica no campo (com a lista fechada): quem usa teclado segue de onde estava.
            this.close();
        },
    }));
});
