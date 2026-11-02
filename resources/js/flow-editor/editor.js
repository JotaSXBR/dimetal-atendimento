// O canvas do workflow, feito com Rete.js. Carregado sob demanda (import dinâmico em flow-editor.js): só a tela do editor paga por ele.
//
// O estado verdadeiro é o grafo (serialize.js); o canvas só o desenha. Cada bloco guarda `type` e `data`, e as portas de
// saída vêm de `outputsFor`, as mesmas que o servidor exige ligadas ao publicar.

import { ClassicPreset, NodeEditor } from 'rete';
import { AreaExtensions, AreaPlugin } from 'rete-area-plugin';
import { ConnectionPlugin, Presets as ConnectionPresets } from 'rete-connection-plugin';
import { LitPlugin, Presets as LitPresets } from '@retejs/lit-plugin';
import { BLOCKS, defaultData, outputsFor } from './nodes.js';
import { fromGraph, toGraph } from './serialize.js';

const socket = new ClassicPreset.Socket('fluxo');
const CHANGES = ['nodecreated', 'noderemoved', 'connectioncreated', 'connectionremoved'];

class FlowNode extends ClassicPreset.Node {
    constructor(id, type, data) {
        super(BLOCKS[type]?.label ?? type);
        this.id = id;
        this.type = type;
        this.data = data;
        this.width = 210;

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

        this.height = 70 + wanted.length * 30;

        return removed;
    }
}

export async function createEditor(container, graph, hooks) {
    const editor = new NodeEditor();
    const area = new AreaPlugin(container);
    const connection = new ConnectionPlugin();
    const render = new LitPlugin();
    const selector = AreaExtensions.selector();
    const selection = AreaExtensions.selectableNodes(area, selector, { accumulating: AreaExtensions.accumulateOnCtrl() });
    let loading = false;

    render.addPreset(LitPresets.classic.setup());
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
            const node = new FlowNode(n.id, n.type, n.data);

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
            const node = new FlowNode(id, type, defaultData(type));

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
            const removed = node.syncOutputs();

            for (const c of editor.getConnections().filter((c) => c.source === id && removed.includes(c.sourceOutput))) {
                await editor.removeConnection(c.id);
            }

            await area.update('node', id);
            changed();
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
