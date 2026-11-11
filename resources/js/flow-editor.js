// Alpine do editor de workflows (/automacao/{id}). O canvas (Rete) só é baixado quando a tela abre; o estado fica aqui
// e a gravação vai para o Livewire (`$wire`), que valida no servidor. Ver flow-editor/editor.js.

import { CATEGORIES, categoryOf, errorNodeIds } from './flow-editor/appearance.js';
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
            menu: null,
            canUndo: false,
            canRedo: false,

            async init() {
                try {
                    const { createEditor } = await import('./flow-editor/editor.js');

                    api = await createEditor(this.$refs.canvas, config.graph, {
                        onSelect: (node) => (this.selected = node),
                        onChange: () => (this.dirty = true),
                        onHistory: (state) => Object.assign(this, state),
                        onContextMenu: (info) => (this.menu = info),
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

            undo() {
                api?.undo();
            },

            redo() {
                api?.redo();
            },

            duplicate(id) {
                api?.duplicateNode(id);
            },

            // Menu de clique direito: cada item age e fecha o menu.
            menuAdd(type) {
                api?.addNode(type, { x: this.menu.x, y: this.menu.y });
                this.menu = null;
            },

            menuDuplicate() {
                this.duplicate(this.menu.nodeId);
                this.menu = null;
            },

            menuRemove() {
                api?.removeNode(this.menu.nodeId);
                this.menu = null;
            },

            /** Atalhos do canvas; em campo de texto a tecla é do campo. */
            shortcut(event) {
                const target = event.target;

                if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName) || target?.isContentEditable) {
                    return;
                }

                const mod = event.ctrlKey || event.metaKey;
                const key = event.key.toLowerCase();
                const movable = this.selected && this.selected.type !== 'inicio';

                if (!mod && (key === 'delete' || key === 'backspace') && movable) {
                    this.remove();
                } else if (mod && key === 'z' && !event.shiftKey) {
                    this.undo();
                } else if (mod && (key === 'y' || (key === 'z' && event.shiftKey))) {
                    this.redo();
                } else if (mod && key === 'd' && movable) {
                    this.duplicate(this.selected.id);
                } else {
                    return;
                }

                event.preventDefault();
            },

            zoom(factor) {
                api?.zoomBy(factor);
            },

            fit() {
                api?.fit();
            },

            organize() {
                api?.organize();
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

            /** Pinta de vermelho os blocos citados pelos erros atuais (nenhum, se não há erro). */
            markErrors() {
                api?.setErrors(errorNodeIds(this.errors, api.getGraph().nodes.map((node) => node.id)));
            },

            async run(action) {
                this.busy = true;
                this.errors = [];
                this.markErrors();

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
                this.markErrors();
                this.status = this.errors.length === 0 ? 'Tudo certo: o workflow pode ser publicado.' : '';
            },

            async publish() {
                const result = await this.run((graph) => $wire.publish(graph));

                this.dirty = false;
                this.errors = result.errors ?? [];
                this.markErrors();
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
