# Automação, fatia 3: editor visual

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O editor de workflows deixa de parecer protótipo: blocos com ícone, cor e prévia do conteúdo, linhas e bolinhas redesenhadas, fundo pontilhado, minimapa e zoom, "Organizar blocos", menu de contexto, atalhos (Delete, Ctrl+Z/Y, Ctrl+D) e o erro de validação marcado no próprio bloco.

**Architecture:** Continua Rete.js 2 com o plugin Lit. O visual vem de elementos próprios (`flow-node`, `flow-connection`) plugados em `customize.node/connection`; toda a lógica que dá para testar sem navegador (categorias, prévias, erro por bloco, organizar, histórico) mora em um módulo puro, `appearance.js`, coberto por `node --test`. Sem `elkjs` e sem plugin de histórico: organizar e desfazer são poucas linhas próprias. Única dependência nova: `rete-minimap-plugin` (MIT, 74 KB).

**Tech Stack:** Rete.js 2, `@retejs/lit-plugin`, Lit 3, Alpine, Tailwind 4, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-11-automacao-leva-b-design.md` (seção "Fatia 3")

## Global Constraints

- `elkjs` não entra (8 MB e licença dupla EPL/GPL); "Organizar" é uma implementação própria por camadas, com `shortcut:` no código dizendo que não minimiza cruzamentos.
- O formato do grafo (`serialize.js`) não muda; cor e ícone vêm do tipo do bloco, nunca do grafo.
- Tema escuro/amarelo atual, com os tokens do projeto (`--color-*` do Tailwind em `resources/css/app.css`); nada de cor fixa nova fora desses tokens, exceto as cores de categoria, definidas como variáveis CSS do editor.
- Botão de ação é `<flux:button>`, não `<button>` cru (a suíte confere em `ViewConventionsTest`); gatilhos internos do canvas (menu de contexto) usam o mesmo componente.
- O editor segue funcionando sem o minimapa (se o plugin falhar ao carregar, o resto abre).
- Commits em português com prefixo, um por tarefa, terminados com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Sem referência a bibliotecas de terceiros em código e commit.

## Review Focus

- Desfazer/refazer não pode perder as mensagens do bot nem a seleção a ponto de apagar trabalho (o histórico guarda só blocos e ligações; as mensagens ficam fora). Teste na Tarefa 1 e verificação na 4.
- "Organizar" com ciclo (menu que volta a si mesmo), bloco solto e ligações para bloco inexistente não trava nem devolve posição `NaN`. Teste na Tarefa 1.
- Erro "ids: mensagem" de bloco que não existe (apagado depois de validar) não quebra a marcação. Teste na Tarefa 1.
- Texto de prévia vazio ou enorme, setor/etiqueta que sumiu: a prévia mostra algo legível e nunca estoura o bloco. Teste na Tarefa 1.
- Duplicar bloco gera id novo e não copia as ligações; duplicar o Início é recusado. Teste na Tarefa 1 (função pura) e verificação na 4.
- A tela abre e valida no celular/janela estreita sem o minimapa cobrir o painel (verificação na Tarefa 3).

---

### Task 1: `appearance.js`, a lógica pura

**Files:**
- Create: `resources/js/flow-editor/appearance.js`
- Modify: `resources/js/flow-editor/nodes.js` (cada bloco ganha `category` e `icon`)
- Test: `tests/js/flow-appearance.test.mjs`

**Interfaces:**
- Consumes: `BLOCKS` de `nodes.js`.
- Produces (todas exportadas por `appearance.js`):
  - `CATEGORIES`: `{ inicio, fala, espera, logica, acao, fim }`, cada uma com `{ label }`; `categoryOf(type): string` (padrão `acao`).
  - `previewOf(type, data, lookups): string[]`: até 3 linhas curtas (cada uma com no máximo 60 caracteres, terminando em `…` quando cortada). `lookups = { sectors: [{id, name}], tags: [{id, name}] }`. Regras: `mensagem`/`pergunta`/`midia` → o texto (ou "(sem texto)"); `menu_setores` → "Lista os setores do menu"; `menu` → as opções ("1 - A", "2 - B", "+N"); `condicao` → "Dentro do horário?" ou "<var> <op> <valor>"; `esperar` → "Digitando por N s"; `salvar_contato` → "<campo> ← <variavel>"; `etiquetar` → nome da etiqueta (ou "Etiqueta removida" se não existe); `transferir` → nome do setor, "Setor do menu" sem `setor_id`, ou "Setor removido" se não existe; `inicio`/`encerrar` → linha fixa ("Começa aqui" / "Encerra o ticket").
  - `errorNodeIds(errors, nodeIds): Set<string>`: ids dos blocos citados por erros no formato `"<id>: mensagem"` que existem em `nodeIds`.
  - `arrange(nodes, edges): Record<string, {x, y}>`: layout em camadas da esquerda para a direita (largura de camada 300, altura de linha 150, origem em 0,0): a camada de um bloco é o caminho mais longo a partir do Início seguindo as ligações (ciclos ignorados com visita única); blocos sem caminho vão para a última camada + 1; dentro da camada, na ordem em que aparecem como destino; `shortcut:` no código sobre cruzamentos.
  - `duplicate(node, id): object | null`: cópia `{ id, type, x: x + 40, y: y + 40, data: structuredClone(data) }` ou `null` para `inicio`.
  - `class History` com `constructor(limit = 50)`, `push(snapshot: string)` (ignora igual ao último e zera o refazer), `undo(current: string): string | null`, `redo(current: string): string | null`, `canUndo(): boolean`, `canRedo(): boolean`.

- [ ] **Step 1: Write the failing tests** em `flow-appearance.test.mjs` (`node:test`): `categoryOf` de todos os tipos de `BLOCKS` devolve uma categoria existente; `previewOf` para cada regra acima, incluindo texto de 200 caracteres (corta em 60 com `…`), texto vazio ("(sem texto)"), setor e etiqueta inexistentes; `errorNodeIds(['menu: sem ligação', 'fantasma: x', 'Nenhum caminho termina em Transferir.'], ['menu','a'])` devolve só `menu`; `arrange` num fluxo linear de 3 blocos (x crescente), num ramo com dois destinos (mesma camada, `y` diferentes), num ciclo `m → m` (sem laço infinito, números finitos) e com bloco solto (última camada + 1); `duplicate` de bloco comum (id novo, `+40`, dados clonados, não compartilha referência) e de `inicio` (`null`); `History`: undo/redo ida e volta, `push` do mesmo texto ignorado, limite de 50, `push` depois de `undo` zera o refazer.
- [ ] **Step 2: Run** `node --test tests/js`. Expected: FAIL (módulo inexistente).
- [ ] **Step 3: Implement** `appearance.js` e as chaves `category` e `icon` em cada bloco de `nodes.js` (`icon` é o nome do ícone de um conjunto pequeno de SVGs inline definidos em `appearance.js`: `play`, `chat`, `list`, `question`, `branch`, `clock`, `image`, `user`, `tag`, `forward`, `stop`).
- [ ] **Step 4: Run** `node --test tests/js`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: lógica pura do editor visual (categorias, prévias, erros por bloco, organizar, histórico)`.

### Task 2: bloco, bolinha e linha redesenhados

**Files:**
- Create: `resources/js/flow-editor/flow-node.js` (elementos `flow-node` e `flow-connection`)
- Modify: `resources/js/flow-editor/editor.js` (`customize.node/connection`, campos `preview` e `invalid` no `FlowNode`, recalcular a prévia em `updateData`, `setErrors(ids)`), `resources/css/app.css` (variáveis e estilos do editor), `resources/views/livewire/automation/editor.blade.php` (fundo pontilhado e paleta agrupada por categoria)
- Test: `tests/js/flow-appearance.test.mjs` (prévia já coberta); verificação no navegador

**Interfaces:**
- Consumes: `categoryOf`, `previewOf`, ícones (Tarefa 1); `BLOCKS`.
- Produces: `FlowNode` com `preview: string[]`, `invalid: boolean`; `createEditor` devolve também `setErrors(ids: Set<string>)`. O bloco desenha: cabeçalho (ícone + rótulo, cor da categoria), corpo com a prévia, uma linha por porta de saída com rótulo, bolinha de entrada à esquerda e de saída à direita (`<rete-ref>` como no bloco clássico), borda amarela quando selecionado e vermelha quando `invalid`.

- [ ] **Step 1: Implement** `flow-node.js` (`LitElement` com `createRenderRoot()` devolvendo `this`, para o CSS global do editor valer) e `flow-connection.js` embutido no mesmo arquivo: caminho SVG com curvatura suave, seta no fim, realce ao passar o mouse. Registrar com `customElements.define` e plugar em `LitPresets.classic.setup({ customize: { node, connection } })`.
- [ ] **Step 2: CSS** em `app.css` (bloco `.flow-*`): variáveis `--flow-cat-inicio`, `--flow-cat-fala`, `--flow-cat-espera`, `--flow-cat-logica`, `--flow-cat-acao`, `--flow-cat-fim` definidas por tema claro/escuro; fundo pontilhado do canvas (`radial-gradient`) com `background-size` que o JS atualiza no zoom; estados selecionado/erro/hover.
- [ ] **Step 3: Build and look.** `npm run build`; abrir o editor local com um workflow do modelo "Atendimento com horário" e conferir no navegador: ícones e cores por categoria, prévia dentro dos blocos, linhas curvas sem laço, seleção e erro. Capturar tela e anotar no ledger.
- [ ] **Step 4: Run** `node --test tests/js` e `php artisan test --filter=AutomationEditorTest`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: blocos, bolinhas e linhas do editor com visual próprio`.

### Task 3: navegação (minimapa, zoom, centralizar, organizar)

**Files:**
- Modify: `package.json` e `package-lock.json` (`rete-minimap-plugin`), `resources/js/flow-editor/editor.js` (plugin do minimapa, `zoomBy`, `fit`, `organize`), `resources/js/flow-editor.js` (métodos Alpine), `resources/views/livewire/automation/editor.blade.php` (barra de controles sobre o canvas)
- Test: verificação no navegador; `arrange` já coberto na Tarefa 1

**Interfaces:**
- Consumes: `arrange(nodes, edges)` (Tarefa 1).
- Produces: `createEditor` devolve `zoomBy(factor)`, `fit()`, `organize()` (aplica `arrange` às posições, anima ou só reposiciona e centraliza, e marca o rascunho como alterado). Controles: "−", "+", "Centralizar" e "Organizar blocos" como `flux:button` pequenos no canto do canvas.

- [ ] **Step 1: Install** `npm install rete-minimap-plugin@2.0.3` (MIT); anotar o tamanho no ledger (`npm run build` antes e depois).
- [ ] **Step 2: Implement** o plugin do minimapa (`MinimapPlugin` no `area` e `LitPresets.minimap.setup({ size: 180 })` no render, dentro de `try/catch` para o editor abrir mesmo se o minimapa falhar), `zoomBy`, `fit` (`AreaExtensions.zoomAt`), `organize`, e os botões. O minimapa fica no canto inferior direito do canvas e some em janela estreita (classe responsiva).
- [ ] **Step 3: Build and look.** No navegador: minimapa mostra os blocos, zoom e centralizar funcionam, "Organizar blocos" arruma o modelo bagunçado de propósito (arrastar dois blocos para cima um do outro e organizar) sem cruzar posições; em janela de 800 px o minimapa não cobre o painel de propriedades. Anotar no ledger.
- [ ] **Step 4: Run** `node --test tests/js`, `npm run build`. Expected: PASS e build ok.
- [ ] **Step 5: Commit** `feat: minimapa, zoom, centralizar e organizar blocos no editor`.

### Task 4: menu de contexto, atalhos, desfazer e duplicar

**Files:**
- Modify: `resources/js/flow-editor/editor.js` (histórico com `History`, `duplicateNode`, `undo`, `redo`, eventos de `contextmenu` com o id do bloco sob o ponteiro), `resources/js/flow-editor.js` (estado do menu, atalhos de teclado), `resources/views/livewire/automation/editor.blade.php` (menu de contexto e botões Desfazer/Refazer no cabeçalho do canvas)
- Test: verificação no navegador

**Interfaces:**
- Consumes: `History`, `duplicate` (Tarefa 1); `api.getGraph()` / `api.load(graph)`.
- Produces: `createEditor` devolve `undo()`, `redo()`, `canUndo()`, `canRedo()`, `duplicateNode(id)`. O histórico guarda `JSON.stringify({nodes, edges})` (sem mensagens do bot) a cada mudança do canvas com 300 ms de espera; desfazer/refazer recarregam o grafo sem disparar novo registro. Menu de contexto: no fundo, "Adicionar bloco" com a paleta; em um bloco, "Duplicar" (exceto Início) e "Apagar" (exceto Início). Atalhos (só com o foco no canvas ou fora de campos de texto): `Delete`/`Backspace` apaga o bloco selecionado, `Ctrl+Z` desfaz, `Ctrl+Y` e `Ctrl+Shift+Z` refazem, `Ctrl+D` duplica.

- [ ] **Step 1: Implement** o histórico e as ações em `editor.js` e o estado em `flow-editor.js`; o menu como `<div x-show>` posicionado no ponteiro, itens em `flux:button`.
- [ ] **Step 2: Build and look.** No navegador: clique direito no fundo abre o menu e adiciona um bloco; clique direito num bloco duplica e apaga; `Delete` apaga; `Ctrl+Z` desfaz a exclusão e `Ctrl+Y` refaz; as mensagens do bot do painel continuam depois de desfazer. Anotar no ledger.
- [ ] **Step 3: Run** `node --test tests/js`, `npm run build`, `php artisan test --filter=AutomationEditorTest` e `--filter=ViewConventionsTest`. Expected: PASS.
- [ ] **Step 4: Commit** `feat: menu de contexto, atalhos e desfazer no editor de workflows`.

### Task 5: erro no bloco e lista de Automação

**Files:**
- Modify: `resources/js/flow-editor.js` (aplicar `errorNodeIds` depois de validar/publicar; limpar ao editar o bloco), `resources/views/livewire/automation/index.blade.php` e `app/Livewire/Automation/Index.php` (mini-resumo)
- Test: `tests/Feature/Livewire/AutomationIndexTest.php`

**Interfaces:**
- Consumes: `errorNodeIds` e `api.setErrors` (Tarefas 1 e 2).
- Produces: `Index` passa a calcular por workflow `nodes_count` (blocos do rascunho) e `sectors_count` (setores distintos citados em blocos Transferir com setor fixo, mais "menu" se há Menu de setores).

- [ ] **Step 1: Write the failing test** em `AutomationIndexTest`: `test_card_mostra_resumo_do_rascunho`: um workflow do modelo padrão mostra "5 blocos"; um do modelo com horário mostra "8 blocos".
- [ ] **Step 2: Run** `php artisan test --filter=AutomationIndexTest`. Expected: FAIL.
- [ ] **Step 3: Implement** o resumo no cartão (`{{ $n }} blocos`) e, no editor, marcar em vermelho os blocos citados pelos erros (`errorNodeIds`), limpando a marca do bloco quando ele é editado.
- [ ] **Step 4: Run** `php artisan test --filter=AutomationIndexTest`; verificação no navegador: validar um fluxo com bloco sem ligação e ver o bloco vermelho e a lista levando até ele. Anotar no ledger.
- [ ] **Step 5: Commit** `feat: erro de validação marcado no bloco e resumo do rascunho na lista`.

### Task 6: verificação (portão 7)

**Files:** nenhum.

- [ ] **Step 1: Run** `vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`, `node --test tests/js`, `npm run build` e a suíte com Redis (receita no `CLAUDE.md`). Ler o código de saída de cada um. Expected: todos 0 e suíte verde.
- [ ] **Step 2:** Se o Pint reformatar algo, commitar `style:` à parte.
