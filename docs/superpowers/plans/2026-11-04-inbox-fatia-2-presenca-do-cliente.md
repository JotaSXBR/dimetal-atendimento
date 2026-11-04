# Fatia 2: presença do cliente (digitando / gravando) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O atendente vê "digitando…" e "gravando áudio…" do cliente na linha do ticket e no cabeçalho da conversa, dentro de uma janela curta aberta pela mensagem de entrada, sem gravar presença no banco.

**Architecture:** A mensagem de entrada ao vivo despacha `SubscribeClientPresenceJob` (fila `realtime`), que reutiliza a janela `online` e o `offline` agendado de `Presence` (abordagem A, janela única compartilhada) e assina `POST /api/{sessão}/presence/{chat}/subscribe`. O webhook trata `presence.update` **antes** de gravar `WahaEvent`: um job `HandleClientPresence` normaliza o payload, guarda o estado em Redis com TTL e transmite `ClientPresenceChanged` pelo Reverb (`private-inbox`, só ids e estado). O navegador guarda os estados num store Alpine e expira sozinho.

**Tech Stack:** Laravel 13, Livewire 4, Flux, Reverb, Redis (cache), WAHA 2026.9.2 (GOWS), PHPUnit (`php artisan test`).

**Spec:** `docs/superpowers/specs/2026-11-03-inbox-completa-design.md` (seção 5, "Fatia 2", bloco "Decisões do dono em 04/11").

## Global Constraints

- Textos de tela em português; commits em português com prefixo (`feat:`, `fix:`), um commit por tarefa; branch `feat/inbox-presenca-cliente` (nunca a `main`); sem PR até o dono pedir.
- **Sem online contínuo.** Janela fixa de **60 s** a partir da mensagem de entrada ao vivo (provisória, ajustada pela medição); só o ticket que recebeu a mensagem é assinado.
- **Nenhuma tabela nova e nenhuma linha de `WahaEvent` para `presence.update`.** Estado só em Redis com TTL (`config('presence.state_ttl_seconds')`, provisório **15 s** até a medição).
- Janela única compartilhada: reutilizar `Presence::ensureOnline` e `Presence::scheduleOffline`; `offline` em `max(45, 60)` s; o "humano ativo" continua adiando o `offline`. Nenhuma chave de online nem timer novo.
- Falha de presença nunca atrapalha a ingestão nem o bot (`Presence::attempt`; o job falha mole).
- O evento do Reverb leva só ids e estado, nunca texto (como `InboxEvent`).
- `presence.update` tem limite próprio no webhook e não conta nos 300/min das mensagens.
- Se a medição (Task 3) mostrar que o evento só chega com online contínuo: **parar e voltar ao dono** (custo: push do celular).
- Busca de código do app só por `codegraph_explore`; Blade/JS pelo Bash (`grep`), avisando o dono. Testes: Postgres em 127.0.0.1:5432; ciclo rápido `php artisan test --parallel`; portão 7 espelha o CI com Redis em série (receita do `CLAUDE.md`), mais Pint, Larastan e PHPMD.

## Review Focus

1. `presence.update` de contato `@lid` ou de chat sem ticket aberto (`@newsletter`, `@broadcast`, contato desconhecido): nada é transmitido, nada quebra, nada vai ao banco (Task 4).
2. Cliente que digita e depois manda a mensagem: o "digitando" some quando a mensagem chega, sem esperar o TTL (Task 4 e Task 5).
3. Dois números (canais) com o mesmo cliente: a presença vai ao ticket do canal certo, não ao do outro (Task 4).
4. Rajada de `presence.update` não pode derrubar o webhook de mensagens: limite próprio, o das mensagens fica intacto (Task 1).
5. Janela de presença do cliente não pode derrubar o "gravando" do atendente nem o `offline` pendente do bot: o job nunca envia `offline` antes de `max(45, 60)` s e respeita o "humano ativo" (Task 2).

---

### Task 1: Webhook trata `presence.update` sem gravar `WahaEvent` e com limite próprio

**Files:**
- Modify: `app/Http/Controllers/Webhooks/WahaWebhookController.php` (`__invoke`), `app/Providers/AppServiceProvider.php:39` (limiter `webhook`), `config/security.php` (nova chave)
- Create: `app/Jobs/HandleClientPresence.php`
- Test: `tests/Feature/Webhooks/WahaWebhookTest.php`, `tests/Feature/Security/RateLimitTest.php`

**Interfaces:**
- Produces: `App\Jobs\HandleClientPresence::__construct(public int $channelId, public array $payload)`, fila `realtime`, `handle(): void` (corpo na Task 4; aqui só um `Log::debug('presence.update bruto', $payload)` que a Task 3 usa na medição).
- Produces: `config('security.presence_per_minute')` (inteiro, padrão 600).

- [ ] **Step 1: Write the failing tests**
  - `WahaWebhookTest::test_presence_update_nao_grava_waha_event_e_despacha_o_job`: corpo com `event=presence.update`, `session` de um canal existente, `payload.id='5516997524950@c.us'`; afirma resposta 200, `WahaEvent::count() === 0`, `Queue::assertPushed(HandleClientPresence::class)` com `channelId` do canal e `HandleWahaEvent` **não** despachado.
  - `WahaWebhookTest::test_presence_update_de_sessao_desconhecida_e_ignorado`: nada despachado, 200.
  - `RateLimitTest::test_presence_update_tem_limite_proprio`: com `security.webhook_per_minute=2` e `security.presence_per_minute=5`, 5 `presence.update` passam e o 6º dá 429; logo depois um evento `message` ainda passa (o contador das mensagens não foi consumido).
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Webhooks/WahaWebhookTest.php tests/Feature/Security/RateLimitTest.php` → FAIL (`HandleClientPresence` não existe).
- [ ] **Step 3: Implement.** No controller, depois da validação do HMAC e de `$session`, se `$body['event'] === 'presence.update'`: resolver o canal com `channelIdFor`, despachar `HandleClientPresence($channelId, $body['payload'] ?? [])` (ignorar se o canal for nulo) e devolver `{ok:true}` **antes** do `firstOrCreate`. No limiter `webhook` (`AppServiceProvider`): se `$request->input('event') === 'presence.update'`, usar `Limit::perMinute(config()->integer('security.presence_per_minute'))->by($request->ip().':presence')`, senão o limite atual. Adicionar `'presence_per_minute' => 600` em `config/security.php`, ao lado de `webhook_per_minute`. O job só registra `Log::debug`.
- [ ] **Step 4: Run to verify they pass** — mesmo comando → PASS.
- [ ] **Step 5: Commit** — `git add` dos arquivos acima; `git commit -m "feat: webhook trata presence.update sem gravar evento e com limite proprio"`.

### Task 2: Mensagem de entrada ao vivo abre a janela e assina a presença do chat

**Files:**
- Modify: `app/Services/Waha/WahaClient.php` (novo `subscribePresence`), `app/Services/Inbox/Presence.php` (`openClientWindow`, `scheduleOffline` com atraso opcional), `app/Services/Inbox/IngestMessage.php` (`announce`, ramo `LIVE` e `! $incoming->fromMe`)
- Create: `app/Jobs/SubscribeClientPresenceJob.php`, `config/presence.php`
- Test: `tests/Feature/Inbox/ClientPresenceWindowTest.php`, `tests/Feature/Services/WahaClientTest.php`

**Interfaces:**
- Produces: `WahaClient::subscribePresence(string $chatId): void` → `POST /api/{sessão}/presence/{chatId}/subscribe` (corpo vazio; `ensureOk`).
- Produces: `Presence::openClientWindow(WahaClient $client, Channel $channel, string $chatId): void` — chama `ensureOnline`, `attempt('assinar presença', subscribePresence)` e `scheduleOffline($channel, max(OFFLINE_AFTER_SECONDS, config('presence.window_seconds')))`.
- Changes: `Presence::scheduleOffline(Channel $channel, ?int $delaySeconds = null): void` (padrão continua `OFFLINE_AFTER_SECONDS`; chamadas existentes não mudam).
- Produces: `App\Jobs\SubscribeClientPresenceJob::__construct(public int $ticketId)`, fila `realtime`, `$tries = 1`; `handle(Presence $presence)` carrega ticket, contato e canal, sai se o canal não está conectado (`Channel::isConnected()`), senão `openClientWindow`.
- Produces: `config('presence.window_seconds')` = 60 e `config('presence.state_ttl_seconds')` = 15 (arquivo novo, comentário com "provisório até a medição").

- [ ] **Step 1: Write the failing tests**
  - `WahaClientTest::test_subscribe_presence_chama_a_rota_da_sessao`: `Http::fake`; afirma `POST .../api/main/presence/5516997524950@c.us/subscribe`.
  - `ClientPresenceWindowTest::test_entrada_ao_vivo_despacha_a_assinatura_depois_do_commit`: ingere mensagem `LIVE` do cliente; `Queue::assertPushed(SubscribeClientPresenceJob::class)` com o id do ticket. Variações que **não** despacham: mensagem `fromMe`, `RECOVERED` e `IMPORTED`.
  - `ClientPresenceWindowTest::test_job_garante_online_assina_e_agenda_offline_em_60s`: `Http::fake`, `Queue::fake`; roda o job; afirma as chamadas `presence` (`online`) e `subscribe` nessa ordem, e `GoOfflineJob` com atraso de 60 s.
  - `ClientPresenceWindowTest::test_janela_reaproveita_o_online_ja_aberto_pelo_bot`: com `Cache` de `presence-online:{canal}` já ocupado, o job **não** chama `online` de novo, mas assina.
  - `ClientPresenceWindowTest::test_humano_ativo_adia_o_offline_da_janela`: com `presence-human:{canal}` marcado, o `GoOfflineJob` da janela reagenda em vez de mandar `offline` (cobre o Review Focus 5).
  - `ClientPresenceWindowTest::test_falha_do_waha_na_assinatura_nao_estoura_o_job`: `Http::fake` com 500; o job termina sem exceção e registra o aviso.
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Inbox/ClientPresenceWindowTest.php tests/Feature/Services/WahaClientTest.php --filter=ClientPresence|subscribe` → FAIL.
- [ ] **Step 3: Implement** as assinaturas acima. Em `announce`, no ramo `$mode === IngestMode::LIVE && ! $incoming->fromMe`, `SubscribeClientPresenceJob::dispatch($ticket->id)->afterCommit()`. `openClientWindow` não duplica lógica de online/offline: só chama `ensureOnline` e `scheduleOffline` já existentes.
- [ ] **Step 4: Run to verify they pass** — mesmo comando, e `php artisan test tests/Feature/Inbox/PresenceSendTest.php tests/Feature/Inbox/IngestMessageTest.php` → PASS (nada do que existia mudou).
- [ ] **Step 5: Commit** — `git commit -m "feat: mensagem de entrada abre a janela e assina a presenca do cliente"`.

### Task 3: Medição no beta (portão de decisão, não gera código novo)

**Files:**
- Modify: `config/presence.php` (`state_ttl_seconds`, `window_seconds`, se a medição pedir), `docs/superpowers/specs/2026-11-03-inbox-completa-design.md` (registrar o resultado na seção "Fatia 2")

**Interfaces:**
- Consumes: `HandleClientPresence` com o `Log::debug` da Task 1 e a janela da Task 2, já no beta.

- [ ] **Step 1: Pedir ao dono o deploy do branch no beta.** O beta só faz deploy quando a `main` recebe merge; sem esse merge não há como assinar o WAHA daqui. Pedir autorização explícita e o caminho (branch `beta` ou merge nomeado). Nunca tocar a produção.
- [ ] **Step 2: Medir com o contato de teste (Jessé, (16) 99752-4950).** Mandar uma mensagem do celular dele ao canal; durante a janela de 60 s digitar e depois gravar áudio; repetir sem a janela (mais de 60 s depois) digitando. Ler o log do `waha-beta` e o log do app pela API do Coolify (`.env.coolify.local`; nunca imprimir o token). Registrar: (a) o evento chega com a sessão online só na janela? (b) chega com `offline`? (c) payload real, inclusive `@lid` e o campo do chat; (d) intervalo entre `typing` e `paused` e entre `typing` renovados.
- [ ] **Step 3: Decidir.** Chegou dentro da janela: ajustar `state_ttl_seconds = max(15, 2 × maior intervalo medido)` e `window_seconds` se a janela curta não bastou; registrar tudo no spec. **Não chegou sem online contínuo: parar e voltar ao dono**, com o custo (push do celular), sem decidir sozinho.
- [ ] **Step 4: Commit** — `git commit -m "docs: resultado da medicao de presenca no beta"` (e o ajuste de config, se houve).

### Task 4: `HandleClientPresence` normaliza, guarda em Redis e transmite

**Files:**
- Modify: `app/Jobs/HandleClientPresence.php`, `app/Services/Inbox/IngestMessage.php` (`announce`: limpar o estado quando a mensagem do cliente chega)
- Create: `app/Services/Inbox/ClientPresence.php`, `app/Events/ClientPresenceChanged.php`
- Test: `tests/Feature/Inbox/ClientPresenceTest.php`, `tests/Feature/Inbox/BroadcastTest.php`

**Interfaces:**
- Produces: `ClientPresence::fromPayload(array $payload): ?array{chat: string, state: 'digitando'|'gravando'|'parou'}` — lê `payload.id` e, em `payload.presences[]`, o item cujo `participant` coincide com o chat (ou o único item); mapeia `lastKnownPresence` `typing`→`digitando`, `recording`→`gravando`, `paused|online|offline`→`parou`; devolve `null` para formato desconhecido (tolerante, nunca lança).
- Produces: `ClientPresence::apply(Channel $channel, array $payload): void` — resolve o ticket **aberto** do contato (por `wa_id` ou `lid` igual ao chat) **no canal**; sem ticket, não faz nada; grava `Cache::put("client-presence:{ticketId}", state, ttl)` quando `digitando|gravando` e `Cache::forget` quando `parou`; despacha `ClientPresenceChanged`.
- Produces: `ClientPresence::states(array $ticketIds): array<int, string>` — `Cache::many` das chaves, só `digitando|gravando`.
- Produces: `ClientPresence::clear(int $ticketId): void` — `Cache::forget` + `ClientPresenceChanged` com `parou`.
- Produces: `App\Events\ClientPresenceChanged::__construct(int $ticketId, string $state)`; implementa `ShouldBroadcast`, fila `realtime`, canal `private-inbox`, `broadcastAs()` = `client.presence`, `broadcastWith()` = `['ticket_id' => …, 'state' => …]`.

- [ ] **Step 1: Write the failing tests**
  - `ClientPresenceTest::test_digitando_vira_estado_no_redis_e_evento`: payload real do WAHA (`payload.id` `@c.us`, `presences[0].lastKnownPresence='typing'`); afirma `ClientPresenceChanged` despachado com `state='digitando'` e `Cache::get('client-presence:{id}') === 'digitando'` com TTL de `config('presence.state_ttl_seconds')`.
  - `ClientPresenceTest::test_gravando_e_parou` e `::test_paused_online_e_offline_limpam_o_estado`.
  - `ClientPresenceTest::test_contato_lid_resolve_o_ticket_pelo_lid` (Review Focus 1).
  - `ClientPresenceTest::test_chat_sem_ticket_aberto_ou_de_tipo_desconhecido_nao_transmite` (`@newsletter`, `@broadcast`, contato inexistente, ticket encerrado): nenhum evento, nenhuma chave.
  - `ClientPresenceTest::test_dois_canais_com_o_mesmo_cliente_vao_ao_ticket_do_canal_certo` (Review Focus 3).
  - `ClientPresenceTest::test_payload_em_formato_desconhecido_e_ignorado_sem_erro`.
  - `ClientPresenceTest::test_chegada_de_mensagem_do_cliente_limpa_o_estado` (ingere mensagem `LIVE` com a chave preenchida; afirma chave removida e `parou` transmitido — Review Focus 2).
  - `BroadcastTest::test_payload_de_presenca_do_cliente_leva_so_ids_e_estado`: `broadcastWith()` não tem texto; `broadcastOn()` é `private-inbox`; fila `realtime`.
  - `ClientPresenceTest::test_nada_de_presenca_vai_ao_banco`: `WahaEvent::count() === 0` e nenhuma tabela nova (`Schema` sem tabela com "presence" no nome).
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Inbox/ClientPresenceTest.php tests/Feature/Inbox/BroadcastTest.php` → FAIL.
- [ ] **Step 3: Implement** os contratos acima. `HandleClientPresence::handle` troca o `Log::debug` por `ClientPresence::apply` (mantendo `Log::debug` do payload bruto só se `config('app.debug')`). A casa chat→contato reaproveita o que `ResolveContact` já usa para `wa_id`/`lid` (ler antes por `codegraph_explore`); o ticket é o aberto (`Ticket::open()`) do contato no `channel_id`.
- [ ] **Step 4: Run to verify they pass** — mesmo comando, mais `tests/Feature/Webhooks` → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: presenca do cliente normalizada, guardada em Redis com TTL e transmitida"`.

### Task 5: Tela mostra "digitando…" / "gravando áudio…" na lista e no cabeçalho

**Files:**
- Create: `resources/js/client-presence.js`
- Modify: `resources/js/app.js` (importar o módulo), `resources/views/livewire/inbox/inbox.blade.php` (linha do ticket ~linha 98 e cabeçalho ~linha 138), `app/Livewire/Inbox/Inbox.php` (`render` entrega `clientPresence` = `ClientPresence::states` dos ids visíveis)
- Test: `tests/Feature/Livewire/InboxClientPresenceTest.php`

**Interfaces:**
- Consumes: `ClientPresence::states(array $ticketIds): array<int, string>` e o evento `.client.presence` (`{ticket_id, state}`) do canal `private-inbox`.
- Produces: store Alpine `Alpine.store('clientPresence')` com `seed(states: object)`, `label(ticketId): string` (`'digitando…'`, `'gravando áudio…'` ou `''`) e expiração no navegador: cada evento `digitando|gravando` agenda o apagamento em `ttlSeconds` (valor vindo do servidor via `seed`, `config('presence.state_ttl_seconds')`), `parou` apaga na hora, e um `.message.created` com `direction === 'in'` do mesmo ticket apaga também.

- [ ] **Step 1: Write the failing tests** (Livewire):
  - `test_render_entrega_os_estados_dos_tickets_da_lista`: com `Cache::put('client-presence:{id}', 'digitando')`, o HTML da lista contém o `seed` com esse id e estado; sem a chave, o `seed` vem vazio.
  - `test_cabecalho_e_linha_usam_o_mesmo_store`: o HTML contém `$store.clientPresence.label({id})` na linha do ticket e no cabeçalho do ticket aberto.
  - `test_ticket_que_o_usuario_nao_ve_nao_entra_no_seed` (atendente sem acesso ao setor): estado dele não aparece.
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Livewire/InboxClientPresenceTest.php` → FAIL.
- [ ] **Step 3: Implement.** `client-presence.js`: registra o store em `alpine:init`, escuta `window.Echo?.private('inbox').listen('.client.presence', …)` e `.listen('.message.created', …)`; sem polling. No Blade, a linha mostra o rótulo no lugar da prévia enquanto `label(id) !== ''` (`x-show`/`x-text`, `wire:ignore` no span para o morph do Livewire não apagar o estado) e o cabeçalho mostra o rótulo abaixo do nome; rótulo com `aria-live="polite"`. Layout visual mínimo (o dono refaz o frontend depois com outras skills). **Avisar o dono em uma linha** que o `grep` em `resources/` foi usado (o índice não cobre Blade).
- [ ] **Step 4: Run to verify they pass** — mesmo comando; depois `npm run build` sem erro e conferência manual no beta (Task 3 já o deixou no ar): digitar no celular do cliente e ver a linha e o cabeçalho.
- [ ] **Step 5: Commit** — `git commit -m "feat: lista e conversa mostram o cliente digitando ou gravando"`.
