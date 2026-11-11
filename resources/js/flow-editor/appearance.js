// Lógica pura do visual do editor (sem DOM): categorias, ícones, prévia do conteúdo, blocos com erro, organizar, duplicar e histórico.
// Fica fora do Rete para rodar sob `node --test`.

export const CATEGORIES = {
    inicio: { label: 'Início' },
    fala: { label: 'Falas' },
    espera: { label: 'Esperas' },
    logica: { label: 'Decisões' },
    acao: { label: 'Ações' },
    fim: { label: 'Fim' },
};

// Miolo de SVG 24x24 (traço), desenhado dentro de <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">.
export const ICONS = {
    play: '<path d="M7 5l12 7-12 7z"/>',
    chat: '<path d="M4 5h16v11H9l-5 4z"/>',
    list: '<path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/>',
    question: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 115 0c0 1.5-2.5 2-2.5 3.5M12 17h.01"/>',
    branch: '<path d="M6 4v8a4 4 0 004 4h4M6 4a2 2 0 100 .01M18 16a2 2 0 100 .01M6 12h8"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    image: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 16l5-5 4 4 3-3 6 6"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 20c1-4 4-6 8-6s7 2 8 6"/>',
    tag: '<path d="M3 12V4h8l10 10-8 8z"/><path d="M7.5 7.5h.01"/>',
    forward: '<path d="M4 12h14M13 6l6 6-6 6"/>',
    stop: '<rect x="6" y="6" width="12" height="12" rx="2"/>',
};

const BY_TYPE = {
    inicio: 'inicio',
    mensagem: 'fala',
    menu_setores: 'fala',
    menu: 'fala',
    pergunta: 'fala',
    midia: 'fala',
    esperar: 'espera',
    condicao: 'logica',
    salvar_contato: 'acao',
    etiquetar: 'acao',
    transferir: 'acao',
    encerrar: 'fim',
};

export function categoryOf(type) {
    return BY_TYPE[type] ?? 'acao';
}

const MAX_LINE = 60;
const OPS = { igual: 'é igual a', diferente: 'é diferente de', contem: 'contém', preenchida: 'está preenchida' };

function clip(value) {
    const text = String(value ?? '').replace(/\s+/g, ' ').trim();

    if (text === '') return '(sem texto)';

    return text.length > MAX_LINE ? `${text.slice(0, MAX_LINE - 1)}…` : text;
}

/** Até 3 linhas curtas com o conteúdo do bloco, para mostrar dentro dele no canvas. */
export function previewOf(type, data, lookups = {}) {
    const d = data ?? {};
    const nameOf = (list, id) => (list ?? []).find((item) => item.id === id)?.name;

    switch (type) {
        case 'inicio':
            return ['Começa aqui'];
        case 'encerrar':
            return ['Encerra o ticket'];
        case 'mensagem':
        case 'pergunta':
            return [clip(d.texto)];
        case 'midia':
            return [clip(d.legenda)];
        case 'menu_setores':
            return ['Lista os setores do menu'];
        case 'menu': {
            const options = Array.isArray(d.opcoes) ? d.opcoes : [];
            const shown = (options.length > 3 ? options.slice(0, 2) : options).map((o, i) => clip(`${i + 1} - ${o}`));

            return options.length > 3 ? [...shown, `+${options.length - 2}`] : shown.length ? shown : ['(sem opções)'];
        }
        case 'condicao':
            return d.kind === 'variavel' ? [clip(`${d.var ?? ''} ${OPS[d.op] ?? ''} ${d.valor ?? ''}`)] : ['Dentro do horário?'];
        case 'esperar':
            return [`Digitando por ${d.segundos ?? 0} s`];
        case 'salvar_contato':
            return [clip(`${d.campo ?? ''} ← ${d.variavel ?? ''}`)];
        case 'etiquetar':
            return [clip(nameOf(lookups.tags, d.tag_id) ?? 'Etiqueta removida')];
        case 'transferir':
            if (d.setor_id == null) return ['Setor do menu'];

            return [clip(nameOf(lookups.sectors, d.setor_id) ?? 'Setor removido')];
        default:
            return [];
    }
}

/** Ids dos blocos citados por erros no formato "<id>: mensagem" que existem no canvas. */
export function errorNodeIds(errors, nodeIds) {
    const known = new Set(nodeIds);
    const found = new Set();

    for (const error of errors ?? []) {
        const id = String(error).split(': ')[0];

        if (known.has(id)) found.add(id);
    }

    return found;
}

const COL = 300;
const ROW = 150;

/**
 * Posições em camadas, da esquerda para a direita. A camada é o caminho mais longo desde o Início (laços ignorados);
 * blocos sem caminho vão para a última camada + 1.
 * shortcut: não minimiza cruzamentos de linhas, só ordena pela ordem em que os blocos aparecem como destino; trocar por um layout de grafos se ficar feio.
 */
export function arrange(nodes, edges) {
    const ids = new Set(nodes.map((n) => n.id));
    const out = new Map(nodes.map((n) => [n.id, []]));
    const order = [];

    for (const e of edges) {
        if (!ids.has(e.from) || !ids.has(e.to)) continue;
        out.get(e.from).push(e.to);
        order.push(e.to);
    }

    const start = (nodes.find((n) => n.type === 'inicio') ?? nodes[0])?.id;
    const state = new Map(); // 1 = na pilha, 2 = terminado
    const forward = new Map(nodes.map((n) => [n.id, []]));
    const post = [];

    // DFS iterativa: descarta as arestas de retorno (laços) e guarda a ordem de término para relaxar as camadas.
    if (start !== undefined) {
        const stack = [[start, 0]];
        state.set(start, 1);

        while (stack.length) {
            const frame = stack[stack.length - 1];
            const next = out.get(frame[0])[frame[1]++];

            if (next === undefined) {
                state.set(frame[0], 2);
                post.push(frame[0]);
                stack.pop();
            } else if (!state.has(next)) {
                forward.get(frame[0]).push(next);
                state.set(next, 1);
                stack.push([next, 0]);
            } else if (state.get(next) === 2) {
                forward.get(frame[0]).push(next);
            }
        }
    }

    const layer = new Map();

    for (const id of post.reverse()) {
        layer.set(id, layer.get(id) ?? 0);
        for (const to of forward.get(id)) layer.set(to, Math.max(layer.get(to) ?? 0, layer.get(id) + 1));
    }

    const last = Math.max(-1, ...layer.values());
    const rank = new Map();
    for (const id of order) if (!rank.has(id)) rank.set(id, rank.size);
    const indexOf = new Map(nodes.map((n, i) => [n.id, i]));
    const layerOf = (id) => layer.get(id) ?? last + 1;
    const sorted = [...nodes].sort(
        (a, b) => layerOf(a.id) - layerOf(b.id) || (rank.get(a.id) ?? Infinity) - (rank.get(b.id) ?? Infinity) || indexOf.get(a.id) - indexOf.get(b.id),
    );
    const rows = new Map();
    const positions = {};

    for (const n of sorted) {
        const l = layerOf(n.id);
        const row = rows.get(l) ?? 0;
        rows.set(l, row + 1);
        positions[n.id] = { x: l * COL, y: row * ROW };
    }

    return positions;
}

/** Cópia do bloco para o ponto 40 px abaixo e à direita, sem ligações; o Início não se duplica. */
export function duplicate(node, id) {
    if (node.type === 'inicio') return null;

    return { id, type: node.type, x: node.x + 40, y: node.y + 40, data: structuredClone(node.data ?? {}) };
}

/** Pilhas de desfazer/refazer de instantâneos em texto. `push` guarda o estado ANTES de uma mudança. */
export class History {
    #undo = [];
    #redo = [];

    constructor(limit = 50) {
        this.limit = limit;
    }

    push(snapshot) {
        if (this.#undo.at(-1) === snapshot) return;

        this.#undo.push(snapshot);
        if (this.#undo.length > this.limit) this.#undo.shift();
        this.#redo = [];
    }

    undo(current) {
        if (!this.#undo.length) return null;

        this.#redo.push(current);

        return this.#undo.pop();
    }

    redo(current) {
        if (!this.#redo.length) return null;

        this.#undo.push(current);

        return this.#redo.pop();
    }

    canUndo() {
        return this.#undo.length > 0;
    }

    canRedo() {
        return this.#redo.length > 0;
    }
}
