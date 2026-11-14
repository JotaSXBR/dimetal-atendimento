// Variáveis que o campo de texto de um bloco aceita: as embutidas (`Vars::BUILTIN`, vindas do servidor) e as respostas guardadas
// pelos blocos Pergunta do próprio fluxo. Só lista; quem insere no campo é o `insertAt` (composer-insert.js).

// `Vars::render` só troca `{{[a-z0-9_]+}}` (e procura a chave em minúsculas), e `BotRun::vars()` descarta o que começa por `_`.
const RESOLVABLE = /^[a-z0-9][a-z0-9_]*$/;

/** `{{chave}}`, o formato que o `Vars::render` troca. */
export function token(key) {
    return `{{${key}}}`;
}

/** @returns {{key: string, label: string}[]} embutidas e depois as respostas das perguntas (sem vazias nem repetidas), na ordem do grafo */
export function variableChoices(builtin, nodes) {
    const seen = new Set(builtin.map((v) => v.key));
    const answers = [];

    for (const node of nodes ?? []) {
        const key = node.type === 'pergunta' && typeof node.data?.variavel === 'string' ? node.data.variavel.trim() : '';

        if (RESOLVABLE.test(key) && !seen.has(key)) {
            seen.add(key);
            answers.push({ key, label: `Resposta: ${key}` });
        }
    }

    return [...builtin, ...answers];
}
