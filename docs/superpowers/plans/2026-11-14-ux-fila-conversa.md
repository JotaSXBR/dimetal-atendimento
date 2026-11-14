# UX da Fila e da Conversa (fatia B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, decisão do dono) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tirar da Fila e da Conversa a informação repetida e pôr cada controle onde se espera: painel enxuto, card sem repetição, menu "⋯", busca na Fila, resumo do dia e seletor de etiquetas com busca.

**Architecture:** Mudanças em Blade e nos componentes Livewire existentes (`Inbox`, `TicketTags`, `ContactCard`); um componente Blade novo `x-tag-picker` com Alpine registrado por `Alpine.data` (o build CSP não aceita método inline) e uma função pura de filtro testada em `node --test`. A busca da Fila reaproveita a regra do Histórico, extraída para um escopo de `Ticket`.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2.21 (grátis), Alpine (build CSP), Tailwind, PHPUnit, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-14-ux-simplificacao-design.md` (itens 1 a 5 e 4b da fatia B; componente `x-tag-picker`).

## Global Constraints

- Branch `feat/ux-fila-conversa`, da `main` (eadd55c). PR só ao fim, depois do portão 7. Sem commit na `main`.
- **Empilhamento (desvio, custo: rebase em cascata se o dono pedir mudança no meio):** a fatia A sai deste branch, a C da A, e assim por diante; cada PR diz "depende de #N".
- Sem dependência nova. Cores só por token (`ink`, `ink-2`, `ink-3`, `line`, `surface`, `canvas`, `accent*`, `warn`, `danger`, `ok`).
- Texto novo nunca abaixo de `text-xs` (12 px); alvo de clique novo ≥ 24×24 px.
- Alpine em CSP: nada de método, arrow function nem objeto literal dentro de atributo; o estado vai em `Alpine.data(...)` (padrão de `resources/js/ticket-details.js`).
- Verificação visual: 360 px, 1280×720, 1920×1080, 2560×1440, claro e escuro, com ≥ 20 tickets e nomes longos. `resize_window` não muda a janela: usar a receita da memória `ux-redesign-fatia-1-estado` (servidor local na 8765, banco `dimetal_visual`, iframes).
- Mudar propriedade ou método público de componente: `grep -rn` em `resources/views` e em `tests/` antes (`addTagId`, `updatedAddTagId`, `tagId`, `openPanel`).
- RED/GREEN só com os afetados: `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`.
- Commits em português com prefixo, terminando com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus

- **Busca da Fila:** telefone com `+55`, espaços e traço acha o contato; protocolo parcial (`000029`) acha; `%` e `_` valem como texto; busca só com espaços equivale a vazia; ticket de setor que o usuário não vê nunca aparece. (Task 4)
- **Resumo do dia:** o "SLA mais perto" só considera tickets que o usuário vê; sem nenhum ticket, ou sem ticket com SLA, mostra só "Escolha uma conversa à esquerda." (Task 5)
- **Menu "⋯":** quem não pode transferir nem reatribuir não vê o menu; ticket encerrado não mostra ações; Encerrar continua com a mesma permissão. (Task 3)
- **Seletor de etiquetas:** "orcamento" acha "Orçamento"; sem etiqueta disponível o seletor some; Enter sem item destacado não aplica; Esc fecha e devolve o foco ao campo; etiqueta já aplicada por outra pessoa não duplica. (Task 1)
- **Painel e cabeçalho:** o Histórico (somente leitura) continua com Canal, Encerrado em, Motivo, telefone; ticket sem setor mostra "Fila geral" e sem responsável "Não atribuído"; "Aberto em" continua alcançável (tooltip do protocolo). (Task 2)

---

### Task 1: `x-tag-picker` e as etiquetas com busca

**Files:**
- Create: `resources/js/tag-filter.js`, `resources/js/tag-picker.js`, `resources/views/components/tag-picker.blade.php`, `tests/js/tag-filter.test.mjs`, `tests/Feature/Components/TagPickerTest.php`
- Modify: `resources/js/app.js` (importar `./tag-picker`), `resources/views/livewire/inbox/ticket-tags.blade.php`, `resources/views/livewire/contacts/tags.blade.php`, `resources/views/livewire/inbox/inbox.blade.php` (filtro da Fila), `resources/views/livewire/contacts/index.blade.php` (filtro de Contatos), `app/Livewire/Inbox/TicketTags.php`, `app/Livewire/Contacts/ContactCard.php`, testes existentes de `TicketTags`/`ContactCard` que usam `addTagId`

**Interfaces:**
- Produces (JS): `filterTags(tags: {id:number,name:string}[], query: string): {id:number,name:string}[]` em `resources/js/tag-filter.js`. Ignora maiúscula e acento (`normalize('NFD')` sem marcas); consulta vazia devolve todas na ordem recebida; ordem preservada.
- Produces (Alpine): `Alpine.data('tagPicker', () => ({ open, query, active, items(): lista filtrada, toggle(), close(), next(), prev(), choose(), pick(id) }))`; lê as etiquetas de `data-tags` (JSON `[{id,name,color,group}]`) e o modo de `data-method` (chama `$wire[method](id)`) ou `data-model` (faz `$wire.set(model, id)`; `id = ''` limpa).
- Produces (Blade): `<x-tag-picker :tags="$collection" label="..." method="addTag" />` (modo adicionar) e `<x-tag-picker :tags="..." label="..." model="tagId" :selected="$tagId" placeholder="Todas as etiquetas" />` (modo filtro). `role="combobox"` no campo, `role="listbox"`/`option` na lista, `aria-activedescendant`, opção com a cor da etiqueta (mesmo ponto colorido do `x-tag-chip`).
- Consumes: `x-tag-chip` e `Tag::sortedByName(...)` como estão.

- [ ] **Step 1: Teste JS** `tests/js/tag-filter.test.mjs`: `filterTags([{id:1,name:'Orçamento'},{id:2,name:'Urgente'}], 'orcamento')` devolve só a id 1; `'URG'` devolve a id 2; `''` e `'   '` devolvem as duas, na ordem; `'zzz'` devolve `[]`.
- [ ] **Step 2:** `node --test tests/js/tag-filter.test.mjs`; esperado FAIL (módulo inexistente).
- [ ] **Step 3: Implementar `filterTags`** em `tag-filter.js` (função pura, sem DOM).
- [ ] **Step 4:** `node --test tests/js` passa.
- [ ] **Step 5: Teste de componente** `TagPickerTest`: o Blade renderiza `role="combobox"`, uma `option` por etiqueta, `data-method="addTag"` no modo adicionar e `data-model="tagId"` + o rótulo da selecionada no modo filtro; `$tags` vazio não renderiza nada. Rodar: FAIL.
- [ ] **Step 6: Implementar** `tag-picker.blade.php` e `tag-picker.js` (teclado: setas, Enter, Esc, Tab fecha; clique fora fecha; foco volta ao campo no Esc), importar em `app.js`.
- [ ] **Step 7: Trocar os cinco seletores.** `ticket-tags.blade.php`, `contacts/tags.blade.php` (modo adicionar, `method="addTag"`) e os filtros "Todas as etiquetas" da Fila e de Contatos (modo filtro, `model="tagId"`; grupos "Do contato" e "Da conversa" viram o campo `group` da opção). Remover `addTagId` e `updatedAddTagId` de `TicketTags` e `ContactCard` (o clique chama `addTag(id)` direto); atualizar os testes que usavam `->set('addTagId', ...)` para `->call('addTag', $id)` sem mudar o que provam.
- [ ] **Step 8: Teste de regressão** em `TicketTagsTest`: `addTag` de etiqueta já aplicada não cria segundo evento de auditoria (o teste já existe? se sim, só conferir que continua verde).
- [ ] **Step 9:** rodar os afetados; tudo verde. Commit `feat: seletor de etiquetas com busca (x-tag-picker)`.

### Task 2: painel enxuto e cabeçalho com setor e responsável

**Files:**
- Modify: `resources/views/components/ticket-details.blade.php`, `resources/views/livewire/contacts/contact-card.blade.php`, `resources/views/livewire/contacts/tags.blade.php`, `resources/views/livewire/inbox/ticket-tags.blade.php`, `resources/views/livewire/inbox/inbox.blade.php` (linha do cabeçalho), `resources/views/components/disclosure.blade.php` só se `open` precisar de mudança
- Test: `tests/Feature/Livewire/TicketDetailsBlocksTest.php`, `tests/Feature/Livewire/TicketTagsTest.php`, `tests/Feature/Livewire/InboxTest.php` (cabeçalho)

**Interfaces:**
- Consumes: `x-tag-picker` (Task 1); `x-disclosure :title :open :testid`.
- Produces: no Inbox (`! $readonly`), `ticket-details` não renderiza o `<dl>` do bloco Ticket nem o `x-disclosure title="Ticket"`; no Histórico (`$readonly`) ele fica como está. `ContactCard` compacto e não editando, com `$currentTicketId` e sem `$readonly`, mostra um único bloco "Etiquetas" com duas linhas rotuladas `Conversa:` (o `livewire:inbox.ticket-tags` aninhado) e `Contato:`; o telefone sai do resumo do contato quando `! $readonly`. Observações e Anteriores vêm fechados (`:open="false"`), com a contagem no título (`Observações (N)` com N = 0 ou 1 conforme tenha texto; `Anteriores (N)` como hoje).

- [ ] **Step 1: Testes (RED).** Em `TicketDetailsBlocksTest`: (a) no Inbox o painel não contém `data-testid="details-ticket"` nem a palavra "Responsável"; (b) contém um único `data-testid="ticket-tags"` com os rótulos "Conversa" e "Contato"; (c) no Histórico (`readonly`) ainda contém `details-ticket` com "Encerrado em" e "Motivo"; (d) o telefone formatado do contato não aparece no painel do Inbox e aparece no do Histórico; (e) `details-history` e a Observações não têm o atributo `open`. Em `InboxTest`: a linha de baixo do cabeçalho contém `protocolo · telefone · canal · Vendas · Jessé` para ticket com setor e responsável, `Fila geral` e `Não atribuído` quando faltam, e o `title` do protocolo traz `Aberto em dd/mm/aaaa hh:mm`.
- [ ] **Step 2:** rodar; FAIL nos três lugares.
- [ ] **Step 3: Implementar** o painel e o cabeçalho; o protocolo no cabeçalho vira `<span title="Aberto em …">` via `flux:tooltip` (grátis, hoje sem uso) com o texto de "Aberto em" no formato `d/m/Y H:i` do fuso de exibição.
- [ ] **Step 4:** testes dos Steps 1 e todos os afetados verdes. Commit `feat: painel da conversa enxuto e cabecalho com setor e responsavel`.

### Task 3: menu "⋯" com Transferir e Reatribuir

**Files:**
- Modify: `resources/views/livewire/inbox/inbox.blade.php` (bloco de ações do cabeçalho)
- Test: `tests/Feature/Livewire/InboxTest.php` (e o teste que hoje procura os botões Transferir/Reatribuir)

**Interfaces:**
- Consumes: `$canTransfer`, `$canReassign`, `$canClose`, `$canClaim`, `$canTakeover`, `openPanel('transfer'|'reassign'|'close')` como estão (nada muda em PHP).
- Produces: `flux:dropdown` com `flux:button icon="ellipsis-horizontal" aria-label="Mais ações"` e `flux:menu` com um `flux:menu.item` por ação permitida; o dropdown só existe se `$canTransfer || $canReassign`. `Encerrar`, `Assumir` e `Tomar` ficam como botões.

- [ ] **Step 1: Testes (RED):** com as duas permissões, o HTML tem `aria-label="Mais ações"` e itens "Transferir" e "Reatribuir" dentro do menu e nenhum botão avulso com esses nomes; só com `reassign`, o menu tem apenas "Reatribuir"; sem nenhuma das duas, não há "Mais ações"; ticket encerrado não mostra o menu; `Encerrar` continua visível quando `$canClose`.
- [ ] **Step 2:** FAIL. **Step 3:** implementar. **Step 4:** verde. Commit `feat: transferir e reatribuir no menu de mais acoes`.

### Task 4: busca na Fila

**Files:**
- Modify: `app/Models/Ticket.php` (novo escopo), `app/Services/Inbox/HistorySearch.php` (passa a usar o escopo), `app/Livewire/Inbox/Inbox.php`, `resources/views/livewire/inbox/inbox.blade.php`
- Create: `resources/js/queue-search.js` (Alpine `queueSearch`: `open`, `toggle()`, `close()`, foco no campo), importado em `app.js`
- Test: `tests/Feature/Inbox/QueueSearchTest.php`; manter `tests/Feature/History/HistorySearchTest.php` verde

**Interfaces:**
- Produces: `Ticket::scopeSearchContactOrProtocol(Builder $query, string $term): void`: `contact->search($term)` OU `protocol ilike`, com `%`, `_` e `\` escapados; termo vazio ou só espaços não filtra. `Inbox::$search` (`public string $search = ''`), aplicado à lista aberta e à de encerrados recentes, depois de `visibleTo`/da regra do encerrado.
- Consumes: `Contact::scopeSearch` (já trata dígitos de telefone e escape).

- [ ] **Step 1: Testes (RED)** `QueueSearchTest` (Livewire `Inbox`, `->set('search', ...)`): nome parcial acha; `+55 16 99752-4950`, `(16) 99752-4950` e `99752` acham o contato certo e só ele; `000029` acha o ticket de protocolo `2026-000029`; `%` e `_` não casam tudo; `'   '` devolve a lista inteira; atendente sem o setor do ticket B não o encontra mesmo digitando o nome exato do contato; na visão "Encerrados" o filtro também vale; combinado com `tagId`, os dois filtros se aplicam.
- [ ] **Step 2:** FAIL. **Step 3:** extrair o escopo de `HistorySearch` (a consulta do Histórico passa a chamar `->searchContactOrProtocol($q)` e mantém o texto das mensagens como está), usar na Fila; lupa ao lado de "Nova conversa" que abre o campo (`wire:model.live.debounce.300ms="search"`, `aria-label="Buscar na fila"`, Esc e "✕" fecham e limpam).
- [ ] **Step 4:** `QueueSearchTest` e `HistorySearchTest` verdes. Commit `feat: busca na Fila por nome, telefone e protocolo`.

### Task 5: card sem o repetido e Fila sem ticket aberto

**Files:**
- Modify: `app/Livewire/Inbox/Inbox.php` (`render`), `resources/views/livewire/inbox/inbox.blade.php` (card, coluna central, grade)
- Test: `tests/Feature/Livewire/InboxQueueCardTest.php`, `tests/Feature/Inbox/QueueSummaryTest.php` (novo)

**Interfaces:**
- Produces: `showAssignee` no card = visão diferente de `meus`; `showSector` = hoje (`≥ 2 setores recebendo`) **e** a lista visível tem mais de um `sector_id` distinto. Com `$ticket === null` o `render` passa `summary` = `['waiting' => int, 'mine' => int, 'nearest' => ?Ticket]`, onde `nearest` é, entre os tickets de `$list` (já `visibleTo`) com `sla_due_at` e não encerrados, o de menor `sla_due_at`. A grade usa `lg:grid-cols-[340px_minmax(0,1fr)]` quando não há ticket aberto (a faixa de detalhes some) e a atual quando há.

- [ ] **Step 1: Testes (RED):** na visão `meus` o card não tem `card-assignee-name`; em `todos` tem; com dois setores ativos mas todos os tickets listados do mesmo setor, `card-sector` não aparece; com tickets de dois setores aparece; resumo: mostra "Aguardando: N" e "Meus: M" iguais a `tabCounts`, mostra o protocolo do ticket de menor `sla_due_at` com `wire:click="select(id)"`; o protocolo de ticket de outro setor (invisível ao atendente) nunca aparece; sem ticket com SLA, mostra só "Escolha uma conversa à esquerda."; com ticket aberto o resumo não é renderizado.
- [ ] **Step 2:** FAIL. **Step 3:** implementar. **Step 4:** verde. Commit `feat: card da Fila sem repeticao e resumo do dia`.

### Task 6: verificação, ledger e fechamento da fatia

- [ ] **Step 1:** subir Postgres de teste e o servidor visual (receitas do `CLAUDE.md` e da memória); popular ≥ 20 tickets com nomes longos, 2 setores, etiquetas e 1 atendente.
- [ ] **Step 2:** conferir nas quatro resoluções e nos dois temas: Fila (card, lupa aberta, filtro de etiquetas), conversa (cabeçalho com "⋯", linha de baixo sem estourar), painel enxuto, Fila vazia com resumo, Histórico intacto. Corrigir o que estourar (`minmax(0,1fr)` em grade, `truncate`).
- [ ] **Step 3:** portão 6 (revisão independente do branch, ver `CLAUDE.md`), portão 7 completo, ledger, PR "depende de nada" (primeiro da pilha).

## Fora desta fatia

Piso de 12 px no app inteiro e Automação na barra lateral (fatia A); filtros do Histórico e gaveta de Contatos (C); confirmações e "Desfazer" (D); editor do bot (E); gráfico (F); Ctrl+K (G); correção do sino (depois da G, no PR da G, pedido do dono).
