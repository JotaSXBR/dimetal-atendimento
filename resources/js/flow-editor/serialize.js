// Ponte entre o canvas (blocos e ligações do Rete) e o grafo que o servidor guarda:
//   { nodes: [{id, type, x, y, data}], edges: [{from, port, to}] }
// O grafo é o único contrato; trocar a biblioteca do canvas não muda o motor.

/** @returns {{nodes: object[], connections: object[]}} */
export function fromGraph(graph) {
    const nodes = (graph?.nodes ?? []).map((n) => ({ id: n.id, type: n.type, x: n.x ?? 0, y: n.y ?? 0, data: n.data ?? {} }));
    const connections = (graph?.edges ?? []).map((e) => ({ source: e.from, sourceOutput: e.port ?? 'out', target: e.to }));

    return { nodes, connections };
}

export function toGraph(nodes, connections) {
    const ids = new Set(nodes.map((n) => n.id));

    return {
        nodes: nodes.map((n) => ({ id: n.id, type: n.type, x: Math.round(n.x), y: Math.round(n.y), data: n.data })),
        edges: connections
            .filter((c) => ids.has(c.source) && ids.has(c.target))
            .map((c) => ({ from: c.source, port: c.sourceOutput, to: c.target })),
    };
}

/** O grafo com as mensagens do bot junto: o Rete só conhece blocos e ligações, então elas viajam ao lado, em `settings`. */
export function withMessages(graph, messages) {
    const mensagens = Object.fromEntries(Object.entries(messages ?? {}).filter(([, value]) => typeof value === 'string'));

    return { ...graph, settings: { mensagens } };
}

/** As mensagens do bot que o grafo guarda (nenhuma, se não há ou se o formato está quebrado). */
export function messagesOf(graph) {
    const mensagens = graph?.settings?.mensagens;

    return mensagens && typeof mensagens === 'object' && !Array.isArray(mensagens) ? { ...mensagens } : {};
}
