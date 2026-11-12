// Alpine do editor de workflows (/automacao/{id}). O canvas (Rete) só é baixado quando a tela abre; o estado fica aqui
// e a gravação vai para o Livewire (`$wire`), que valida no servidor. Ver flow-editor/editor.js.

import { CATEGORIES, categoryOf, clampToViewport, errorNodeIds } from './flow-editor/appearance.js';
import { BLOCKS, PALETTE_SHORTCUTS } from './flow-editor/nodes.js';
import { messagesOf, withMessages } from './flow-editor/serialize.js';
import { shown } from './shown.js';

document.addEventListener('alpine:init', () => {
    // Os dados da página (grafo, setores, etiquetas...) chegam em `data-config` (JSON) e não por argumento: o build CSP do Alpine não
    // tem `JSON.parse` nas expressões de atributo (ver tests/Unit/AlpineCspSubsetTest.php).
    window.Alpine.data('flowEditor', ($wire) => {
        // Fora do objeto reativo do Alpine: o canvas é grande e não deve virar proxy.
        let api = null;
        let config = null;

        return {
            palette: [
                ...Object.entries(BLOCKS)
                    .filter(([, block]) => block.palette)
                    .map(([type, block]) => ({ key: type, type, label: block.label, data: null })),
                ...PALETTE_SHORTCUTS.map((shortcut) => ({ ...shortcut })),
            ]
                .map((item) => ({ ...item, category: categoryOf(item.type), group: CATEGORIES[categoryOf(item.type)].label }))
                .sort((a, b) => Object.keys(CATEGORIES).indexOf(a.category) - Object.keys(CATEGORIES).indexOf(b.category)),
            sectors: [],
            tags: [],
            users: [],
            versions: [],
            catalog: [],
            messages: {},
            selected: null,
            errors: [],
            status: '',
            dirty: false,
            busy: false,
            failed: false,
            menu: null,
            canUndo: false,
            canRedo: false,
            panel: 'props', // `props` (propriedades do bloco) ou `test` (conversa de teste)
            clock: 'real', // `real`, `aberto` ou `fechado`: o "agora" do teste

            async init() {
                config = JSON.parse(this.$el.dataset.config);
                this.sectors = config.sectors;
                this.tags = config.tags;
                this.users = config.users;
                this.versions = config.versions;
                this.catalog = config.messages;
                this.messages = messagesOf(config.graph);

                try {
                    const { createEditor } = await import('./flow-editor/editor.js');

                    api = await createEditor(this.$refs.canvas, config.graph, {
                        onSelect: (node) => (this.selected = node),
                        onChange: () => (this.dirty = true),
                        onHistory: (state) => Object.assign(this, state),
                        onContextMenu: (info) => {
                            this.menu = info;
                            this.$nextTick(async () => {
                                await shown(this.$refs.menu);
                                this.fitMenu();
                            });
                        },
                        lookups: { sectors: config.sectors, tags: config.tags, users: config.users },
                    });
                } catch (error) {
                    this.failed = true;
                    console.error(error);
                }
            },

            destroy() {
                api?.destroy();
            },

            // O que as telas leem do simulador (`$wire.sim`, nulo antes de começar): getters, porque o build CSP do Alpine não tem `?.` nem `??`.
            get hasSimErrors() {
                return ($wire.simErrors?.length ?? 0) > 0;
            },

            get simLog() {
                return $wire.sim?.log ?? [];
            },

            get simTrail() {
                return $wire.sim?.trail ?? [];
            },

            get simSession() {
                return $wire.sim?.session;
            },

            get simOutcome() {
                return $wire.sim?.outcome;
            },

            get simOutcomeText() {
                return this.simOutcome === 'fila' ? 'Desfecho: foi para a fila de atendimento.' : 'Desfecho: conversa encerrada pelo bot.';
            },

            // Menu de clique direito: posição (já puxada para dentro da tela por `fitMenu`).
            get menuStyle() {
                return this.menu ? `left:${this.menu.x}px;top:${this.menu.y}px` : '';
            },

            /** "Voltar ao padrão" só aparece se o workflow trocou o texto (e o rodapé escondido tem o próprio controle). */
            canResetMessage(key) {
                return key in this.messages && !(key === 'rodape_sair' && this.exitHintHidden());
            },

            messageText(key) {
                return this.messages[key] ?? '';
            },

            // Caixa de seleção: o `x-model` grava o valor primeiro, depois o bloco é atualizado.
            applyLater() {
                this.$nextTick(() => this.apply());
            },

            restoreVersion(id) {
                if (window.confirm('Copiar esta versão para o rascunho? O rascunho atual será substituído.')) {
                    this.restore(id);
                }
            },

            fields() {
                return (BLOCKS[this.selected?.type]?.fields ?? []).filter((field) => !field.when || Object.entries(field.when).every(([key, value]) => (this.selected.data[key] ?? false) === value));
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

            /** Depois de o menu aparecer, o puxa para dentro da tela (clique direito perto da borda). */
            fitMenu() {
                const el = this.$refs.menu;

                if (el && this.menu) {
                    const box = el.getBoundingClientRect();

                    this.menu = { ...this.menu, ...clampToViewport(this.menu.x, this.menu.y, box.width, box.height, window.innerWidth, window.innerHeight) };
                }
            },

            // Menu de clique direito: cada item age e fecha o menu.
            menuAdd(item) {
                api?.addNode(item.type, { x: this.menu.x, y: this.menu.y }, item.data);
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

                // Backspace com o foco num botão ou link da página não é "apagar o bloco" (Delete continua valendo em qualquer lugar).
                if (key === 'backspace' && target?.closest?.('button, a, summary, [role="button"], [role="menuitem"]')) {
                    return;
                }

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

            add(item) {
                api?.addNode(item.type, null, item.data);
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

            // A conversa de teste vive no servidor (`$wire.sim`, só leitura aqui); o motor é o mesmo do bot, sem gravar nem enviar nada.
            async openTest() {
                this.panel = 'test';

                if (!$wire.sim?.session && !$wire.simErrors?.length) {
                    await this.testStart();
                }
            },

            closeTest() {
                this.panel = 'props';
                api?.setActive(null);
            },

            /** Chama uma ação do simulador com o grafo do canvas (salvo ou não) e realça o bloco em que a conversa parou. */
            async testCall(action) {
                this.busy = true;

                try {
                    await action(withMessages(api.getGraph(), this.messages));
                } finally {
                    this.busy = false;
                }

                api?.setActive(this.testCurrent());
            },

            testStart() {
                return this.testCall((graph) => $wire.simStart(graph, this.clock));
            },

            testReply() {
                return this.testCall((graph) => $wire.simReply(graph, $wire.simText));
            },

            testSilence() {
                return this.testCall((graph) => $wire.simSilence(graph));
            },

            testWaiting() {
                return $wire.sim?.outcome == null && $wire.sim?.session?.waiting != null;
            },

            /** O bloco em que a conversa está esperando; terminada, o último que ela percorreu. */
            testCurrent() {
                const sim = $wire.sim;

                if (!sim?.session) {
                    return null;
                }

                return sim.outcome == null ? sim.session.node_id : (sim.trail.at(-1) ?? null);
            },

            testLabel(id) {
                const node = api?.getGraph().nodes.find((n) => n.id === id);

                return BLOCKS[node?.type]?.label ?? id;
            },

            testVars() {
                return Object.entries($wire.sim?.session?.vars ?? {})
                    .filter(([key]) => !key.startsWith('_'))
                    .map(([key, value]) => ({ key, value: String(value) }));
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
