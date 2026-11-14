// Seletor de etiquetas com busca (resources/views/components/tag-picker.blade.php). Fica aqui, e não num `x-data` inline, porque o build
// CSP do Alpine não aceita método dentro do atributo. A lista é lida de `data-tags` a cada uso: o Livewire pode trocá-la ao renderizar.
// `this.$el` muda conforme o elemento que dispara o evento (o campo, a opção): a raiz do componente fica guardada em `root`.
import { buildRows } from './tag-filter.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('tagPicker', () => ({
        root: null,
        open: false,
        query: '',
        active: 0,

        init() {
            this.root = this.$el;
        },

        get all() {
            return JSON.parse(this.root.dataset.tags);
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
            this.active = Math.min(this.active, Math.max(this.items.length - 1, 0));
        },

        close() {
            this.open = false;
            this.query = '';
            this.active = 0;
        },

        /** O foco saiu do conjunto (campo e lista): fecha. Clicar numa opção não tira o foco do campo (`mousedown.prevent`). */
        leave(event) {
            if (!this.root.contains(event.relatedTarget)) {
                this.close();
            }
        },

        next() {
            this.show();
            this.active = Math.min(this.active + 1, this.items.length - 1);
        },

        prev() {
            this.show();
            this.active = Math.max(this.active - 1, 0);
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

            this.close();
            this.$refs.field.blur();
        },
    }));
});
