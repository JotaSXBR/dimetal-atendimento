# UX: Histórico e Contatos (fatia C) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, decisão do dono) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deixar o Histórico só com a busca (os filtros num popover "Filtros" com contagem, Limpar e Aplicar) e abrir a ficha de Contatos numa gaveta lateral em vez de uma coluna que fica vazia.

**Architecture:** Um componente Blade novo `x-popover` (código próprio, estado em `Alpine.data('popover')`, porque o build CSP não aceita método inline) abriga os filtros do Histórico; os campos passam a `wire:model` adiado e "Aplicar" dispara `$refresh`. A gaveta de Contatos usa o `flux:modal variant="flyout"` (grátis, já instalado) ligado a uma propriedade booleana do componente `Contacts\Index`.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2.21, Alpine (CSP), Tailwind, PHPUnit, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-14-ux-simplificacao-design.md` (itens 8 e 9, fatia C; componente `x-popover`).

## Global Constraints

- Branch `feat/ux-historico-e-contatos`, da fatia A (#120, ainda não mesclada); rebasear sobre a `main` quando a #120 for mesclada. PR com base `main`, "depende de #120" se ainda aberta.
- Sem dependência nova; nenhum código copiado do Flux Pro (só a aparência pública serve de referência). Cor só por token; texto ≥ `text-xs`; alvo ≥ 24 px; `<button>` cru novo entra na lista de `ViewConventionsTest`.
- Alpine em CSP: estado e métodos em `Alpine.data` (padrão de `resources/js/tag-picker.js`); `this.$el` é o elemento do evento, não a raiz: guardar a raiz em `init()`. Foco em campo recém-mostrado só vale com o campo visível (esperar `offsetParent !== null`).
- Os padrões do Histórico continuam: `De` = 30 dias atrás (sem janela quando há `contactId`); **o padrão não conta como filtro ativo**.
- `Livewire`: mudar propriedade/método público de componente exige `grep -rn` em `resources/views` e `tests/` (`from`, `to`, `sectorId`, `outcome`, `userId`, `selectedId`, `select`).
- Verificação visual em 360 px, 1280×720, 1920×1080 e 2560×1440, claro e escuro, com dado realista; script `docs/superpowers/audit/ux-audit.js`. Roteiro de teclado no Chrome para o popover (Esc, clique fora, foco).
- Commits em português com prefixo e `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus

- **Contagem:** com os padrões a contagem é zero; `De` diferente do padrão, `Até`, setor, motivo e atendente contam um cada; com `contactId` (vindo de "Ver todos") não há padrão de `De`, então qualquer `De` conta. (Task 1)
- **Limpar e Aplicar:** "Limpar" volta o `De` ao padrão e esvazia o resto; mudar um campo sem "Aplicar" não muda a lista nem a contagem; "Aplicar" aplica uma vez, volta à página 1 e fecha. (Task 1)
- **Popover:** Esc fecha só ele (e devolve o foco ao botão "Filtros"); clique fora fecha; ao abrir o foco vai ao primeiro campo; o seletor e a data nativos funcionam dentro dele. (Task 1)
- **Gaveta de Contatos:** "Conversar" da linha não abre a ficha; fechar a gaveta (Esc, ✕, clique fora) limpa a seleção; "Novo contato" cadastra e abre a ficha na gaveta, com o aviso de contato já existente; busca e página trocam sem reabrir a gaveta. (Task 2)
- **Permissões:** o atendente continua só vendo o que já via em Histórico e Contatos; o filtro de atendente só aparece para quem vê todos os setores. (Tasks 1 e 2)

---

### Task 1: popover próprio e filtros do Histórico

**Files:**
- Create: `resources/views/components/popover.blade.php`, `resources/js/popover.js`, `tests/Feature/History/HistoryFiltersTest.php`
- Modify: `resources/js/app.js` (importar `./popover`), `app/Livewire/History/Index.php`, `resources/views/livewire/history/index.blade.php`, `tests/Unit/ViewConventionsTest.php` (se entrar `<button>` cru)
- Test: `tests/Feature/History/HistoryFiltersTest.php`; manter verdes `HistorySearchTest`, `HistoryPaginationTest`, `HistoryTimelineTest`

**Interfaces:**
- Produces (Blade): `<x-popover label="Filtros" class="…">` com o slot `trigger` (o botão, que leva `data-popover-trigger`, `x-on:click="toggle"`, `x-bind:aria-expanded="open"` e `aria-controls`) e o slot padrão (o painel, `role="dialog"`, `aria-label`, `x-show="open"`). Fecha com `close`/`escape`.
- Produces (Alpine): `Alpine.data('popover', () => ({ root, open, init(), toggle(), show(), close(), escape(event) }))`; `show()` abre e foca o primeiro campo quando visível; `close()` devolve o foco ao `[data-popover-trigger]`; `escape(event)` só age (e `stopPropagation`) com o painel aberto; clique fora fecha (`x-on:click.outside="close"`).
- Produces (PHP): em `App\Livewire\History\Index`: `public function clearFilters(): void` (de volta ao padrão e página 1) e `activeFilters(): int` exposto à view como `activeFilters`; o padrão de `De` sai de um método privado `defaultFrom(): string` (usado por `mount` e por estes dois). `wire:model` adiado nos campos; "Aplicar" é `wire:click="$refresh"`.
- Consumes: `flux:input`, `flux:select`, `flux:button` como estão.

- [ ] **Step 1: Testes (RED)** `HistoryFiltersTest` (Livewire `History\Index`, como admin): (a) a tela tem a busca e um botão "Filtros" sem contagem, e os campos De, Até, Setor, Motivo e Atendente estão dentro de `role="dialog"`; (b) `activeFilters` é 0 com os padrões, 1 com `to`, 2 com `to` + `sectorId`, 1 com `from` diferente do padrão, 3 com `sectorId` + `outcome` + `userId`; (c) com `contactId` o padrão de `from` é vazio e `from` preenchido conta 1; (d) `clearFilters` devolve `from` ao padrão de 30 dias atrás e zera `to`, `sectorId`, `outcome`, `userId` e a página; (e) o filtro de atendente não existe para atendente; (f) o botão mostra a contagem (`Filtros · 2`) quando há filtros.
- [ ] **Step 2:** rodar; esperado FAIL.
- [ ] **Step 3: Implementar** `popover.blade.php` e `popover.js` (padrão do `tag-picker.js`), o `defaultFrom`/`clearFilters`/`activeFilters` no `Index`, e a view do Histórico: busca fica; De, Até, Setor, Motivo e Atendente vão para dentro do `x-popover` com "Limpar" (`wire:click="clearFilters"`) e "Aplicar" (`wire:click="$refresh"` e fecha); campos com `wire:model` (adiado). Atualizar `wire:target` do esqueleto para incluir `clearFilters` e `$refresh`.
- [ ] **Step 4:** testes do Step 1 e os de Histórico verdes (`tests/Feature/History`, `tests/Feature/Livewire/TicketDetailsBlocksTest`, `ContactTagsEverywhereTest`); ajustar só o que afirmava a tela antiga. Commit `feat: filtros do Historico num popover proprio`.

### Task 2: ficha de Contatos em gaveta

**Files:**
- Modify: `app/Livewire/Contacts/Index.php`, `resources/views/livewire/contacts/index.blade.php`
- Test: `tests/Feature/Contacts/ContactsDrawerTest.php` (novo); atualizar `tests/Feature/Livewire/ContactsIndexTest.php`, `tests/Feature/Contacts/ChatButtonsTest.php` só no que afirmava a coluna

**Interfaces:**
- Produces: `Contacts\Index::$showCard` (`public bool $showCard = false`) ligado ao `flux:modal variant="flyout" wire:model.self="showCard"`; `select(int $id)` e `createContact` passam a abrir (`showCard = true`); `updatedShowCard(bool $open)` limpa `selectedId` ao fechar. A coluna `aside` some; a lista ocupa a largura (`max-w-4xl`).
- Consumes: `livewire:contacts.contact-card` (a ficha completa) dentro da gaveta, só renderizada com `selectedId`.

- [ ] **Step 1: Testes (RED)** `ContactsDrawerTest`: sem seleção não há ficha nem coluna "Selecione um contato…"; `select($id)` liga `showCard` e `selectedId` e a ficha aparece no HTML do modal; `set('showCard', false)` limpa `selectedId`; `createContact` abre a ficha do novo contato (e do existente, com o aviso); trocar `search` e página não reabre a gaveta; a lista tem o botão "Conversar" fora do botão que abre a ficha (um clique não abre a outra coisa: `data-testid` distintos).
- [ ] **Step 2:** FAIL. **Step 3:** implementar o `$showCard`/`updatedShowCard`, trocar o `aside` pelo `flux:modal` flyout (`position="right"`, título "Contato", largura `md`), e a grade da seção para uma coluna.
- [ ] **Step 4:** testes verdes e os de Contatos/Chat/etiquetas ajustados. Commit `feat: ficha de Contatos em gaveta lateral`.

### Task 3: verificação, revisão e PR

- [ ] **Step 1:** servidor local e dados (receita da fatia B/A), `npm run build`, script `ux-audit.js` em `historico`, `contatos`, `inbox`, `relatorios` (sem estouro, sem controle sem nome, sem alvo < 24 px); roteiro de teclado do popover no Chrome (Esc devolve o foco, clique fora fecha, foco no 1º campo, data e seletor nativos dentro); gaveta de Contatos em 360 e 1280 (✕, Esc, clique fora).
- [ ] **Step 2:** portões 6 e 7 (revisão independente; Pint, Larastan, PHPMD, `node --test`, `npm run build`, suíte com Redis), ledger, PR, CI verde e merge.

## Fora desta fatia

Confirmações e Desfazer (D), editor do bot (E), gráfico (F), Ctrl+K (G), faixas dispensáveis (H), sino (no fim).
