// O canvas do workflow, feito com Rete.js. Carregado sob demanda (import dinâmico em flow-editor.js): só a tela do editor paga por ele.
//
// O estado verdadeiro é o grafo (serialize.js); o canvas só o desenha. Cada bloco guarda `type` e `data`, e as portas de
// saída vêm de `outputsFor`, as mesmas que o servidor exige ligadas ao publicar.

import { ClassicPreset, NodeEditor } from 'rete';
import { AreaExtensions, AreaPlugin } from 'rete-area-plugin';
import { ConnectionPlugin, Presets as ConnectionPresets } from 'rete-connection-plugin';
import { LitPlugin, Presets as LitPresets } from '@retejs/lit-plugin';
import { MinimapPlugin } from 'rete-minimap-plugin';
import { getDOMSocketPosition } from 'rete-render-utils';
import { LitElement, html } from 'lit';
import { unsafeSVG } from 'lit/directives/unsafe-svg.js';
import { History, ICONS, arrange, categoryOf, duplicate, previewOf } from './appearance.js';
import { BLOCKS, defaultData, outputsFor } from './nodes.js';
import { fromGraph, toGraph } from './serialize.js';

const socket = new ClassicPreset.Socket('fluxo');
const CHANGES = ['nodecreated', 'noderemoved', 'connectioncreated', 'connectionremoved'];

class FlowNode extends ClassicPreset.Node {
    constructor(id, type, data, lookups) {
        super(BLOCKS[type]?.label ?? type);
        this.id = id;
        this.type = type;
        this.data = data;
        this.width = 230;
        this.invalid = false;
        this.preview = [];
        this.refreshPreview(lookups);

        if (type !== 'inicio') {
            this.addInput('in', new ClassicPreset.Input(socket, ''));
        }

        this.syncOutputs();
    }

    /** Deixa as saídas iguais às portas do bloco; devolve as que sumiram (para o chamador apagar as ligações). */
    syncOutputs() {
        const wanted = outputsFor(this.type, this.data);
        const removed = Object.keys(this.outputs).filter((key) => !wanted.some((o) => o.port === key));

        removed.forEach((key) => this.removeOutput(key));
        wanted.forEach(({ port, label }) => {
            if (this.outputs[port]) {
                this.outputs[port].label = label;
            } else {
                // Uma ligação por porta: cada saída leva a um só destino.
                this.addOutput(port, new ClassicPreset.Output(socket, label, false));
            }
        });

        this.height = 56 + this.preview.length * 18 + wanted.length * 28;

        return removed;
    }

    refreshPreview(lookups) {
        this.preview = previewOf(this.type, this.data, lookups);
    }
}

/** O desenho do bloco: cabeçalho com ícone e cor da categoria, prévia do conteúdo e uma linha por porta de saída. */
function nodeTemplate(node, emit) {
    const socket = (side, key, payload) => html`<span class="flow-socket flow-socket-${side}"><rete-ref .data=${{ type: 'socket', side, key, nodeId: node.id, payload }} .emit=${emit}></rete-ref></span>`;
    const inputs = Object.entries(node.inputs).map(([key, input]) => (input ? socket('input', key, input.socket) : null));
    const outputs = Object.entries(node.outputs).map(([key, output]) => (output ? html`<div class="flow-out" data-testid=${`output-${key}`}><span class="flow-out-label">${output.label}</span>${socket('output', key, output.socket)}</div>` : null));

    return html`<div data-node-id=${node.id} class="flow-node ${node.selected ? 'is-selected' : ''} ${node.invalid ? 'is-invalid' : ''}" data-category=${categoryOf(node.type)} data-testid="node">
        <div class="flow-head">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${unsafeSVG(ICONS[BLOCKS[node.type]?.icon] ?? '')}</svg>
            <span class="flow-title" data-testid="title">${node.label}</span>
            ${inputs}
        </div>
        <div class="flow-body">${node.preview.map((line) => html`<p>${line}</p>`)}</div>
        ${outputs}
    </div>`;
}

const CONNECTION_CSS = `
    .flow-conn { position: absolute; overflow: visible !important; width: 9999px; height: 9999px; pointer-events: none; color: var(--color-ink-3, #78716c); }
    .flow-conn-line { fill: none; stroke: currentColor; stroke-width: 2.5px; }
    .flow-conn-hit { fill: none; stroke: transparent; stroke-width: 16px; pointer-events: stroke; }
    .flow-conn:hover { color: var(--color-accent-content, #854d0e); }
`;

// A bolinha também vive em shadow DOM (dentro de <rete-socket>): a cor da categoria chega por variável CSS herdada.
const SOCKET_CSS = `
    .dot { box-sizing: border-box; width: 14px; height: 14px; border-radius: 50%; border: 2px solid var(--color-surface, #fff); background: var(--flow-cat, #0f766e); box-shadow: 0 0 0 1px var(--flow-cat, #0f766e); cursor: crosshair; transition: transform 0.1s; }
    .dot:hover { transform: scale(1.35); background: var(--color-accent, #facc15); }
`;
const socketTemplate = () => html`<style>${SOCKET_CSS}</style><div class="dot"></div>`;

// Elemento próprio porque o Rete só atualiza (seleção, erro, prévia) as propriedades de um elemento já montado, nunca um template solto.
class FlowNodeElement extends LitElement {
    static properties = {
        data: { attribute: false, hasChanged: () => true }, // o mesmo objeto, mutado pelo Rete: sempre redesenha
        emit: { attribute: false },
    };

    // Sem shadow DOM: o CSS global do editor (.flow-*) vale aqui dentro.
    createRenderRoot() {
        return this;
    }

    render() {
        return nodeTemplate(this.data, this.emit);
    }
}

if (!customElements.get('flow-node')) {
    customElements.define('flow-node', FlowNodeElement);
}

/** A linha: o traço que o Rete calcula, com uma faixa invisível mais larga para o mouse acertar e uma seta no fim. */
function connectionTemplate({ path }) {
    // A linha vive no shadow DOM do wrapper do Rete: o CSS global não chega aqui, então o estilo vai junto.
    return html`<style>${CONNECTION_CSS}</style><svg class="flow-conn" data-testid="connection">
        <defs><marker id="flow-arrow" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="currentColor"/></marker></defs>
        <path class="flow-conn-hit" d=${path}/>
        <path class="flow-conn-line" d=${path} marker-end="url(#flow-arrow)"/>
    </svg>`;
}

export async function createEditor(container, graph, hooks) {
    const editor = new NodeEditor();
    const area = new AreaPlugin(container);
    const connection = new ConnectionPlugin();
    const render = new LitPlugin();
    const selector = AreaExtensions.selector();
    const selection = AreaExtensions.selectableNodes(area, selector, { accumulating: AreaExtensions.accumulateOnCtrl() });
    let loading = false;
    let history = new History();
    let last = ''; // o último estado guardado no histórico (nós e ligações; as mensagens do bot ficam de fora)
    let timer = null;
    const newId = () => `n${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`;
    const snapshot = () => JSON.stringify(api.getGraph());
    const announce = () => hooks.onHistory?.({ canUndo: history.canUndo(), canRedo: history.canRedo() });

    /** Guarda o estado anterior no histórico se algo mudou desde o último registro. */
    const commit = () => {
        clearTimeout(timer);

        // Com o canvas sendo redesenhado o grafo está pela metade: gravar agora corromperia o histórico.
        if (loading) {
            return;
        }

        const now = snapshot();

        if (now !== last) {
            history.push(last);
            last = now;
            announce();
        }
    };

    const lookups = hooks.lookups ?? {};

    render.addPreset(
        LitPresets.classic.setup({
            socketPositionWatcher: getDOMSocketPosition({ offset: (position) => position }),
            customize: {
                node: (data) => ({ emit }) => html`<flow-node .data=${data.payload} .emit=${emit}></flow-node>`,
                connection: () => connectionTemplate,
                socket: () => socketTemplate,
            },
        }),
    );
    connection.addPreset(ConnectionPresets.classic.setup());
    editor.use(area);
    area.use(connection);
    area.use(render);
    AreaExtensions.simpleNodesOrder(area);

    // O minimapa é um extra: se não carregar, o editor abre do mesmo jeito.
    try {
        render.addPreset(LitPresets.minimap.setup({ size: 180 }));
        area.use(new MinimapPlugin({ boundViewport: true }));
    } catch (error) {
        console.warn('Minimapa indisponível', error);
    }

    const changed = () => {
        if (!loading) {
            hooks.onChange?.();
            clearTimeout(timer);
            timer = setTimeout(commit, 300);
        }
    };

    editor.addPipe((context) => {
        if (context.type === 'connectioncreated') {
            // Uma saída só leva a um destino: a ligação nova substitui a antiga da mesma porta.
            const made = context.data;
            const stale = editor.getConnections().filter((c) => c.id !== made.id && c.source === made.source && c.sourceOutput === made.sourceOutput);

            stale.forEach((c) => queueMicrotask(() => editor.removeConnection(c.id)));
        }

        if (CHANGES.includes(context.type)) {
            changed();
        }

        return context;
    });

    area.addPipe((context) => {
        if (context.type === 'nodepicked') {
            hooks.onSelect?.(describe(editor.getNode(context.data.id)));
        } else if (context.type === 'zoomed' || context.type === 'translated') {
            // O fundo pontilhado acompanha o zoom e o arrasto do canvas.
            const { x, y, k } = area.area.transform;

            container.style.backgroundSize = `${24 * k}px ${24 * k}px`;
            container.style.backgroundPosition = `${x}px ${y}px`;
        } else if (context.type === 'nodetranslated') {
            changed();
        } else if (context.type === 'pointerdown' && context.data.event.target === container) {
            hooks.onSelect?.(null);
        }

        return context;
    });

    const describe = (node) => (node ? { id: node.id, type: node.type, data: structuredClone(node.data) } : null);

    async function draw(source, { fit = true } = {}) {
        loading = true;

        try {
            await drawNodes(source);
        } finally {
            loading = false;
        }

        if (fit) {
            await AreaExtensions.zoomAt(area, editor.getNodes());
        }
    }

    async function drawNodes(source) {
        await editor.clear();

        const { nodes, connections } = fromGraph(source);

        for (const n of nodes) {
            const node = new FlowNode(n.id, n.type, n.data, lookups);

            await editor.addNode(node);
            await area.translate(node.id, { x: n.x, y: n.y });
        }

        for (const c of connections) {
            const from = editor.getNode(c.source);
            const to = editor.getNode(c.target);

            if (from && to && from.outputs[c.sourceOutput] && to.inputs.in) {
                await editor.addConnection(new ClassicPreset.Connection(from, c.sourceOutput, to, 'in'));
            }
        }
    }

    /** Troca o conteúdo do canvas por `source` e recomeça o histórico. */
    async function load(source) {
        await draw(source);
        history = new History();
        last = snapshot();
        announce();
    }

    /** Volta (ou avança) para um estado do histórico sem fechar o enquadramento atual. */
    async function restore(state) {
        await draw(JSON.parse(state), { fit: false });
        last = state;
        hooks.onSelect?.(null);
        hooks.onChange?.();
        announce();
    }

    const api = {
        getGraph() {
            const nodes = editor.getNodes().map((node) => {
                const position = area.nodeViews.get(node.id)?.position ?? { x: 0, y: 0 };

                return { id: node.id, type: node.type, x: position.x, y: position.y, data: node.data };
            });

            return toGraph(nodes, editor.getConnections());
        },

        load,

        /** Cria o bloco no ponto da tela dado (clique direito) ou, sem ponto, perto do centro do canvas. */
        async addNode(type, at = null) {
            const box = container.getBoundingClientRect();
            const { x, y, k } = area.area.transform;
            const id = newId();
            const node = new FlowNode(id, type, defaultData(type), lookups);
            const screen = at ? { x: at.x - box.left, y: at.y - box.top } : { x: box.width / 2 + Math.random() * 40 - 105, y: box.height / 2 + Math.random() * 40 - 60 };

            await editor.addNode(node);
            await area.translate(id, { x: (screen.x - x) / k, y: (screen.y - y) / k });
            await selection.select(id, false);
            hooks.onSelect?.(describe(node));

            return id;
        },

        /** Aplica os dados editados no painel; o menu muda de portas junto com as opções. */
        async updateData(id, data) {
            const node = editor.getNode(id);

            if (!node) {
                return;
            }

            node.data = data;
            node.invalid = false; // editar o bloco limpa a marca de erro até a próxima validação
            node.refreshPreview(lookups);
            const removed = node.syncOutputs();

            for (const c of editor.getConnections().filter((c) => c.source === id && removed.includes(c.sourceOutput))) {
                await editor.removeConnection(c.id);
            }

            await area.update('node', id);
            changed();
        },

        /** Aproxima (factor > 1) ou afasta (< 1) a partir do centro do canvas. */
        async zoomBy(factor) {
            const box = container.getBoundingClientRect();

            await area.area.zoom(area.area.transform.k * factor, box.width / 2, box.height / 2);
        },

        async fit() {
            await AreaExtensions.zoomAt(area, editor.getNodes());
        },

        /** Arruma os blocos em camadas (ver `arrange`) e enquadra o resultado; conta como alteração do rascunho. */
        async organize() {
            const { nodes, edges } = this.getGraph();
            const positions = arrange(nodes, edges);

            for (const [id, position] of Object.entries(positions)) {
                await area.translate(id, position);
            }

            await this.fit();
        },

        /** Marca em vermelho os blocos citados por erros de validação. */
        async setErrors(ids) {
            for (const node of editor.getNodes()) {
                const invalid = ids.has(node.id);

                if (node.invalid !== invalid) {
                    node.invalid = invalid;
                    await area.update('node', node.id);
                }
            }
        },

        async removeNode(id) {
            for (const c of editor.getConnections().filter((c) => c.source === id || c.target === id)) {
                await editor.removeConnection(c.id);
            }

            await editor.removeNode(id);
            hooks.onSelect?.(null);
        },

        async focus(id) {
            const node = editor.getNode(id);

            if (node) {
                await AreaExtensions.zoomAt(area, [node]);
                await selection.select(id, false);
                hooks.onSelect?.(describe(node));
            }
        },

        async undo() {
            if (loading) {
                return;
            }

            commit();

            const state = history.undo(last);

            if (state !== null) {
                await restore(state);
            }
        },

        async redo() {
            if (loading) {
                return;
            }

            commit();

            const state = history.redo(last);

            if (state !== null) {
                await restore(state);
            }
        },

        /** Copia o bloco (sem as ligações) para um pouco abaixo e à direita, e o seleciona. */
        async duplicateNode(id) {
            const node = editor.getNode(id);
            const position = area.nodeViews.get(id)?.position;
            const copy = node && position ? duplicate({ id, type: node.type, x: position.x, y: position.y, data: node.data }, newId()) : null;

            if (!copy) {
                return null;
            }

            const clone = new FlowNode(copy.id, copy.type, copy.data, lookups);

            await editor.addNode(clone);
            await area.translate(copy.id, { x: copy.x, y: copy.y });
            await selection.select(copy.id, false);
            hooks.onSelect?.(describe(clone));

            return copy.id;
        },

        destroy() {
            clearTimeout(timer);
            area.destroy();
        },
    };

    await load(graph);

    // Clique direito: o menu é do Alpine; aqui só se diz onde e sobre qual bloco.
    container.addEventListener('contextmenu', (event) => {
        event.preventDefault();
        const nodeId = event.target.closest?.('.flow-node')?.dataset.nodeId ?? null;

        hooks.onContextMenu?.({ x: event.clientX, y: event.clientY, nodeId, type: nodeId ? editor.getNode(nodeId)?.type : null });
    });

    return api;
}
