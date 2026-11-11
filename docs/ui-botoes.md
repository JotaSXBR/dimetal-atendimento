# Botões da interface

Só `flux:button` (Flux 2.21, grátis). O `x-ui.button` próprio foi removido; um teste (`tests/Unit/ViewConventionsTest.php`) impede a volta.

## Quando usar cada variante

| Variante | Uso | Exemplo |
|---|---|---|
| `primary` | **A** ação principal da tela ou da seção. No máximo uma por contexto. | "Nova conversa", "Assumir", "Salvar contato", "Entrar" |
| `filled` | Ação secundária com peso (precisa se destacar do fundo, ou vem em grupo). | "Reconectar", "Editar contato" (em `flux:button.group`) |
| `subtle` | Ação secundária discreta, linha densa, cancelar. | "Cancelar", "Detalhes", filtros |
| `ghost` | Ação terciária sem borda nem fundo. | "Apagar canal" |
| `danger` | **Só** ação destrutiva ou de alerta crítico. | "Apagar", "Abrir atendimento" (aviso de SLA estourado) |

Vermelho é só para SLA estourado e erro: `danger` não serve de "destaque".

## Tamanho

- `sm` é o padrão de tela (32px).
- `xs` em faixas de aviso, linhas densas e grupos no painel de detalhes.
- Tamanho base (40px) só em formulário de página inteira, como o login.

## Regras

- **Ícone sem texto** sempre com `aria-label` e, quando couber, `tooltip` (o tooltip só aparece com mouse: o `aria-label` é o que vale em toque e leitor de tela).
- **Ações vizinhas** usam `flux:button.group` com `variant="filled"` ou `outline` (as variantes `subtle` e `ghost` não se colam).
- **Carregamento**: `flux:button` com `wire:click` ou `type="submit"` já mostra o spinner e bloqueia o clique; não repetir com `wire:loading` à mão, só `wire:loading.attr="disabled"` quando o botão faz uma chamada longa e há outras ações na tela.
- **Destrutivo** pede confirmação (`wire:confirm`) e usa o texto do que será perdido.
- Mantém `wire:click`, `wire:confirm`, `x-on:click`, `data-testid`/`data-test` ao trocar o componente.
- `<button>` cru só para **gatilho interno** (abas `role="tab"`, itens de menu e de lista selecionável, controles do Alpine). Os que sobram estão listados em `ViewConventionsTest` com o motivo; mudar a lista é uma decisão, não um acaso.

## Migração do `x-ui.button`

`primary` → `variant="primary"`; `secondary` → `variant="filled"`; `ghost` → `variant="ghost"`; `type` e `wire:*` ficam como estavam.
