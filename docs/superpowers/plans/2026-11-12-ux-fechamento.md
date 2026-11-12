# UX, fechamento da v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar a rolagem horizontal de Contatos em 1280 px e os ~950 px de branco rolável da Fila, e deixar o script de auditoria capaz de provar isso por resolução.

**Architecture:** Duas correções de classe Tailwind na origem da causa (grade com `minmax(0,1fr)`; lista rolável `relative`), fixadas por testes de renderização que olham a classe; a prova de layout é o `ux-audit.js` rodado no navegador antes e depois.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2, Tailwind, PHPUnit; Chrome DevTools MCP para a auditoria.

**Spec:** `docs/superpowers/specs/2026-11-12-ux-fechamento-design.md`

## Global Constraints

- Só o beta em escopo; nunca tocar `main` nem a produção do Coolify.
- Proibido `overflow-x-hidden` no `<main>`/corpo para esconder o corte (anti-objetivo do spec).
- Sem redesenho, sem componente novo, sem mexer no editor de automação nem no CSP.
- Servidor local de verificação: `dimetal_local` (porta 8123), nunca `dimetal_test`.
- Commits em português com prefixo (`fix:`, `test:`, `docs:`); PR só se o dono pedir.

## Review Focus

- Contatos com nome de 80+ caracteres sem espaço (ex.: razão social colada): a coluna da lista não pode empurrar o painel (teste de classe + auditoria com dado real).
- Fila com 20+ tickets e etiquetas: nenhum filho `absolute` pode estender o documento (auditoria mede `scrollHeight`).
- Contatos com o painel de detalhe aberto (contato selecionado), não só vazio, em 1280 px.
- Tema claro e escuro dão o mesmo resultado nas duas telas.
- Selo de SLA (`absolute -end-0.5 -bottom-0.5`, `inbox.blade.php:106`) continua posicionado sobre o avatar depois do `relative` na lista (o avatar já é `relative`; conferir visualmente).

---

### Task 1: Contatos, coluna da lista não estoura

**Files:**
- Modify: `resources/views/livewire/contacts/index.blade.php:1`
- Test: `tests/Feature/Livewire/ContactsIndexTest.php`

**Interfaces:**
- Consumes: `ContactsIndexTest::page(): Testable` (já existe).
- Produces: nada.

- [ ] **Step 1: RED.** Em `ContactsIndexTest` adicionar `test_grade_da_lista_usa_minmax_para_nome_longo_nao_empurrar_o_painel`: cria um `Contact::factory()` com `name` de 90 caracteres (`str_repeat('Distribuidora', 7)`), `$this->page()->assertSeeHtml('lg:grid-cols-[minmax(0,1fr)_22rem]')`.
- [ ] **Step 2:** Rodar `php artisan test --filter=test_grade_da_lista_usa_minmax` com Postgres de teste no ar; esperado: FAIL (classe ausente).
- [ ] **Step 3:** Em `contacts/index.blade.php:1` trocar `lg:grid-cols-[1fr_22rem]` por `lg:grid-cols-[minmax(0,1fr)_22rem]`.
- [ ] **Step 4:** Mesmo comando; esperado: PASS. Depois `php artisan test --filter=ContactsIndexTest`; esperado: tudo verde.
- [ ] **Step 5:** `git add resources/views/livewire/contacts/index.blade.php tests/Feature/Livewire/ContactsIndexTest.php && git commit -m "fix: lista de Contatos não empurra o painel de detalhe em 1280 px"`

### Task 2: Fila, lista rolável não deixa `sr-only` esticar o documento

**Files:**
- Modify: `resources/views/livewire/inbox/inbox.blade.php:57`
- Test: `tests/Feature/Livewire/InboxQueueCardTest.php`

**Interfaces:**
- Consumes: o helper de montagem do Inbox já usado em `InboxQueueCardTest` (reutilizar o mesmo `setUp`/helper; não criar outro).
- Produces: nada.

- [ ] **Step 1: RED.** Em `InboxQueueCardTest` adicionar `test_lista_da_fila_e_relative_para_o_sr_only_nao_escapar_do_corte`: com um ticket aguardando, `assertSeeHtml('relative min-h-0 flex-1 overflow-y-auto')`.
- [ ] **Step 2:** Rodar `php artisan test --filter=test_lista_da_fila_e_relative`; esperado: FAIL.
- [ ] **Step 3:** Em `inbox.blade.php:57` trocar `class="min-h-0 flex-1 overflow-y-auto"` por `class="relative min-h-0 flex-1 overflow-y-auto"`. Conferir se o `overflow-y-auto` da conversa (linha 303, `flex-col-reverse`) tem o mesmo problema (`sr-only` nas bolhas); se tiver, aplicar `relative` igual e cobrir no mesmo teste.
- [ ] **Step 4:** Rodar `php artisan test --filter=InboxQueueCardTest` e os afetados: `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`; esperado: verde.
- [ ] **Step 5:** `git add resources/views/livewire/inbox/inbox.blade.php tests/Feature/Livewire/InboxQueueCardTest.php && git commit -m "fix: lista da Fila é relative, o documento não rola em branco"`

### Task 3: Script de auditoria por resolução, prova antes/depois

**Files:**
- Modify: `docs/superpowers/audit/ux-audit.js`
- Create: `docs/superpowers/audit/2026-11-12-ux-fechamento.md`

**Interfaces:**
- Consumes: servidor local em `127.0.0.1:8123` logado como `dev@teste.local`, banco populado (20 tickets, nomes longos).
- Produces: `ux-audit.js` com `sizes = [[360,800],[1280,720],[1920,1080],[2560,1440]]` (largura × altura do iframe) e o campo `docH` + marcador `rolaV` quando `scrollHeight > altura + 1` apenas nas rotas de altura fixa (`inbox`, `historico`, `auditoria`).

- [ ] **Step 1:** Em `ux-audit.js` trocar `widths` por `sizes` (pares), usar a altura no `iframe`, acrescentar `docH` e `rolaV` ao `probe` e incluir `rolaV` na condição de `found.push`. Atualizar o comentário do topo (rotas e dado realista exigido).
- [ ] **Step 2:** Rodar o script (corpo da função em `evaluate_script`, `waitForStableDom:false`, `filePath` no scratchpad) **antes** das Tasks 1 e 2 já commitadas não é possível; usar o resultado de 12/11 já medido (Contatos 1280 `ox=125`; Fila `docH=1682`) como "antes", registrado no relatório.
- [ ] **Step 3:** Rodar o script depois das Tasks 1 e 2; esperado: lista vazia (nenhum `ox`, nenhum `rolaV` em inbox/historico/auditoria) nos dois temas.
- [ ] **Step 4:** Captura de tela em claro de Histórico, Auditoria, Canais, Horário e Empresa em 1280×720 (limite declarado no spec); registrar no relatório o que viu e abrir defeito só se for real.
- [ ] **Step 5:** Escrever `2026-11-12-ux-fechamento.md` com antes/depois e o limite; `git add docs/superpowers/audit && git commit -m "docs: auditoria de UX por resolução, antes e depois do fechamento"`

---

## Self-review

- Spec: itens 1 e 2 → Tasks 1 e 2; item 3 descartado no spec; itens 4 e 5 sem ação; script e prova de pronto → Task 3; limite das telas não vistas → Task 3 Step 4.
- Nomes: `ux-audit.js` `sizes`/`docH`/`rolaV` só na Task 3.
- Proporção: plano curto, sem código de corpo; cada passo tem comando e resultado esperado.
