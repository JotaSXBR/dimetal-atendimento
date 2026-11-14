// Seletor de etiquetas com busca (resources/views/components/tag-picker.blade.php). Fica aqui, e não num `x-data` inline, porque o build
// CSP do Alpine não aceita método dentro do atributo. A lista é lida de `data-tags` a cada uso: o Livewire pode trocá-la ao renderizar.
import { filterTags } from './tag-filter.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('tagPicker', () => ({
        open: false,
        query: '',
        active: 0,

        get all() {
            return JSON.parse(this.$el.dataset.tags);
        },

        get filtering() {
            return this.$el.dataset.model !== undefined;
        },

        /** Linhas da lista, já com tudo o que o modelo precisa (o build CSP não faz conta dentro do atributo). */
        get items() {
            const found = filterTags(this.all, this.query);
            const rows = this.filtering && this.query.trim() === '' ? [{ id: '', name: this.$el.dataset.clear, color: '', group: '' }, ...found] : found;

            return rows.map((tag, index) => ({
                ...tag,
                index,
                key: `${tag.id}`,
                domId: `${this.$el.dataset.listId}-${index}`,
                header: tag.group !== '' && (index === 0 || rows[index - 1].group !== tag.group),
                active: index === this.active,
                hasDot: tag.color !== '',
                dotClass: `tag-${tag.color}`,
                rowClass: index === this.active ? 'bg-canvas' : '',
            }));
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
            if (!this.$el.contains(event.relatedTarget)) {
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
            const { method, model } = this.$el.dataset;

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
