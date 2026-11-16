# Menores, leva 3 (12–20) e nomes Automação x IA (23): plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** fechar os menores 12, 15–20, investigar 13 e 14, e trocar "Bot" por "Automação" na interface.

**Architecture:** cada menor é uma tarefa pequena com o próprio teste, no arquivo que já cuida do assunto. 13 e 14 são investigação do
controlador (sem implementador) com portão do dono antes de qualquer correção grande.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2, PHPUnit, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-16-menores-leva-3-design.md`

## Global Constraints

- Valor gravado `bot` (enums `MessageOrigin::BOT`, `NoteOrigin::BOT`, `ClosedReason::BOT`, tipos `bot_*` de evento), classes `Bot*` e
  token de cor `bot` (`bg-bot`, `text-bot`, tom `bot` do `x-status-badge`) **não mudam**. Só texto que a pessoa lê.
- "Automação" é feminino: "pela Automação", "a Automação", "da Automação".
- Cada tarefa tira o seu item de `docs/superpowers/menores-pendentes.md` no mesmo commit.
- Commits em português com prefixo (`fix:`, `test:`, `docs:`).
- Testes afetados: `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`.

## Review Focus

- Rascunho no formato antigo (sem `at`) e mais de 200 tickets com rascunho: os sem data e os mais antigos vão primeiro (Tarefa 3).
- `setor_id` vindo do JSON como `7`, `"7"`, `"7.0"`, `"1e3"`, `null`: só `7` e `"7"` contam (Tarefa 4).
- Trava ocupada no backup **manual** ou durante restauração não alerta ninguém; só o diário (Tarefa 1).
- Filtros e cores por origem seguem funcionando depois da troca de texto (valor `bot` intacto) (Tarefa 8).
- Prazo de apagar de 1 hora/1 dia no singular (Tarefa 2).

---

### Task 1: Backup diário com a trava ocupada alerta o admin (item 12)

**Files:**
- Create: `app/Notifications/BackupSkipped.php`
- Modify: `app/Console/Commands/BackupDatabaseCommand.php` (ramo `$file === null`), `app/Livewire/Alerts/Bell.php:162-166`
- Test: `tests/Feature/Console/BackupDatabaseCommandTest.php`, `tests/Feature/Backup/BackupAlertsTest.php`

**Interfaces:**
- Produces: `BackupSkipped extends AlertNotification`, `toArray()` = `['kind' => 'backup', 'level' => 'critico', 'state' => 'skipped']`
  (mesmo molde de `BackupStale`).

- [ ] **Step 1: testes que falham**
  - `test_diario_com_lock_ocupado_alerta_os_admins`: admin ativo criado, `Notification::fake()`, `RunBackup` mockado devolvendo `null`;
    `artisan('app:backup-database')` com sucesso; `Notification::assertSentTo($admin, BackupSkipped::class)`.
  - `test_manual_com_lock_ocupado_nao_alerta`: mesmo cenário com `--manual`; `Notification::assertNothingSent()`.
  - Em `BackupAlertsTest`, `test_bell_mostra_backup_que_nao_rodou`: notificação `BackupSkipped` no admin; o sino mostra
    `Backup diário não rodou` e `Outro backup estava em andamento`.
- [ ] **Step 2:** rodar e ver falhar (classe não existe).
- [ ] **Step 3:** criar `BackupSkipped`; no comando, injetar `AlertRecipients` no `handle` e, só quando `! $manual` e `$file === null`,
  `Notification::send($recipients->admins(), new BackupSkipped)` (o `warn` continua). No `Bell`, `'skipped' => ['circle-stack', 'warn',
  'Backup diário não rodou', 'Outro backup estava em andamento']`.
- [ ] **Step 4:** testes passam, mais `tests/Feature/Console/BackupDatabaseCommandTest.php` e `tests/Feature/Backup` inteiros.
- [ ] **Step 5:** commit `fix: backup diário com a trava ocupada alerta o admin no sino`.

### Task 2: Prazo de apagar em horas quando não fecha em dias (item 15)

**Files:**
- Modify: `app/Policies/MessagePolicy.php:51-55`
- Test: `tests/Unit/Policies/MessagePolicyWindowTest.php` (criar; se já houver teste de `MessagePolicy` em `tests/Feature`, pôr lá)

**Interfaces:**
- Produces: `public static function windowLabel(int $hours): string` em `MessagePolicy`.

- [ ] **Step 1: teste que falha** `test_rotulo_do_prazo_em_dias_ou_horas`: `48 → '2 dias'`, `24 → '1 dia'`, `36 → '36 horas'`, `1 → '1 hora'`.
- [ ] **Step 2:** ver falhar.
- [ ] **Step 3:** implementar `windowLabel` (múltiplo de 24 vira dias, senão horas, singular em 1) e usar em `revoke` no lugar de `$dias`.
- [ ] **Step 4:** passa, junto com os testes de apagar mensagem já existentes (`codegraph affected`).
- [ ] **Step 5:** commit `fix: prazo de apagar para todos em horas quando não fecha em dias`.

### Task 3: Rascunhos: corte pelos mais antigos, leitura por chave, usuário inativo (item 16)

**Files:**
- Modify: `resources/js/draft-store.js`, `resources/js/composer-mode.js:22-28`
- Test: `tests/js/draft-store.test.mjs`, `tests/Feature/Livewire/InboxDraftCleanupTest.php`

**Interfaces:**
- Produces: `draftTicketId(key, userId): number|null` (id do ticket da chave do usuário, ou `null`);
  `draftTicketIds(entries, userId): number[]` agora recebe `[key, raw][]` e devolve os ids únicos **do mais antigo para o mais novo**
  (rascunho sem `at` conta como o mais antigo; empate fica na ordem de entrada). Quem chama faz o `.slice(0, 200)`.

- [ ] **Step 1: testes que falham**
  - `draftTicketId`: `('rascunho:1:10:reply', 1) → 10`; `('rascunho:2:10:reply', 1) → null`; `('rascunho:1:x:reply', 1) → null`.
  - `draftTicketIds ordena do mais antigo e põe o formato antigo primeiro`: entradas do ticket 30 (`at` 300), 10 (`at` 100), 20 (texto
    cru), 10 de novo (`at` 500), uma de outro usuário → `[20, 10, 30]`.
  - Atualizar o teste atual de `draftTicketIds` para a nova assinatura.
  - PHP `test_usuario_inativo_nao_recebe_nenhum_id`: admin com `active => false` e um ticket encerrado →
    `closedDraftTickets([$closed->id])` devolve `[]` (se o `Inbox` recusar o `mount` do inativo, o teste afirma essa recusa e diz no
    relatório).
- [ ] **Step 2:** ver os de JS falharem (o PHP pode já passar: `readableBy` corta inativo; é teste de regressão, anotar no relatório).
- [ ] **Step 3:** implementar em `draft-store.js`; em `composer-mode.js` passar as entradas que não venceram para `draftTicketIds(...).slice(0, 200)`
  e filtrar as chaves com `closed.includes(draftTicketId(key, userId))`.
- [ ] **Step 4:** `node --test tests/js` e `php artisan test tests/Feature/Livewire/InboxDraftCleanupTest.php` passam.
- [ ] **Step 5:** commit `fix: limpeza de rascunhos corta pelos mais antigos`.

### Task 4: Contagem de setores só com id inteiro (item 17)

**Files:**
- Modify: `app/Livewire/Automation/Index.php:136-145` (`summary`)
- Test: `tests/Feature/Livewire/AutomationIndexTest.php`

- [ ] **Step 1: teste que falha** `test_setor_id_que_nao_e_inteiro_nao_conta`: setor existente de id `N`; rascunho com blocos `transferir` de
  `setor_id` `N` (int), `"N"` (texto), `"N.0"`, `"1e3"` e `null` (com setor de id 1000 existente para o `1e3` pegar se fosse aceito);
  o cartão mostra `1 setor` (conferir o texto exato que a view já usa para a contagem). O teste atual do `setor_id` texto fica.
- [ ] **Step 2:** ver falhar (`"N.0"` e `"1e3"` contam hoje).
- [ ] **Step 3:** trocar `is_numeric` + `(int)` por `filter_var($id, FILTER_VALIDATE_INT)` (aceita `int` e texto de inteiro) e quebrar a cadeia
  em passos com variável por etapa (ids crus, ids válidos, ids existentes).
- [ ] **Step 4:** `AutomationIndexTest` passa.
- [ ] **Step 5:** commit `fix: contagem de setores do workflow só com id inteiro`.

### Task 5: `fitView` só monta as posições quando ancora (item 18)

**Files:**
- Modify: `resources/js/flow-editor/appearance.js:261-265` (`fitPlan`), `resources/js/flow-editor/editor.js:260-266`
- Test: `tests/js/flow-appearance.test.mjs`

**Interfaces:**
- Produces: `fitPlan({ k, x, y, inset, positions })` com `positions` agora uma **função** `() => {type, x, y}[]`, chamada só no ramo que
  ancora o Início.

- [ ] **Step 1: testes que falham:** `fitPlan não lê as posições quando o zoom fica` (passa `positions: () => { throw new Error('lido') }`
  com `k: 1`, resultado igual ao atual); os três testes atuais de `fitPlan` passam a envolver o array em `() => [...]`.
- [ ] **Step 2:** ver falhar.
- [ ] **Step 3:** `fitPlan` chama `positions()` só no ramo do `anchorTranslate`; em `editor.js` o `flatMap` vira o corpo da função passada.
- [ ] **Step 4:** `node --test tests/js` passa; `npm run build` sem erro.
- [ ] **Step 5:** commit `fix: fitView só monta as posições dos blocos quando ancora o Início`.

### Task 6: Testes frágeis (item 19)

Só testes: o RED clássico não se aplica. Para provar que cada teste novo morde, quebrar o código de propósito, ver falhar e desfazer.

**Files:**
- Modify: `tests/Feature/Livewire/BackupsRestoreTest.php:102-105`, `tests/js/storage-blocked.test.mjs:20-23`,
  `tests/Feature/Seeders/AmbienteDeTesteSeederTest.php:79-82`, `database/seeders/Cenarios/EtiquetasDosDoisEscopos.php`

**Interfaces:**
- Produces: `EtiquetasDosDoisEscopos::NOME_DO_CONTATO = 'Cliente com Etiquetas'`, nome do contato que o cenário cria.

- [ ] **Step 1:** trocar `test_trava_de_despacho_dura_120_segundos` por `test_pedido_com_a_trava_tomada_nao_despacha_e_o_pedido_solta_a_trava`:
  com `Cache::lock('restore-dispatch', 120)->get()` tomado por outro dono, `restore` mostra `Já há uma restauração em andamento.` e
  `Queue::assertNothingPushed()`; depois de soltar essa trava e fazer um pedido válido, `Cache::lock('restore-dispatch', 1)->get()` é `true`
  (o pedido soltou a trava). Provar: comentar o `$latch->release()`, ver falhar, desfazer.
- [ ] **Step 2:** no `after` do `storage-blocked`, para `window` e `document`: se o original era `undefined`, `delete globalThis.<nome>`;
  senão, restaurar. `node --test tests/js` passa.
- [ ] **Step 3:** o cenário cria o contato com `NOME_DO_CONTATO`; o teste do seed pega esse contato e o ticket dele (no lugar de "o primeiro
  etiquetado") e mantém os dois asserts de etiqueta que ficou de fora. Seed e teste passam.
- [ ] **Step 4:** commit `test: trava da restauração pelo comportamento, after do storage e etiqueta amarrada ao cenário`.

### Task 7: `UsuariosDeTeste` garante setores ativos com a marca já gravada (item 20)

**Files:**
- Modify: `database/seeders/Cenarios/UsuariosDeTeste.php:21-28`
- Test: `tests/Feature/Seeders/AmbienteDeTesteSeederTest.php`

- [ ] **Step 1: teste que falha** `test_usuarios_de_teste_sozinho_cria_setores_mesmo_com_a_marca`: `Setting::put(DefaultSectors::MARK, true)`,
  nenhum setor; rodar `UsuariosDeTeste` (do jeito que o teste do seed já roda cenário, respeitando a guarda de ambiente) → 5 setores
  `ATIVO` com os nomes de `DefaultSectors::NAMES`, e o atendente de teste com 2 setores, ambos ativos.
- [ ] **Step 2:** ver falhar (hoje o `SectorSeeder` vê a marca e não cria nada).
- [ ] **Step 3:** sem setor ativo, criar (ou reativar pelo nome, se existir apagado/desativado) os de `DefaultSectors::NAMES` com `menu_order`
  pela posição; o atendente pega os 2 primeiros **ativos**.
- [ ] **Step 4:** teste do seed inteiro passa.
- [ ] **Step 5:** commit `fix: cenário de usuários de teste garante setores ativos`.

### Task 8: "Bot" vira "Automação" na interface e regras da IA em docs (item 23)

**Files:**
- Modify (lista conhecida; a varredura do Step 3 manda): `resources/views/livewire/inbox/transcript.blade.php:69`,
  `resources/views/livewire/inbox/inbox.blade.php:335`, `resources/views/livewire/automation/index.blade.php:2`,
  `resources/views/livewire/automation/editor.blade.php:248-249`, `resources/views/livewire/settings/tags.blade.php:1`,
  `resources/views/livewire/settings/company.blade.php:22`, `app/Enums/ClosedReason.php:20`, `app/Enums/NoteOrigin.php:15`,
  `app/Support/TicketTimeline.php:115`, `app/Support/TicketEventText.php:34-35`, `app/Livewire/Automation/Editor.php:227`,
  `app/Services/Bot/Flow/FlowValidator.php:85`
- Create: `docs/automacao-e-ia.md`
- Test: os testes que já afirmam esses textos (achar com `grep -rn "Bot \|pelo bot\|do bot" tests`) e um novo no transcript

- [ ] **Step 1: testes que falham:** mensagem de origem `BOT` no transcript mostra `Automação · resposta automática` e não
  `Bot · resposta automática`; `NoteOrigin::BOT->label() === 'Automação'`; `ClosedReason::BOT->label() === 'Encerrado pela Automação'`;
  evento `bot_started` → `Automação iniciou o atendimento`; o encaminhamento da linha do tempo começa com `Automação encaminhou para`.
  Atualizar os testes antigos que afirmam o texto com "Bot".
- [ ] **Step 2:** ver falhar.
- [ ] **Step 3:** trocar os textos. Varredura de texto visível: `grep -rniE "\bbots?\b" resources/views resources/js app lang --include=*.php --include=*.js --include=*.json`
  e trocar só o que a pessoa lê (rótulos, ajudas, mensagens de erro, `$description` de comando não). Comentários e nomes técnicos ficam.
- [ ] **Step 4:** criar `docs/automacao-e-ia.md` com a seção "Regras para a IA" da spec (três autores, dois modos, estado com filtro e
  motivo gravado, métricas separadas, aviso ao cliente a decidir) e uma linha dizendo que no código o valor segue `bot`.
- [ ] **Step 5:** suíte `php artisan test --parallel` passa.
- [ ] **Step 6:** commit `fix: o que aparecia como Bot passa a se chamar Automação`.

Conferência visual depois desta tarefa (agente `conferente-visual`, servidor local com o seed, 720p, 1080p, 2K e celular): rótulo da
bolha da Automação no transcript (não quebra feio, cor `bot`), faixa "Em triagem automática", nota da Automação, sino com o alerta da
Tarefa 1.

### Task 9 (controlador): canal desativado volta a conectar (item 13)

Sem implementador. `superpowers:systematic-debugging`.

- [ ] Ler os logs do WAHA e do app no beta (agente `leitor-web-logs`, API do Coolify) depois de um reinício com canal desativado; olhar os
  `session.status` gravados em `waha_events` desse canal.
- [ ] Hipótese a confirmar: hoje o `deactivate` já faz `logout` (`ChannelCard.php:127`), então a sessão volta em `SCAN_QR_CODE`, não
  `WORKING`. Se o problema não se reproduz mais, fechar o item com a evidência. Se reproduz: tarefa nova com TDD em `HandleWahaEvent::applyStatus`
  (canal `active = false` que recebe estado diferente de `STOPPED` → `WahaClient::stop()`), **depois de mostrar a causa ao dono**.

### Task 10 (controlador): desempenho da miniatura e do sino (item 14)

Sem implementador. Medir no `dimetal_local` com o seed.

- [ ] Sino: contar consultas e tempo do `render` do `App\Livewire\Alerts\Bell` com 50+ notificações (`DB::enableQueryLog`).
- [ ] Miniatura: tamanho e tempo da resposta da rota de mídia de imagem no transcript com as bolhas do cenário `BolhasDeTamanhosVariados`.
- [ ] Levar os números ao dono. Correção barata (N+1, índice, `select` de colunas) vira tarefa nova com TDD; mudança grande (gerar
  miniatura no servidor) volta ao dono e sai desta leva.

### Task 11: canal desativado não volta no WAHA (item 13, causa da Task 9)

Causa (log do beta e docs do WAHA): `POST /api/sessions/{s}/logout` com a sessão rodando "desloga e inicia do zero" (fica em QR), e
com `WHATSAPP_RESTART_ALL_SESSIONS=true` o WAHA religa toda sessão `STOPPED` ao reiniciar. Só apagar a sessão (`DELETE`, que também
desloga e para) a tira da lista. Mesma distinção no Evolution API (logout mantém a instância; delete remove).

**Files:**
- Modify: `app/Livewire/Settings/ChannelCard.php:127-141` (`deactivate`), `app/Jobs/HandleWahaEvent.php:97-119` (`applyStatus`)
- Test: o teste atual do `deactivate` do cartão (achar com `grep -rln "deactivate" tests/Feature`), `tests/Feature/Jobs/HandleWahaEventTest.php`
  (ou onde ficam `test_status_da_sessao_atualiza_o_canal`)

- [ ] **Step 1: testes que falham**
  - Desativar chama `DELETE /api/sessions/{sessão}` (com `Http::fake`) e não `POST .../logout`; o canal fica `active = false`, `STOPPED`.
  - `test_canal_desativado_que_volta_no_waha_e_apagado_de_novo`: canal `active = false`, `STOPPED`; evento `session.status` com
    `SCAN_QR_CODE` (e outro com `WORKING`) → `Http::assertSent` do `DELETE` da sessão e o canal continua `STOPPED`.
  - Canal desativado que recebe `STOPPED` não chama o WAHA.
  - Reativar segue funcionando: com a sessão apagada (`GET` 404), `start()` cria a sessão (teste existente do `reactivate` deve
    continuar verde; se não houver, criar um).
- [ ] **Step 2:** ver falhar.
- [ ] **Step 3:** `deactivate` usa `WahaClient::deleteSession()` no lugar de `logout()` (o aparelho sai dos dispositivos conectados do mesmo
  jeito; atualizar o comentário do método). Em `applyStatus`, canal `active = false` com estado diferente de `STOPPED`: `deleteSession()` e
  sair sem gravar o estado (o canal fica `STOPPED`).
- [ ] **Step 4:** testes do cartão, do job e de webhook passam; suíte `--parallel` passa.
- [ ] **Step 5:** commit `fix: canal desativado tem a sessão apagada no WAHA e não volta ao reiniciar`.

### Task 12: cenário do seed com conversa da Automação (para a conferência visual da Task 8)

A conferência visual não achou mensagem de origem `bot` nem nota da Automação no seed: os itens 1 e 3 da Task 8 ficaram sem prova.

**Files:**
- Create: `database/seeders/Cenarios/ConversaDaAutomacao.php`
- Modify: `database/seeders/AmbienteDeTesteSeeder.php` (chamar o cenário)
- Test: `tests/Feature/Seeders/AmbienteDeTesteSeederTest.php`

- [ ] **Step 1: teste que falha** `test_tem_conversa_da_automacao`: depois do seed existe `Message` com `origin = MessageOrigin::BOT` e
  `TicketNote` com origem da Automação (`NoteOrigin::BOT`, na coluna que o modelo usa), no mesmo ticket, que tem também mensagem do cliente.
- [ ] **Step 2:** ver falhar.
- [ ] **Step 3:** cenário no molde de `NotaInterna`/`BolhasDeTamanhosVariados`: ticket `AGUARDANDO` no `CanalConectado::canal()`, uma
  mensagem do cliente ("Oi, quero falar com vendas"), uma resposta `OUT`/`BOT` com texto de menu de várias linhas e uma nota da Automação
  com o que ela coletou ("Nome: Ana · Setor escolhido: Vendas"). Guarda `exigirAmbienteDeTeste()`.
- [ ] **Step 4:** teste do seed passa.
- [ ] **Step 5:** commit `test: cenário do seed com conversa da Automação`.
