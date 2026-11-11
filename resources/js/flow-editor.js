// Alpine do editor de workflows (/automacao/{id}). O canvas (Rete) só é baixado quando a tela abre; o estado fica aqui
// e a gravação vai para o Livewire (`$wire`), que valida no servidor. Ver flow-editor/editor.js.

import { CATEGORIES, categoryOf } from './flow-editor/appearance.js';
import { BLOCKS } from './flow-editor/nodes.js';
import { messagesOf, withMessages } from './flow-editor/serialize.js';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('flowEditor', ($wire, config) => {
        // Fora do objeto reativo do Alpine: o canvas é grande e não deve virar proxy.
        let api = null;

        return {
            palette: Object.entries(BLOCKS)
                .filter(([, block]) => block.palette)
                .map(([type, block]) => ({ type, label: block.label, category: categoryOf(type), group: CATEGORIES[categoryOf(type)].label }))
                .sort((a, b) => Object.keys(CATEGORIES).indexOf(a.category) - Object.keys(CATEGORIES).indexOf(b.category)),
            sectors: config.sectors,
            tags: config.tags,
            versions: config.versions,
            catalog: config.messages,
            messages: messagesOf(config.graph),
            selected: null,
            errors: [],
            status: '',
            dirty: false,
            busy: false,
            failed: false,

            async init() {
                try {
                    const { createEditor } = await import('./flow-editor/editor.js');

                    api = await createEditor(this.$refs.canvas, config.graph, {
                        onSelect: (node) => (this.selected = node),
                        onChange: () => (this.dirty = true),
                        lookups: { sectors: config.sectors, tags: config.tags },
                    });
                } catch (error) {
                    this.failed = true;
                    console.error(error);
                }
            },

            destroy() {
                api?.destroy();
            },

            fields() {
                return (BLOCKS[this.selected?.type]?.fields ?? []).filter((field) => !field.when || Object.entries(field.when).every(([key, value]) => this.selected.data[key] === value));
            },

            blockLabel(type) {
                return BLOCKS[type]?.label ?? type;
            },

            linesValue(key) {
                return (this.selected.data[key] ?? []).join('\n');
            },

            setLines(key, text) {
                this.selected.data[key] = text.split('\n').map((line) => line.trim()).filter((line) => line !== '');
                this.apply();
            },

            /** Texto próprio da mensagem; em branco volta ao padrão (a chave sai do grafo). */
            setMessage(key, value) {
                if (value.trim() === '') {
                    delete this.messages[key];
                } else {
                    this.messages[key] = value;
                }

                this.dirty = true;
            },

            resetMessage(key) {
                delete this.messages[key];
                this.dirty = true;
            },

            /** O rodapé do #sair é o único que pode sumir: texto vazio no grafo. */
            exitHintHidden() {
                return this.messages.rodape_sair === '';
            },

            toggleExitHint(hide) {
                if (hide) {
                    this.messages.rodape_sair = '';
                } else {
                    delete this.messages.rodape_sair;
                }

                this.dirty = true;
            },

            apply() {
                api?.updateData(this.selected.id, JSON.parse(JSON.stringify(this.selected.data)));
            },

            add(type) {
                api?.addNode(type);
            },

            remove() {
                if (this.selected && this.selected.type !== 'inicio') {
                    api.removeNode(this.selected.id);
                }
            },

            focus(error) {
                api?.focus(error.split(':')[0]);
            },

            async run(action) {
                this.busy = true;
                this.errors = [];

                try {
                    return await action(withMessages(api.getGraph(), this.messages));
                } finally {
                    this.busy = false;
                }
            },

            async save() {
                await this.run((graph) => $wire.saveDraft(graph));
                this.dirty = false;
                this.status = 'Rascunho salvo.';
            },

            async validate() {
                const result = await this.run((graph) => $wire.validateDraft(graph));

                this.dirty = false;
                this.errors = result.errors ?? [];
                this.status = this.errors.length === 0 ? 'Tudo certo: o workflow pode ser publicado.' : '';
            },

            async publish() {
                const result = await this.run((graph) => $wire.publish(graph));

                this.dirty = false;
                this.errors = result.errors ?? [];
                this.status = result.ok ? `Versão ${result.version} publicada.` : '';

                if (result.ok) {
                    this.versions = result.versions;
                }
            },

            async restore(versionId) {
                const result = await $wire.restore(versionId);

                await api.load(result.graph);
                this.messages = messagesOf(result.graph);
                this.selected = null;
                this.dirty = false;
                this.status = 'Versão copiada para o rascunho. Publique para valer.';
            },
        };
    });
});
