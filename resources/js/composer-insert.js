// Inserção de texto no cursor do campo de mensagem (emoji e resposta rápida). Lógica pura: o `composerMode` lê e grava o campo.

/**
 * Troca o trecho [start, end) de `value` por `text`. Índices fora do texto são limitados a ele; início maior que o fim vale
 * como cursor simples no início. Devolve o novo valor e a posição do cursor logo depois do texto inserido.
 */
export function insertAt(value, start, end, text) {
    const clamp = (n) => Math.min(Math.max(n, 0), value.length);
    const from = clamp(start);
    const to = Math.max(from, clamp(end));

    return { value: value.slice(0, from) + text + value.slice(to), caret: from + text.length };
}
