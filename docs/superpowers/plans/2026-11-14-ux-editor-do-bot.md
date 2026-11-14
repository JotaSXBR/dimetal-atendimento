# Editor do bot: variáveis e zoom legível Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fatia E da spec: botão "Inserir variável" nos campos de texto do bot e zoom que nunca abre ilegível.

**Architecture:** As variáveis embutidas ficam numa constante do PHP (`Vars::BUILTIN`, mesma fonte do `BotRun::vars()`) e chegam ao editor no `data-config`; as variáveis do cliente (campo "Guardar a resposta na variável" dos blocos Pergunta) saem do próprio grafo, em JS puro testado. O painel de propriedades ganha um `<select>` "Inserir variável" nos campos `textarea`, que usa o `insertAt` que já existe (`resources/js/composer-insert.js`). O zoom ganha um piso (`MIN_FIT_ZOOM`) aplicado em `fit()`.

**Tech Stack:** Laravel 13, Livewire 4.4, Alpine CSP, Rete (flow-editor), `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-14-ux-simplificacao-design.md` (fatia E, itens 11 e 12).

## Global Constraints

- Alpine CSP: métodos em `Alpine.data`, nada de expressão com `JSON.parse` no atributo.
- Texto de interface em português; nenhum texto abaixo de 12px (guarda do `ViewConventionsTest`).
- Digitar `{{variavel}}` à mão continua valendo; o botão só facilita.
- Só supervisor/admin chegam ao editor (já garantido pelo `Editor`).

## Review Focus

- **Inserção:** no cursor (não no fim) e substituindo a seleção; o campo reage (`x-model`/`apply()` chamados) e o rascunho fica "alterado"; sem foco prévio insere no fim.
- **Lista:** traz as 6 embutidas do `BotRun::vars()` e as variáveis das perguntas do grafo, sem repetir nem listar vazias.
- **Zoom:** o piso vale ao abrir e no "Centralizar"; fluxo pequeno continua enquadrado (zoom até 1, sem piso atrapalhar); fluxo grande abre no Início, legível, e rola.
- **Campos `lines` (opções do menu):** fora desta fatia (não têm variável).

---

### Task 1: Inserir variável

**Files:**
- Modify: `app/Services/Bot/Vars.php` (constante), `app/Livewire/Automation/Editor.php` (config `variables`), `resources/js/flow-editor.js` (método), `resources/views/livewire/automation/editor.blade.php` (select no `textarea`)
- Create: `resources/js/flow-editor/variables.js`, `tests/js/flow-variables.test.mjs`
- Test: `tests/Feature/Livewire/AutomationEditorTest.php`, `tests/Unit/Bot/VarsTest.php` (se não existir, criar)

**Interfaces:**
- Produces (PHP): `Vars::BUILTIN: array<string, string>` chave → descrição (`nome`, `empresa`, `protocolo`, `setor`, `horario`, `proximo_horario`); `config.variables: list<array{key: string, label: string}>` com as embutidas.
- Produces (JS): `variableChoices(builtin, nodes): list<{key, label}>` = embutidas + `data.variavel` dos blocos `pergunta` (texto aparado, não vazio, sem repetir chave nem embutida, ordem do grafo, rótulo `Resposta: <chave>`); `token(key): string` = `'{{' + key + '}}'`.
- Consumes: `insertAt(value, start, end, text)` de `composer-insert.js`.

- [ ] **Step 1: Testes (RED).** JS: `variableChoices` junta, tira repetidas/vazias/embutidas, mantém a ordem; `token('nome')` → `{{nome}}`. PHP: `Vars::BUILTIN` tem exatamente as chaves que `BotRun::vars()` devolve sem sessão (`nome, empresa, protocolo, setor, horario, proximo_horario`); o editor entrega `config.variables` com essas chaves e descrições não vazias.
- [ ] **Step 2:** rodar; FAIL.
- [ ] **Step 3: Implementar.** `Vars::BUILTIN`; `Editor::render` passa `'variables' => collect(Vars::BUILTIN)->map(...)`; `variables.js`; em `flow-editor.js`, `variables: []` (preenchido do config) e `variableOptions()` (usa `api.getGraph().nodes`) e `insertVariable(event)`: lê o `<select>`, acha o `textarea` irmão (`closest('label')`), usa `insertAt(selectionStart, selectionEnd)`, grava em `selected.data[key]`, chama `apply()`, devolve o foco e o cursor ao campo e zera o select. No Blade, abaixo de cada `textarea` do tipo `textarea`, um `<select aria-label="Inserir variável">` com opção inicial "Inserir variável…" e `x-for` nas opções.
- [ ] **Step 4:** verdes (`node --test tests/js`, testes PHP afetados). Commit `feat: inserir variavel nos campos de texto do editor do bot`.

### Task 2: Zoom legível ao abrir e no Centralizar

**Files:**
- Modify: `resources/js/flow-editor/editor.js` (`draw` e `fit`), `resources/js/flow-editor/appearance.js` (função pura)
- Test: `tests/js/flow-appearance.test.mjs`

**Interfaces:**
- Produces (JS puro): `MIN_FIT_ZOOM = 0.9` (o texto dos blocos é de 12 px: com 0,9 fica em 10,8 px, o menor que ainda se lê em 1280×720; abaixo disso vira mancha) e `fitScale(zoomedTo: number, min = MIN_FIT_ZOOM): number` = `Math.max(zoomedTo, min)`.
- Comportamento: depois do `zoomAt` (que enquadra tudo), se o zoom ficou abaixo do piso, sobe ao piso e ancora o canto superior esquerdo do bloco Início a 24 px do canto do canvas (o resto se alcança arrastando); fluxo que cabe acima do piso fica como o `zoomAt` deixou.

- [ ] **Step 1: Testes (RED).** `fitScale(0.4)` → 0.9; `fitScale(1.3)` → 1.3; `fitScale(0.9)` → 0.9; piso customizado.
- [ ] **Step 2:** FAIL. **Step 3:** implementar `fitScale`/`MIN_FIT_ZOOM` e usar em `fit()` e no `draw({fit:true})` (função interna `fitView()` compartilhada). **Step 4:** verdes. Commit `feat: zoom minimo legivel ao abrir e no Centralizar do editor`.

### Task 3: verificação, revisão e PR

- [ ] **Step 1:** `npm run build`; no Chrome (1280×720) abrir um workflow com muitos blocos: o texto dos blocos se lê ao abrir e no Centralizar; selecionar um bloco Mensagem, pôr o cursor no meio do texto, escolher "Nome do contato" e conferir a inserção no cursor, o rascunho marcado como alterado e o foco de volta; bloco Pergunta com variável `cpf` e outro bloco listando "cpf".
- [ ] **Step 2:** portões 6 e 7 (revisor subagente Sonnet médio; Pint, Larastan, PHPMD, `node --test tests/js`, build, suíte com Redis), ledger, PR, CI verde e merge.

## Fora desta fatia

Gráfico (F), Ctrl+K (G), faixas dispensáveis (H), sino (no fim).
