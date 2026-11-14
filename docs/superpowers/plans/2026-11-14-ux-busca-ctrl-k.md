# Busca de ações (Ctrl+K) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fatia G: Ctrl+K (⌘K) e um botão na barra lateral abrem uma busca em modal: telas, contatos e protocolos de ticket. Enter abre, setas navegam, Esc fecha.

**Architecture:** Componente Livewire `App\Livewire\Search\Palette` (no layout `app/sidebar`) com `$q` (`wire:model.live.debounce`) e três grupos de resultado calculados no servidor: telas (`Navigation`, só as que o usuário pode abrir), contatos (`Contact::search`, até 5) e tickets por protocolo (`Ticket::readableBy`, até 5). O modal é um `<dialog>` próprio (como o `x-confirm-dialog`); `Alpine.data('commandPalette')` (`resources/js/command-palette.js`) abre/fecha, ouve Ctrl/⌘+K e conduz as setas (função pura `stepActive` testada). Cada resultado é um `<a>` (`wire:navigate`); Enter clica no ativo. Abrir contato e ticket por URL: `/contatos?contato=ID` (novo) e `/inbox?ticket=ID` (já existe).

**Tech Stack:** Laravel 13, Livewire 4.4, Alpine CSP, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-14-ux-simplificacao-design.md` (fatia G, item 14).

## Global Constraints

- Respeita permissões e setores: tela só se o papel abre (`Navigation::primary` + Configuração); ticket só se `readableBy`; protocolo de ticket que o usuário não vê não aparece nem some em branco diferente de "Nada encontrado".
- Alpine CSP: métodos em `Alpine.data`; texto em português; alvos ≥ 24 px; texto ≥ 12 px.
- Lista vazia com busca: "Nada encontrado".

## Review Focus

- **Permissão:** atendente sem o setor não acha o protocolo; `@` do `like` escapado (`%`, `_`); usuário inativo não vê nada.
- **Teclado:** Ctrl+K e ⌘K abrem (inclusive com o foco num campo de texto) e não roubam o atalho se o diálogo de confirmação está aberto; Esc fecha e devolve o foco; setas não saem da lista; Enter sem resultado não faz nada.
- **Ciclo de vida:** `wire:navigate` fecha o modal ao navegar; reabrir limpa a busca; busca rápida não deixa resposta velha sobrescrever a nova (debounce + estado no servidor).

### Task 1: servidor (busca por permissão)

**Files:**
- Create: `app/Livewire/Search/Palette.php`, `resources/views/livewire/search/palette.blade.php`, `tests/Feature/Search/PaletteTest.php`
- Modify: `app/Livewire/Contacts/Index.php` (`#[Url(as: 'contato')]` abre a ficha no `mount`), `tests/Feature/Livewire/ContactsDrawerTest.php`

**Interfaces:**
- Produces: `Palette::results(): array{screens: list<array{label,url}>, contacts: list<array{label,detail,url}>, tickets: list<array{label,detail,url}>}`; `$q` público; com `$q` vazio só `screens` (todas as permitidas). URLs: `route(item.route)`, `route('contatos', ['contato' => id])`, `route('inbox', ['ticket' => id])`.

- [ ] **Step 1: Testes (RED).** Admin vê todas as telas e atendente só as dele (`Relatórios` some); `q='rel'` filtra telas por rótulo sem acento/caixa; contato por nome e por telefone (dígitos); `%` digitado não vira curinga; ticket por protocolo (parcial) só se `readableBy` (atendente de outro setor não acha); inativo não acha nada; sem resultado mostra "Nada encontrado"; máximo 5 por grupo; `?contato=ID` abre a ficha em Contatos (e inexistente não quebra).
- [ ] **Step 2:** FAIL. **Step 3:** implementar. **Step 4:** verdes. Commit `feat: busca de telas, contatos e protocolos respeitando permissoes`.

### Task 2: modal, atalho e teclado

**Files:**
- Create: `resources/js/command-palette.js`, `tests/js/command-palette.test.mjs`
- Modify: `resources/js/app.js`, `resources/views/layouts/app/sidebar.blade.php` (botão "Buscar" + `<livewire:search.palette />`), `resources/views/livewire/search/palette.blade.php` (o `<dialog>`)

**Interfaces:**
- Produces (JS puro): `stepActive(current: number, count: number, key: 'ArrowDown'|'ArrowUp'): number` (circular; `count = 0` → `-1`; `current = -1` + ↓ → 0, + ↑ → último); `isShortcut(event): boolean` (`(ctrlKey || metaKey) && key.toLowerCase() === 'k'`, sem `altKey`/`shiftKey`).

- [ ] **Step 1: Testes (RED)** de `stepActive` e `isShortcut`. **Step 2:** FAIL. **Step 3:** implementar o JS e o Blade. **Step 4:** verdes; no Chrome: Ctrl+K abre com foco no campo, ↓/↑ marcam, Enter abre, Esc fecha, 1280×720 e 360. Commit `feat: modal Ctrl+K com setas, Enter e Esc`.

### Task 3: verificação, revisão e PR (portões 6 e 7, ledger, PR, merge com CI verde)
