// O canvas do workflow, feito com Rete.js. Carregado sob demanda (import dinâmico em flow-editor.js): só a tela do editor paga por ele.
//
// O estado verdadeiro é o grafo (serialize.js); o canvas só o desenha. Cada bloco guarda `type` e `data`, e as portas de
// saída vêm de `outputsFor`, as mesmas que o servidor exige ligadas ao publicar.

import { ClassicPreset, NodeEditor } from 'rete';
import { AreaExtensions, AreaPlugin } from 'rete-area-plugin';
import { ConnectionPlugin, Presets as ConnectionPresets } from 'rete-connection-plugin';
import { LitPlugin, Presets as LitPresets } from '@retejs/lit-plugin';
import { getDOMSocketPosition } from 'rete-render-utils';
import { LitElement, html } from 'lit';
import { unsafeSVG } from 'lit/directives/unsafe-svg.js';
import { ICONS, categoryOf, previewOf } from './appearance.js';
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

    return html`<div class="flow-node ${node.selected ? 'is-selected' : ''} ${node.invalid ? 'is-invalid' : ''}" data-category=${categoryOf(node.type)} data-testid="node">
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

    const changed = () => {
        if (!loading) {
            hooks.onChange?.();
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

    async function load(source) {
        loading = true;
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

        loading = false;
        await AreaExtensions.zoomAt(area, editor.getNodes());
    }

    await load(graph);

    return {
        getGraph() {
            const nodes = editor.getNodes().map((node) => {
                const position = area.nodeViews.get(node.id)?.position ?? { x: 0, y: 0 };

                return { id: node.id, type: node.type, x: position.x, y: position.y, data: node.data };
            });

            return toGraph(nodes, editor.getConnections());
        },

        load,

        async addNode(type) {
            const box = container.getBoundingClientRect();
            const { x, y, k } = area.area.transform;
            const id = `n${Date.now().toString(36)}${Math.floor(Math.random() * 1296).toString(36)}`;
            const node = new FlowNode(id, type, defaultData(type), lookups);

            await editor.addNode(node);
            await area.translate(id, { x: (box.width / 2 - x) / k - 105 + Math.random() * 40, y: (box.height / 2 - y) / k - 60 + Math.random() * 40 });
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
            node.refreshPreview(lookups);
            const removed = node.syncOutputs();

            for (const c of editor.getConnections().filter((c) => c.source === id && removed.includes(c.sourceOutput))) {
                await editor.removeConnection(c.id);
            }

            await area.update('node', id);
            changed();
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

        destroy() {
            area.destroy();
        },
    };
}
