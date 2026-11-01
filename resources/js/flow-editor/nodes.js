// Os blocos do editor de workflows. Espelha `App\Services\Bot\Flow\NodeType` (tipos e portas de saída): se mudar lá, mude aqui.
//
// `fields` descreve o formulário do painel lateral; `kind` pode ser text, textarea, number, select, lines (um item por linha),
// sector e tag (os dois últimos listam o que o servidor entregou).

const timeout = { key: 'timeout_min', label: 'Minutos sem resposta até ir para a fila', kind: 'number', min: 1, max: 1440 };
const invalid = { key: 'invalido', label: 'Aviso de resposta inválida (opcional)', kind: 'textarea' };

export const BLOCKS = {
    inicio: { label: 'Início', palette: false, fields: [] },
    mensagem: {
        label: 'Mensagem',
        palette: true,
        data: { texto: 'Olá, {{nome}}!' },
        fields: [{ key: 'texto', label: 'Texto', kind: 'textarea' }],
    },
    menu_setores: {
        label: 'Menu de setores',
        palette: true,
        data: { texto: 'Com qual setor você quer falar? Responda com o número da opção.', timeout_min: 10 },
        fields: [{ key: 'texto', label: 'Texto antes da lista', kind: 'textarea' }, invalid, timeout],
    },
    menu: {
        label: 'Menu',
        palette: true,
        data: { texto: 'Escolha uma opção:', opcoes: ['Opção 1', 'Opção 2'], timeout_min: 10 },
        fields: [
            { key: 'texto', label: 'Texto antes da lista', kind: 'textarea' },
            { key: 'opcoes', label: 'Opções (uma por linha)', kind: 'lines' },
            invalid,
            timeout,
        ],
    },
    pergunta: {
        label: 'Pergunta',
        palette: true,
        data: { texto: 'Qual é o seu nome?', variavel: 'resposta', validacao: 'texto', timeout_min: 10 },
        fields: [
            { key: 'texto', label: 'Pergunta', kind: 'textarea' },
            { key: 'variavel', label: 'Guardar a resposta na variável', kind: 'text' },
            { key: 'validacao', label: 'Aceitar', kind: 'select', options: [['texto', 'Qualquer texto'], ['email', 'E-mail'], ['numero', 'Só números']] },
            invalid,
            timeout,
        ],
    },
    condicao: {
        label: 'Condição',
        palette: true,
        data: { kind: 'horario' },
        fields: [
            { key: 'kind', label: 'Testar', kind: 'select', options: [['horario', 'Dentro do horário de funcionamento'], ['variavel', 'Valor de uma variável']] },
            { key: 'var', label: 'Variável', kind: 'text', when: { kind: 'variavel' } },
            { key: 'op', label: 'Comparação', kind: 'select', when: { kind: 'variavel' }, options: [['igual', 'é igual a'], ['diferente', 'é diferente de'], ['contem', 'contém'], ['preenchida', 'está preenchida']] },
            { key: 'valor', label: 'Valor', kind: 'text', when: { kind: 'variavel' } },
        ],
    },
    esperar: {
        label: 'Esperar',
        palette: true,
        data: { segundos: 3 },
        fields: [{ key: 'segundos', label: 'Segundos mostrando "digitando…" (1 a 30)', kind: 'number', min: 1, max: 30 }],
    },
    midia: {
        label: 'Enviar mídia',
        palette: true,
        data: { path: '', legenda: '' },
        fields: [
            { key: 'path', label: 'Caminho do arquivo no armazenamento', kind: 'text' },
            { key: 'legenda', label: 'Legenda (opcional)', kind: 'textarea' },
        ],
    },
    salvar_contato: {
        label: 'Salvar no contato',
        palette: true,
        data: { campo: 'name', variavel: 'resposta' },
        fields: [
            { key: 'campo', label: 'Campo do contato', kind: 'select', options: [['name', 'Nome'], ['email', 'E-mail'], ['company', 'Empresa']] },
            { key: 'variavel', label: 'Valor vindo da variável', kind: 'text' },
        ],
    },
    etiquetar: {
        label: 'Etiquetar',
        palette: true,
        data: { tag_id: null },
        fields: [{ key: 'tag_id', label: 'Etiqueta', kind: 'tag' }],
    },
    transferir: {
        label: 'Transferir para setor',
        palette: true,
        data: { setor_id: null },
        fields: [{ key: 'setor_id', label: 'Setor (vazio = o escolhido no menu)', kind: 'sector' }],
    },
    encerrar: { label: 'Encerrar', palette: true, fields: [] },
};

/** Dados iniciais de um bloco novo (cópia, para um bloco não alterar o outro). */
export function defaultData(type) {
    return structuredClone(BLOCKS[type]?.data ?? {});
}

/** As portas de saída do bloco, na ordem em que aparecem, com o rótulo mostrado no canvas. */
export function outputsFor(type, data) {
    switch (type) {
        case 'transferir':
        case 'encerrar':
            return [];
        case 'menu_setores':
            return [{ port: 'setor', label: 'Setor escolhido' }];
        case 'condicao':
            return [
                { port: 'true', label: 'Sim' },
                { port: 'false', label: 'Não' },
            ];
        case 'menu': {
            const options = Array.isArray(data?.opcoes) ? data.opcoes : [];

            return Array.from({ length: Math.max(1, options.length) }, (_, i) => ({ port: `opt:${i + 1}`, label: `${i + 1} - ${options[i] ?? ''}`.trim() }));
        }
        default:
            return [{ port: 'out', label: '' }];
    }
}
