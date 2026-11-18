# Redesenho, fatia 7: Automação (lista e editor do bot) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lista da Automação na largura de gestão com os workflows num painel, e o editor do bot com o cabeçalho do desenho,
blocos monocromáticos (o tipo só no ícone) e painéis laterais no visual da base. O canvas segue como está.

**Architecture:** Markup de `resources/views/livewire/automation/index.blade.php` e `editor.blade.php`, e o CSS do editor em
`resources/css/app.css` (`.flow-*`). Nenhuma propriedade Livewire, regra, rota nem lógica de `resources/js/flow-editor/` nova.

**Tech Stack:** Laravel 13, Livewire 4.4 (build CSP), Flux 2.21 free, Tailwind 4, Rete (editor), PHPUnit, node --test.

**Spec:** `docs/superpowers/specs/2026-11-17-redesenho-ui-pencil-design.md` (fatia 7: "cabeçalho, cores dos blocos (monocromáticas
com sinal por tipo), painéis laterais"; não-meta: canvas do editor).

## Global Constraints

- Só visual: nada some (criar workflow com modelo, avisos `$error`/`$notice`, Editar, Versões com a lista, duplicar, apagar,
  ativar por canal; no editor, Salvar rascunho, Validar, Testar, Publicar, Desfazer/Refazer, zoom, Centralizar, Organizar blocos,
  menu do canvas, painel de teste, painel de propriedades, avisos de erro e de validação).
- Build CSP: nenhuma expressão Alpine nova em atributo; manter todos os `x-*`, `x-ref`, `wire:*`, `wire:ignore`, `wire:key`,
  `aria-*` e `role` existentes. Nada muda em `resources/js/flow-editor/`.
- Tokens de `resources/css/app.css`; piso de 12 px; alvos ≥ 24 px. As cores de categoria (`--flow-cat-*`) ficam, só que usadas
  no ícone do bloco.
- Testes com `DB_DATABASE=fluxie_ui_test`; `node --test tests/js` continua verde.

## Decisões (não reabrir)

- Ruling: o editor continua de borda a borda (ferramenta de tela cheia): painéis laterais separados por borda, sem cartões com
  vão em volta como no Pencil (o canvas perderia ~50 px). Custo se errado: trocar bordas por `rounded-xl` com `gap`.
- Ruling: "sinal por tipo" = a cor da categoria só no ícone do cabeçalho do bloco; o cabeçalho fica neutro (`canvas`) e o título
  `ink`. Os estados (selecionado, com erro, ativo no teste) seguem como hoje.
- Saem do desenho: "Histórico" e "Testar fluxo" como botões da barra (o app tem Testar no cabeçalho e Versões na lista),
  "Versão 7 publicada - editando rascunho v8", saídas "Erro/Timeout" no painel (o app mostra as saídas no próprio bloco).

## Medidas do Pencil (`w97Giu` editor, `sfMRi` lista; PNGs em
`C:/Users/bkpdi/AppData/Local/Temp/claude/abad7b6d92ced975/0cc90b53-26aa-4a61-b219-6ddb491b1462/scratchpad/pen-f7/final/`)

| Parte | Medidas |
|---|---|
| Lista | `x-page-header` de hoje (ação "Novo workflow" à direita); conteúdo `max-w-[120rem]`; formulário de criar num `x-panel` "Novo workflow"; workflows num `x-panel` "Workflows" (subtítulo com a contagem), uma linha por workflow `px-4 py-3` com `border-b border-line`: nome 13 px peso 600 + selo de status, linha 12 px `ink-3`, os canais como chips, ações à direita; versões abertas dentro da linha |
| Cabeçalho do editor | faixa `border-b border-line bg-surface px-6 py-4`: link "← Automação" 12 px `ink-3` acima do título; título 20 px peso 500 `ink`; ações à direita (Salvar rascunho, Validar, Testar como `outline` sm; Publicar `primary` sm) |
| Paleta (esquerda) | `w-44`, `border-e border-line bg-surface p-3`; "Adicionar bloco" 12 px peso 600 `ink-3`; grupos 12 px `ink-3`; botões `rounded-lg border-line` 12 px, altura ≥ 28 px |
| Bloco | cartão `surface`, borda `line`, raio 10 px (como hoje); cabeçalho neutro `background: var(--color-canvas)`, título `ink` peso 600; ícone 14 px na cor `var(--flow-cat)` |
| Painéis da direita | `w-72`, `border-s border-line bg-surface p-4`; títulos de seção 12 px peso 600 `ink-3` em caixa alta com espaçamento (como "NÓ SELECIONADO"); campos `rounded-lg border-line` |

## Review Focus

- Bloco selecionado, com erro e ativo no teste continuam distinguíveis no claro e no escuro com o cabeçalho neutro (Task 2).
- O ícone de cada categoria continua com contraste ≥ 3:1 sobre `canvas` nos dois temas (Task 2; conferência).
- Lista com versões abertas e com o formulário de criar aberto, sem perder `wire:key` (Task 1).
- 1280 px: cabeçalho do editor com as quatro ações sem quebrar o título (Task 2; conferência).
- 390 px: lista sem rolagem lateral (conferência; o editor não é usado no celular).

---

### Task 1: Lista da Automação

**Files:**
- Modify: `resources/views/livewire/automation/index.blade.php`
- Test: o teste que já renderiza `App\Livewire\Automation\Index` (conferir o nome em `tests/Feature`)

- [ ] **Step 1: Testes que falham** — `test_automacao_no_painel_do_desenho`: contêiner `max-w-[120rem]`; workflows num `data-panel`
  com `wire:key="workflow-{id}"`; com o formulário aberto, ele está num `data-panel` "Novo workflow"; sem workflows, o
  `x-empty-state` dentro do painel.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** com as medidas.
- [ ] **Step 4: Rodar e ver passar** — `--filter="Automation|Workflow"`.
- [ ] **Step 5: Commit** — `feat: lista da automação no painel do desenho`

### Task 2: Editor: cabeçalho, blocos monocromáticos e painéis

**Files:**
- Modify: `resources/views/livewire/automation/editor.blade.php`, `resources/css/app.css` (bloco `.flow-*`)
- Test: o teste que já renderiza o editor (conferir em `tests/Feature`) e, se houver, o teste de CSS/tokens do editor

- [ ] **Step 1: Testes que falham**
  - `test_editor_com_cabecalho_do_desenho`: `data-editor-header` com o link de volta para `automacao`, o título `h1` com o nome
    do workflow e as quatro ações.
  - Teste de CSS (no arquivo que já lê `app.css`, ou um novo `tests/Feature/FlowEditorCssTest.php`): a regra `.flow-head` usa
    `var(--color-canvas)` como fundo e não `color-mix` com `--flow-cat`; existe regra que pinta o ícone (`.flow-head svg` ou a
    classe do ícone que `editor.js` já gera) com `var(--flow-cat)`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** com as medidas; conferir no navegador (servidor local, `/automacao/1`) claro e escuro em 1280 e
  1920 com o medidor: cabeçalho numa linha, blocos legíveis, selecionado visível.
- [ ] **Step 4: Rodar e ver passar** — `--filter="Automation|Workflow|Flow|ThemeTokens"` e `node --test tests/js`.
- [ ] **Step 5: Commit** — `feat: editor do bot com cabeçalho e blocos do desenho`

### Task 3: Verificação da fatia (controlador)

- [ ] Portão 7 do `CLAUDE.md`; suíte com Redis (`redis-ui`, removido no fim).
- [ ] Conferência visual: lista (720p, 1080p, 2K, 390) e editor (720p, 1080p, 2K), claro e escuro: bloco selecionado, painel de
  teste aberto, menu do canvas.
