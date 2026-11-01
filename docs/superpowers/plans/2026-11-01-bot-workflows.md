# Bot de pré-atendimento (workflows) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, decisão do dono; sem subagentes nem advisor). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Pré-atendimento por workflow: ticket nasce `pendente`, o bot conduz (saudação, menu de setores, perguntas, etiquetas) e transfere à fila do setor (`aguardando`), com editor visual Rete.js, rascunho/versões e um workflow ativo por canal.

**Architecture:** Grafo JSON versionado no Postgres; `bot_sessions` guarda o estado por ticket; um job na fila `inbound` avança o grafo a cada mensagem (lock por ticket) e a rotina de minuto expira silêncio. `IngestMessage` é o único ponto de entrada. Regra que guia tudo: **`pendente` = triagem automática sem humano; `aguardando` = fila humana**.

**Tech Stack:** Laravel 13, Livewire 4, Flux, Alpine, PostgreSQL 18, Rete.js (MIT) via Vite, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-01-bot-workflows-design.md`

## Global Constraints

- Commits em português com prefixo (`feat:`, `fix:`, `test:`, `docs:`); um commit por tarefa; terminar com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Branch `feat/bot-workflows` (já criado de `origin/main`); nunca commitar na `main`; **não abrir PR** (só se o dono pedir).
- Testes: `QUEUE_CONNECTION=database php artisan test --filter=...`; precisam de Postgres em 127.0.0.1:5432 (container `pg-test` do CLAUDE.md). Qualidade no fim: Pint, Larastan, PHPMD (`composer test`).
- Teto de 50 passos por execução do bot; Esperar de 1 a 30 s; opção inválida repete 2x e na 3ª vai à fila padrão; silêncio padrão 10 min; `#sair` (sem diferenciar maiúsculas) em qualquer ponto → fila padrão. Fila padrão = primeiro setor com `SectorStatus::receivesTickets()` por `menu_order` (menu usa `appearsInMenu()`).
- Mensagens do bot saem por `SendTicketMessage::create(..., MessageOrigin::BOT)` + `SendTicketMessageJob`; esse caminho não finge visto/digitando (só o bloco Esperar usa `WahaClient::startTyping/stopTyping`).
- Variáveis de texto: `{{nome}}`, `{{protocolo}}`, `{{setor}}`, `{{horario}}`, `{{proximo_horario}}` e as variáveis das Perguntas; texto do cliente nunca é interpolado como template.
- Permissão: admin e supervisor editam/publicam (`can:supervisor`); pendente é visível a todo usuário ativo, só leitura, e pode ser transferido por qualquer um.
- Sem workflow ativo no canal o ticket nasce `aguardando` (comportamento atual preservado).
- Editar código com a ferramenta `Edit` primeiro; migrations só expandem.

## Review Focus

1. Cliente manda áudio/imagem/figurinha no menu: conta como opção inválida, não quebra (Tarefa 6).
2. Duas mensagens seguidas enquanto o bot espera, ou o mesmo webhook reprocessado: um menu só, sem avançar duas vezes (Tarefa 6).
3. Nenhum setor ativo no menu: bot encaminha para `aguardando` sem setor, sem laço (Tarefa 5).
4. Setor desativado depois de publicada a versão: transferência cai na fila padrão (Tarefa 5).
5. Mensagem antiga reprocessada do histórico (>300 s) não inicia nem avança bot (Tarefa 6).

---

## File Structure

- `app/Enums/TicketStatus.php` (+`PENDENTE`, `inHumanQueue()`), `ClosedReason.php` (+`BOT`).
- `app/Models/{Workflow,WorkflowVersion,BotSession,Tag}.php`, factories, migrations `2026_11_02_0000NN_*`.
- `app/Services/Bot/Flow/{FlowGraph,FlowValidator,NodeType}.php`; `app/Services/Bot/{BotEngine,BotRun,Step,Vars}.php`; `app/Services/Bot/Nodes/*.php` (um por bloco, interface `Node`); `app/Services/Bot/{WorkflowPublisher,BotEntry}.php`.
- `app/Jobs/AdvanceBotSessionJob.php`; `app/Console/Commands/ExpireBotSessionsCommand.php` (+ agenda).
- `app/Livewire/Automation/{Index,Editor}.php` + views; `app/Livewire/Settings/Tags.php`; `resources/js/flow-editor/*.js`.
- Alterados: `Ticket.php`, `TicketPolicy.php`, `ClaimTicket.php`, `TransferTicket.php`, `CloseTicket.php`, `IngestMessage.php`, `Inbox.php` + view, `Navigation.php`, `routes/web.php`, `Channel.php`, `routes/console.php`.

---

### Task 1: Status `pendente` no domínio

**Files:**
- Modify: `app/Enums/TicketStatus.php`, `app/Enums/ClosedReason.php`, `app/Models/Ticket.php` (`scopeVisibleTo`, `scopeReadableBy`), `app/Policies/TicketPolicy.php`, `app/Actions/Inbox/ClaimTicket.php`, `app/Actions/Inbox/TransferTicket.php`
- Test: `tests/Feature/Bot/PendingStatusTest.php`

**Interfaces:**
- Produces: `TicketStatus::PENDENTE` (`'pendente'`, label "Pendente"), `TicketStatus::inHumanQueue(): bool` (true só para `AGUARDANDO`); `ClosedReason::BOT` (`'bot'`, "Encerrado pelo bot"); `TransferTicket::__invoke(Ticket $ticket, ?User $actor, Sector $to, ?string $note = null): bool`.

- [ ] **Step 1: Verificar se a coluna `tickets.status` tem `CHECK`/enum no banco** (`grep -rn "status" database/migrations | grep -i ticket`); se tiver, a migration `2026_11_02_000001_allow_pendente_status.php` amplia; se for `string`, nada a migrar.
- [ ] **Step 2: Testes (vermelhos)** em `PendingStatusTest`: `test_pendente_nao_pode_ser_assumido_nem_respondido` (policy `claim`/`reply` falsas, `ClaimTicket` devolve `false` e não muda o ticket); `test_qualquer_usuario_ativo_ve_o_pendente_em_leitura` (atendente de outro setor vê por `visibleTo`/`readableBy`); `test_atendente_transfere_pendente_e_ele_vira_aguardando_com_relogio_novo` (policy `transfer` verdadeira para atendente sem `can_transfer`; após `TransferTicket`, status `aguardando`, `sector_id` do destino, `waiting_since` ≈ agora, `sla_*` nulos); `test_pendente_nao_entra_no_sla` (`SlaClock` não agenda nem adota pendente); `test_pendente_nao_e_fila_humana` (`inHumanQueue()`); `test_transfer_aceita_ator_nulo` (evento `transferred` sem `actor_user_id`).
- [ ] **Step 3: Rodar e ver falhar** — `QUEUE_CONNECTION=database php artisan test --filter=PendingStatusTest` — FAIL (case inexistente).
- [ ] **Step 4: Implementar** — enum + `ClosedReason::BOT`; em `visibleTo`/`readableBy` incluir `status = pendente` para qualquer usuário ativo; `claim` exige `status !== PENDENTE`; `transfer` ganha `|| $ticket->status === PENDENTE` (mantendo ativo e não encerrado); `ClaimTicket` acrescenta `where status <> pendente`; `TransferTicket` aceita `?User $actor`, grava `actor_user_id` nulo e, quando o status anterior era `pendente`, zera `waiting_since=now()` e as cinco colunas `sla_*`; `AlertDispatcher::queued($ticket, $actor?->id)`.
- [ ] **Step 5: Rodar** `--filter="PendingStatusTest|ClaimAndCloseTest|RoutingPolicyTest|RoutingActionsTest|SlaClockTest"` — PASS (nenhuma regressão).
- [ ] **Step 6: Commit** — `feat: status pendente (triagem automática) no domínio de tickets`.

### Task 2: Pendentes no Inbox (leitura + transferir)

**Files:**
- Modify: `app/Livewire/Inbox/Inbox.php`, `resources/views/livewire/inbox/inbox.blade.php`, `app/Support/SidebarState.php` (só se contar pendentes)
- Test: `tests/Feature/Livewire/InboxPendingTest.php`

**Interfaces:** Consumes Task 1. Produces: aba `pendentes` ("Pendentes") em `$tabs`; `render()` expõe `pending` (coleção) e `tabCounts['pendentes']`.

- [ ] **Step 1: Testes** — `test_aba_pendentes_lista_so_pendentes_para_todos`; `test_pendente_abre_sem_caixa_de_resposta_e_com_transferir` (`canReply=false`, `canTransfer=true`, `canClaim=false`; o HTML mostra o aviso "Em triagem automática" e o botão Transferir, sem o botão Assumir); `test_transferir_pelo_painel_move_para_aguardando`.
- [ ] **Step 2: Falhar**, **Step 3: Implementar** a aba/lista (reuso do componente de linha de ticket; selo "Pendente"), banner no cabeçalho do ticket pendente, `canTransfer` via policy; `waiting`/`active` ignoram pendente.
- [ ] **Step 4: Passar** `--filter="InboxPendingTest|InboxTest|BroadcastTest"`. **Step 5: Commit** `feat: fila Pendentes no inbox (leitura e transferência)`.

### Task 3: Modelos e migrations do bot

**Files:**
- Create: migrations `2026_11_02_000002_create_workflows_tables.php`, `…000003_create_bot_sessions_table.php`, `…000004_create_tags_tables.php`; models `Workflow`, `WorkflowVersion`, `BotSession`, `Tag` + factories
- Modify: `app/Models/Channel.php` (fillable `workflow_id`, `workflow(): BelongsTo`), `app/Models/Ticket.php` (`tags(): BelongsToMany`, `botSession(): HasOne`)
- Test: `tests/Feature/Bot/ModelsTest.php`

**Interfaces — Produces:**
- `Workflow`: `name`, `draft` (array), `updated_by`, softDeletes; `versions(): HasMany`; `latestVersion(): ?WorkflowVersion`.
- `WorkflowVersion`: `workflow_id`, `number`, `graph` (array, imutável: `updating` lança `LogicException`), `published_by`, `published_at`; unique (`workflow_id`,`number`).
- `BotSession`: `ticket_id` (unique), `workflow_version_id`, `node_id`, `vars` (array), `attempts` (int), `waiting` (`'menu'|'input'|null`), `expires_at`, `state` (`ativa|transferida|encerrada|abandonada`); scope `active()`.
- `Tag`: `name` (unique), `color`; `tickets(): BelongsToMany` (`ticket_tag`).
- `channels.workflow_id` nullable FK (nullOnDelete).

- [ ] **Step 1: Testes** — versão publicada não pode ser alterada; `bot_sessions` aceita uma sessão por ticket (segunda viola unique); `Channel->workflow`; `Tag` nome único; `latestVersion()` devolve a de maior `number`.
- [ ] **Step 2–4:** falhar, implementar migrations/modelos/factories (índice parcial `bot_sessions_active_expires (expires_at) where state = 'ativa'`), passar. **Step 5: Commit** `feat: modelos de workflow, sessão do bot e etiquetas`.

### Task 4: Grafo e validador

**Files:**
- Create: `app/Services/Bot/Flow/{NodeType,FlowGraph,FlowValidator}.php`
- Test: `tests/Unit/Bot/FlowValidatorTest.php`

**Interfaces — Produces:**
- `enum NodeType: string` — `inicio, mensagem, menu_setores, menu, pergunta, condicao, esperar, midia, salvar_contato, etiquetar, transferir, encerrar`; `waits(): bool` (menu_setores, menu, pergunta); `isFinal(): bool` (transferir, encerrar).
- Formato do grafo: `['nodes' => [['id'=>'n1','type'=>'mensagem','x'=>0,'y'=>0,'data'=>[…]]], 'edges' => [['from'=>'n1','port'=>'out','to'=>'n2']]]`. Portas: `out`; `true`/`false` (condição); `opt:<n>` (menu); `setor` (menu_setores); `erro` (menu/pergunta, opcional: padrão = fila padrão).
- `FlowGraph::fromArray(array $graph): self`; `start(): ?string`; `node(string $id): ?array`; `next(string $id, string $port = 'out'): ?string`; `nodes(): array`.
- `FlowValidator::validate(array $graph, ?array $sectorIds = null, ?array $tagIds = null): list<string>` — mensagens "bloco <id>: …"; vazio = publicável.

- [ ] **Step 1: Testes (um por regra)** — um único Início; nó inalcançável; saída sem ligação; caminho sem fim em Transferir/Encerrar; ciclo sem bloco que espera o cliente (ciclo com Menu é válido); setor/etiqueta referenciados inexistentes; tipo desconhecido; grafo mínimo válido (Início → Mensagem → Transferir) devolve `[]`.
- [ ] **Step 2–4:** falhar, implementar, passar. **Step 5: Commit** `feat: grafo do workflow e validador de publicação`.

### Task 5: Motor — núcleo (mensagem, menus, transferir, encerrar, `#sair`, erros)

**Files:**
- Create: `app/Services/Bot/{BotEngine,BotRun,Step,Vars}.php`, `app/Services/Bot/Nodes/{Node,InicioNode,MensagemNode,MenuSetoresNode,MenuNode,TransferirNode,EncerrarNode}.php`
- Test: `tests/Feature/Bot/EngineCoreTest.php`

**Interfaces:**
- Produces: `BotEngine::start(Ticket $ticket, WorkflowVersion $version): BotSession` (cria sessão, roda até esperar/terminar); `BotEngine::receive(BotSession $session, Message $message): void` (resposta do cliente); `BotEngine::handoff(BotSession $s, string $reason, ?Sector $to = null): void` (`reason`: `fluxo|sair|erros|timeout`; transfere via `TransferTicket(ator null)`, grava `bot_handoff`, sessão `transferida`); `BotEngine::defaultSector(): ?Sector`.
- `Vars::render(string $template, array $vars): string` (só `{{chave}}` conhecidas; resto fica literal).
- `Step`: `Step::next(string $port='out')`, `Step::wait(string $kind, ?int $minutes = null)`, `Step::done()`.
- Cada `Node`: `enter(BotRun $run, array $data): Step`; os que esperam também `answer(BotRun $run, array $data, string $text): Step`. `BotRun` expõe `ticket`, `session`, `vars`, `say(string $text)` (cria e despacha a mensagem BOT).
- Padrões de texto: menu de setores lista `N - Nome` dos `Sector::visibleInMenu()` por `menu_order`, acrescenta a linha `Para falar com um atendente digite #sair`; aviso padrão de inválido "Opção inválida. Responda com o número de uma das opções."

- [ ] **Step 1: Testes** — fluxo simples ponta a ponta (saudação → menu de setores → escolhe "2" → encaminhamento com `{{protocolo}}` → ticket `aguardando` no setor 2, evento `bot_handoff` motivo `fluxo`, sessão `transferida`); opção inválida 2x repete com aviso e a 3ª transfere à fila padrão (motivo `erros`); `#sair`/`#SAIR` em menu e em pergunta (motivo `sair`); sem setor no menu → `aguardando` sem setor; setor desativado depois de publicado → fila padrão; 51 nós em laço → para no teto e transfere à fila padrão; `Encerrar` fecha com `ClosedReason::BOT`.
- [ ] **Step 2–4:** falhar, implementar (`BotEngine::run` percorre nós até `wait`/`done`/50 passos; `receive` trata `#sair`, depois `answer` do nó atual; `attempts` zera ao acertar), passar. **Step 5: Commit** `feat: motor do bot (mensagem, menus, transferência, #sair, erros)`.

### Task 6: Integração com `IngestMessage`, sessão, job e timeout

**Files:**
- Create: `app/Services/Bot/BotEntry.php`, `app/Jobs/AdvanceBotSessionJob.php`, `app/Console/Commands/ExpireBotSessionsCommand.php`
- Modify: `app/Services/Inbox/IngestMessage.php` (`openTicketFor`, `store`), `app/Actions/Inbox/CloseTicket.php` (fecha a sessão), `app/Services/Inbox/MergeLidContact.php` (sessão do ticket mesclado → `abandonada`), `routes/console.php` (agenda `bot:expire` a cada minuto, `withoutOverlapping(10)`), `app/Services/Inbox/AfterHoursReply.php` (não responde a ticket `pendente` com sessão ativa)
- Test: `tests/Feature/Bot/IngestIntegrationTest.php`, `tests/Feature/Bot/ExpireSessionsTest.php`

**Interfaces:**
- `BotEntry::handle(Message $incoming, bool $ticketIsNew): void` — decide: canal sem `workflow_id` ou sem versão publicada → nada; ticket novo → cria como `pendente` (ver abaixo) e `AdvanceBotSessionJob::dispatch($sessionId, $messageId)`; sessão ativa → despacha o job; senão nada. Só reage a mensagem do cliente com `sent_at` ≤ 300 s.
- `AdvanceBotSessionJob(int $sessionId, int $messageId)` na fila `inbound`; trava com `pg_advisory_xact_lock(hashtext('bot:'.$ticketId))`; se a sessão não está `ativa` ou a mensagem já foi processada (`bot_session` guarda `last_message_id`), sai.
- `bot:expire`: sessões `ativa` com `expires_at <= now()` → `BotEngine::handoff(..., 'timeout')`.
- Em `IngestMessage::openTicketFor`: com workflow ativo e `! fromMe`, o ticket é criado `PENDENTE` (sem `AlertDispatcher::queued`), `opened` + `bot_started`; com `fromMe` mantém `EM_ATENDIMENTO`.
- Mensagem `fromMe` (celular) numa conversa `pendente`: sessão `abandonada`, ticket `EM_ATENDIMENTO` (reusa o caminho atual).

- [ ] **Step 1: Testes** — canal sem workflow nasce `aguardando` e sem sessão; com workflow nasce `pendente`, com protocolo e primeira saudação despachada; `AlertDispatcher::queued` e `waiting_since` só após a transferência (Task 1); webhook repetido (mesmo `wa_message_id`) não reexecuta; **duas mensagens seguidas** com a sessão esperando → um menu só (`last_message_id`); **áudio no menu** = inválido; mensagem com mais de 300 s não inicia bot; `fromMe` encerra a sessão; `CloseTicket` por supervisor marca a sessão `encerrada`; `bot:expire` encaminha à fila padrão e é idempotente; `AfterHoursReply` não dispara em ticket pendente.
- [ ] **Step 2–4:** falhar, implementar, passar `--filter="IngestIntegrationTest|ExpireSessionsTest|IngestMessageTest|AfterHoursReplyTest|CloseInactiveTicketsTest"`. **Step 5: Commit** `feat: bot integrado à ingestão, com expiração de sessões`.

### Task 7: Motor — demais blocos

**Files:**
- Create: `app/Services/Bot/Nodes/{PerguntaNode,CondicaoNode,EsperarNode,MidiaNode,SalvarContatoNode,EtiquetarNode}.php`
- Test: `tests/Feature/Bot/EngineExtraNodesTest.php`

**Interfaces:**
- Pergunta `data`: `texto`, `variavel`, `validacao` (`texto|email|numero`), `timeout_min` (padrão 10), `invalido` (aviso). Guarda em `vars[variavel]`.
- Condição `data`: `kind` (`horario`|`variavel`); `horario` usa `BusinessHours::isOpen(now())` (e feriados já embutidos); `variavel` compara `var`, `op` (`igual|diferente|contem|preenchida`), `valor`.
- Esperar `data.segundos` (1–30, fora disso ajusta aos limites): `WahaClient::startTyping`, `Sleep::for`, `stopTyping`; melhor esforço (falha só vai ao log).
- Mídia `data`: `path` (arquivo no storage), `legenda`; usa `SendTicketMedia`/`AttachmentRules`; arquivo ausente → pula o bloco e registra no log.
- Salvar contato `data`: `campo` ∈ {`name`,`email`,`company`}, `variavel`; atualiza `Contact` e marca `edited_at` para a varredura Google existente.
- Etiquetar `data.tag_id`: anexa em `ticket_tag` (idempotente).

- [ ] **Step 1: Testes** — e-mail inválido repete e depois vai à fila padrão; número aceita só dígitos; Condição de horário (dentro/fora, feriado) e de variável; Esperar fora da faixa é limitado e falha do WAHA não derruba; mídia ausente não trava; Salvar contato grava o campo e deixa o contato elegível para o sync; Etiquetar duas vezes não duplica.
- [ ] **Step 2–4:** falhar, implementar, passar. **Step 5: Commit** `feat: blocos pergunta, condição, esperar, mídia, contato e etiqueta`.

### Task 8: Publicação, versões e workflow padrão

**Files:**
- Create: `app/Services/Bot/WorkflowPublisher.php`, `database/seeders/DefaultWorkflowSeeder.php` (chamado pela migration de dados `2026_11_02_000005_seed_default_workflow.php` ou pelo `DatabaseSeeder`, o que o repo já usa para dados base)
- Test: `tests/Feature/Bot/WorkflowPublisherTest.php`

**Interfaces — Produces:**
- `WorkflowPublisher::saveDraft(Workflow $w, array $graph, User $by): void`
- `publish(Workflow $w, User $by): WorkflowVersion` — valida com `FlowValidator` (lança `InvalidFlowException` com a lista), grava versão `number = max+1` imutável.
- `restore(Workflow $w, WorkflowVersion $v, User $by): void` — copia o grafo da versão para o rascunho.
- `activate(Workflow $w, Channel $c): void` / `deactivate(Channel $c): void` — exige ao menos uma versão publicada; define/limpa `channels.workflow_id`.
- `duplicate(Workflow $w): Workflow`; `delete(Workflow $w): void` (recusa se algum canal usa).
- Seed "Atendimento padrão": Início → Mensagem (saudação com `{{nome}}`) → Menu de setores → Mensagem (encaminhamento com `{{protocolo}}` e `{{setor}}`) → Transferir (setor escolhido); **sem versão publicada e sem canal vinculado** até o admin publicar e ativar.

- [ ] **Step 1: Testes** — publicar grafo inválido não grava versão e devolve erros; versões numeram 1,2,3; sessão em andamento fica na versão em que começou após nova publicação; restaurar versão antiga e publicar gera número novo; ativar sem versão publicada falha; apagar workflow com canal ativo falha; seed cria o fluxo padrão válido pelo `FlowValidator`.
- [ ] **Step 2–4:** falhar, implementar, passar. **Step 5: Commit** `feat: publicação, versões, ativação por canal e workflow padrão`.

### Task 9: Tela Automação (lista, versões, ativação)

**Files:**
- Create: `app/Livewire/Automation/Index.php`, `resources/views/livewire/automation/index.blade.php`, `app/Livewire/Settings/Tags.php` + view (CRUD de etiquetas, nome + cor)
- Modify: `routes/web.php` (`automacao` e `automacao/{workflow}` em `can:supervisor`; `configuracoes/etiquetas`), `app/Support/Navigation.php` (item "Automação" na seção Configuração, gate `supervisor`; "Etiquetas" no grupo Atendimento), `app/Models/Ticket.php`/`Inbox` (chip da etiqueta e filtro simples por etiqueta na lista)
- Test: `tests/Feature/Livewire/AutomationIndexTest.php`, `tests/Feature/Livewire/TagsTest.php`

**Interfaces:** Consumes `WorkflowPublisher`. `Index` expõe `newWorkflow()`, `duplicate(int)`, `delete(int)`, `activate(int $workflowId, int $channelId)`, `deactivate(int $channelId)`, `restore(int $versionId)`; exige `authorize('supervisor')` em cada ação.

- [ ] **Step 1: Testes** — atendente recebe 403; supervisor cria/duplica/apaga/ativa/desativa; apagar com canal ativo mostra erro; lista mostra versões e quem publicou; etiquetas: nome único, cor válida, apagar remove o vínculo; chip aparece no ticket.
- [ ] **Step 2–4:** falhar, implementar (Flux + padrões de `QuickReplies`), passar. **Step 5: Commit** `feat: tela de automação e cadastro de etiquetas`.

### Task 10: Editor visual (Rete.js)

**Files:**
- Create: `app/Livewire/Automation/Editor.php`, `resources/views/livewire/automation/editor.blade.php`, `resources/js/flow-editor/{index,nodes,serialize}.js`, `tests/js/flow-serialize.test.mjs`
- Modify: `package.json`/lock (`rete`, `rete-area-plugin`, `rete-connection-plugin`, `rete-render-utils` e o plugin de render em Lit, versões atuais e compatíveis entre si; conferir com `npm ci --dry-run`), `resources/js/app.js`/`vite.config`
- Test: `tests/Feature/Livewire/AutomationEditorTest.php`

**Interfaces:**
- `Editor` (Livewire, `mount(Workflow $workflow)`): `saveDraft(array $graph)` → `WorkflowPublisher::saveDraft`; `validateDraft(): array` (erros por bloco); `publish()`; `restore(int $versionId)`; props: `sectors`, `tags`, `channels` para os campos do painel. Grafo trafega só como JSON no formato da Tarefa 4.
- `serialize.js`: `toGraph(editor): {nodes, edges}` e `fromGraph(editor, graph)`; testada em Node (round-trip e portas `opt:<n>`/`true`/`false`).
- UI: paleta (12 blocos), canvas com zoom/arrastar, painel lateral Alpine com os campos de cada bloco (textos com ajuda de `{{variáveis}}`), botões Salvar rascunho, Validar (erros listados e bloco destacado), Publicar, aba Versões (restaurar). Funcional, sem polimento visual (o dono refaz o layout depois).

- [ ] **Step 1: Testes** — `serialize` round-trip (JS); Livewire: `saveDraft` grava, `publish` com grafo inválido mostra erros e não cria versão, `publish` válido cria versão, atendente 403, grafo com tipo desconhecido é recusado.
- [ ] **Step 2: Falhar. Step 3: Implementar** (Livewire primeiro, depois a ilha JS carregada só nessa página via `@vite`). **Step 4: Passar** (`php artisan test --filter=AutomationEditorTest` e `npx vp test` ou o runner JS do repo, como em `tests/js/channel-qr-options.test.mjs`). **Step 5: Verificação manual no navegador** (montar o fluxo padrão, salvar, publicar, ativar num canal de teste, enviar mensagem pelo WAHA falso/local) e conferir o console sem erros. **Step 6: Commit** `feat: editor visual de workflows com Rete.js`.

### Task 11: Fecho e verificação

**Files:** `docs/superpowers/plans/…` (ledger em `.superpowers/sdd/2026-11-01-bot-workflows/progress.md`), notas em `docs/`, memória.

- [ ] **Step 1:** suíte inteira + Pint + Larastan + PHPMD (`composer test`); cobertura ≥ 80% (`composer coverage`). Colar a saída lida.
- [ ] **Step 2:** revisão do branch inteiro com `superpowers:requesting-code-review` (o dono dispensou o advisor e subagentes: revisão própria, dita como tal na mensagem final); tratar achados com `receiving-code-review`.
- [ ] **Step 3:** `superpowers:verification-before-completion`, depois `superpowers:finishing-a-development-branch` (sem PR: só o que o dono pedir).
- [ ] **Step 4:** atualizar a memória do projeto (estado do bot, pendências do beta: redeploy de app, worker e scheduler; ativar o fluxo padrão num canal; smoke).
- [ ] **Step 5:** mensagem final com "Rulings I made" e "Deferred minors".

---

## Self-Review (feita)

- Cobertura do spec: seções 3, 5 (12 blocos), 6, 7, 8, 9, 10, 12 e 14 têm tarefa; a 11 (LLM) é só `BotEntry` como ponto de entrada.
- Tipos: `TransferTicket(?User)` (T1) é usado em `BotEngine::handoff` (T5); `BotEntry`/`AdvanceBotSessionJob` (T6) consomem `BotEngine` (T5); `WorkflowPublisher` (T8) é consumido por T9 e T10; formato do grafo é o da T4 em todas.
- Escolhas minhas do spec (seção 13) mantidas.
