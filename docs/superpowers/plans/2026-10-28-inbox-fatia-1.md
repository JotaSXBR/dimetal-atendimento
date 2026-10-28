# Inbox fatia 1 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a operação recebe mensagens do WhatsApp numa fila, um atendente assume, responde texto com ticks de entrega e leitura, e encerra o ticket, com vários números e em tempo real.

**Architecture:** o webhook continua gravando o evento bruto; `HandleWahaEvent` passa a delegar para serviços de ingestão (contato, ticket, mensagem, ack) em `app/Services/Inbox`. O envio grava a linha local antes de chamar o WAHA. A Inbox é Livewire sobre Policy + ações atômicas; o tempo real usa Laravel Reverb com eventos que levam só ids.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Flux 2, PostgreSQL 18, Redis 8, PHPUnit 12, Larastan nível 6, Pint, Laravel Reverb + Echo.

**Spec:** `docs/superpowers/specs/2026-10-28-inbox-fatia-1-design.md` (leia as seções 5 e 9). Referência visual: arquivo Pencil `Dimetal.pen`, telas Inbox, Ticket encerrado e Config - Canais.

## Global Constraints

- Entrega em três PRs, nesta ordem: **PR 0** (Task 0), **PR A** (Tasks 1–6), **PR B** (Tasks 7–12). Cada PR sai de uma branch própria a partir da `main` atualizada e só mergeia com CI verde.
- Verificação antes de abrir qualquer PR: `php artisan test`, `vendor/bin/phpstan analyse --no-progress` (0 erros) e `vendor/bin/pint --test` (ignorar apenas reclamação de `line_ending` por CRLF local). `composer` não está no PATH do bash.
- Estados do ticket: `aguardando`, `em_atendimento`, `encerrado`. Ticket aberto = `status <> 'encerrado'`; no máximo um por (`contact_id`, `channel_id`).
- `wa_message_id` é único por `channel_id`. Origens: `contato`, `painel`, `fora_do_painel`. Entrega: `sent`, `delivered`, `read`, `failed`.
- Códigos de ack do WAHA: 1 → `sent`; 2 → `delivered`; 3 e 4 → `read`; -1 → `failed`; 0 e demais → ignorar.
- Telefone no formato `<dígitos>@c.us`; remetente `@lid` vira telefone só se `_data.Info.SenderAlt` casar `^[1-9]\d{7,14}(:\d+)?@s\.whatsapp\.net$`; nome do remetente cortado em 120 caracteres.
- Mensagens e eventos nunca são editados ou apagados pela interface. Só `delivery_status`, `delivered_at`, `read_at` e `wa_message_id` (preenchimento do pendente) mudam depois da criação.
- Mensagem de grupo (`@g.us`), `status@broadcast` e newsletter são ignoradas com log.
- O WAHA já assina `message`, `message.any`, `message.ack`; nada muda na configuração da sessão.
- Mensagens da interface em português; código e nomes de classes em inglês, como o repositório já faz.
- Sem mexer no `main` do Coolify; só beta.

## Review Focus

1. Webhook reenviado com o mesmo id (e `message` + `message.any` para a mesma mensagem recebida) não duplica contato, ticket nem mensagem — Task 3.
2. Ack que chega fora de ordem nunca rebaixa o estado (`read` não volta a `delivered`) — Task 4.
3. Payload sem `body`, sem `timestamp` ou sem id não derruba o job nem cria linha vazia — Task 3.
4. Atendente abrindo por URL o ticket de outro recebe 403, e o canal de broadcast nunca carrega texto — Tasks 7 e 9.
5. Eco da mensagem do painel que chega antes do WAHA responder ao envio não vira "fora do painel" nem linha duplicada — Tasks 3 e 5.

---

## PR 0

### Task 0: Docs, `fontaine`, design e plano

**Files:**
- Modify (já alterados no disco): `docs/deploy.md`, `docs/deploy-coolify-api.md`, `docs/superpowers/notes/2026-10-28-topologia-coolify.md`, `docs/superpowers/specs/waha-config.md`, `docs/superpowers/plans/2026-10-27-fase-0-canal-whatsapp.md`, `package.json`, `package-lock.json`
- Create (já escritos): `docs/superpowers/specs/2026-10-28-inbox-fatia-1-design.md`, este plano

- [ ] **Step 1:** `git switch main && git pull`, depois `git switch -c chore/docs-fontaine-inbox-spec`. Conferir com `git status --short` que só esses arquivos mudaram; **não** incluir `.codegraph/`, `.gemini/`, `GEMINI.md`.
- [ ] **Step 2:** `npm ci && npm run build`. Esperado: build termina sem o aviso `fontaine`.
- [ ] **Step 3:** verificação global (testes, PHPStan, Pint). Esperado: tudo verde.
- [ ] **Step 4:** commit `docs: atualiza deploy, configuracao do WAHA e inclui spec e plano da Inbox` + `build: instala fontaine`, em dois commits; push; abrir PR; aguardar CI; mergear. O deploy automático roda sozinho.

---

## PR A — dados, ingestão, envio e canais múltiplos

### Task 1: Esquema e modelos do inbox

**Files:**
- Create: `database/migrations/2026_10_28_000001_create_contacts_table.php`, `..._000002_create_tickets_table.php`, `..._000003_create_messages_table.php`, `..._000004_create_ticket_events_table.php`
- Create: `app/Models/Contact.php`, `Ticket.php`, `Message.php`, `TicketEvent.php`
- Create: `app/Enums/TicketStatus.php`, `MessageDirection.php`, `MessageOrigin.php`, `DeliveryStatus.php`
- Create: `database/factories/ContactFactory.php`, `TicketFactory.php`, `MessageFactory.php`
- Test: `tests/Feature/Domain/InboxSchemaTest.php`

**Interfaces:**
- Produces: `Ticket` (`contact`, `channel`, `sector`, `assignee`, `messages`, `events` relações; casts de `status`; escopo `open()` = `status <> encerrado`), `Message` (casts de `direction`, `origin`, `delivery_status`; `ticket`, `contact`, `channel`), `Contact`. Colunas conforme spec 5.1, `tickets.assigned_user_id` → `users`, `tickets.sector_id` → `sectors` (nullable).
- Índices: parcial único em `tickets (contact_id, channel_id) WHERE status <> 'encerrado'`; único `messages (channel_id, wa_message_id)` (aceita vários nulos); único em `contacts.wa_id` e `contacts.lid` (nullable).

- [ ] **Step 1: Testes que falham** em `InboxSchemaTest`: `test_so_um_ticket_aberto_por_contato_e_canal` (segundo `Ticket` aberto lança `QueryException`; um encerrado + um aberto passa), `test_wa_message_id_e_unico_por_canal` (mesmo id em canais diferentes passa, no mesmo canal falha), `test_varias_mensagens_pendentes_sem_wa_message_id_sao_aceitas`.
- [ ] **Step 2:** rodar `php artisan test --filter=InboxSchemaTest`. Esperado: FAIL, tabelas inexistentes.
- [ ] **Step 3:** criar migrações, enums, modelos e factories (índice parcial via `DB::statement('CREATE UNIQUE INDEX ... WHERE status <> \'encerrado\'')`; seguir `Channel` como molde de atributos `#[Fillable]` e casts).
- [ ] **Step 4:** rodar o filtro. Esperado: PASS. Rodar PHPStan.
- [ ] **Step 5:** commit `feat: esquema de contatos, tickets, mensagens e eventos`.

### Task 2: Identidade do contato (regra de LID)

**Files:**
- Create: `app/Services/Inbox/ResolveContact.php`
- Test: `tests/Unit/Inbox/ResolveContactTest.php` (ou `tests/Feature/Domain/` se precisar de banco)

**Interfaces:**
- Produces: `ResolveContact::__invoke(string $from, ?string $senderAlt, ?string $displayName): Contact` — acha por `wa_id`, depois por `lid`; cria se não achar; completa `lid`/`name` faltantes sem sobrescrever apelido.

- [ ] **Step 1: Testes** (valores do crmv2): `@lid` com `SenderAlt` `5516997524950:53@s.whatsapp.net` → contato `wa_id=5516997524950@c.us`, `lid=16449842241553@lid`; sem sufixo de dispositivo também; `SenderAlt` ausente, nulo, `abc:1@s.whatsapp.net`, `0516997524950:1@...`, `1234567:1@...` ou `5516997524950:1@lid` → contato só com `lid` (`wa_id` nulo); `@c.us` nunca é reescrito; nome de 500 caracteres vira 120; segundo contato com o mesmo `lid` reutiliza a linha e, depois, ganha `wa_id` quando o `SenderAlt` válido aparece.
- [ ] **Step 2:** rodar; esperado FAIL.
- [ ] **Step 3:** implementar `ResolveContact` com a regex das Global Constraints.
- [ ] **Step 4:** rodar; esperado PASS.
- [ ] **Step 5:** commit `feat: resolve contato do WhatsApp com regra de LID`.

### Task 3: Ingestão de mensagens (recebidas e do celular)

**Files:**
- Create: `app/Services/Inbox/IngestMessage.php`
- Modify: `app/Jobs/HandleWahaEvent.php` (despachar `message` e `message.any` para o serviço)
- Test: `tests/Feature/Inbox/IngestMessageTest.php`, `tests/Feature/Jobs/HandleWahaEventTest.php`

**Interfaces:**
- Consumes: `ResolveContact`, modelos da Task 1.
- Produces: `IngestMessage::__invoke(WahaEvent $event): ?Message` — `null` quando ignora (grupo, broadcast, id ausente, `message.any` não `fromMe`). Cria/usa ticket aberto do contato e canal (`aguardando`, ou `em_atendimento` sem responsável com `first_human_reply_at` quando for `fromMe` sem ticket aberto); mensagem com `direction`/`origin` corretos; `sent_at` vem de `payload.timestamp` (segundos), com fallback para `received_at`; atualiza `last_inbound_at`/`last_outbound_at`; grava `TicketEvent` `message_in`/`message_out`, e `opened` quando cria o ticket. Tudo em transação; duplicata (mesmo canal + `wa_message_id`) retorna a linha existente sem efeitos.
- Eco: `fromMe` que casa com mensagem pendente (`origin=painel`, `wa_message_id` nulo, mesmo canal e contato, mesmo texto, `created_at` ≤ 60 s) preenche o id nela.

- [ ] **Step 1: Testes** (montar `WahaEvent` com envelopes realistas: `payload.payload = {id:'false_5516997524950@c.us_ABC', from, fromMe, body, timestamp, hasMedia, _data.Info.PushName}`):
  - `test_mensagem_recebida_cria_contato_ticket_e_mensagem`
  - `test_reenvio_do_mesmo_webhook_nao_duplica` e `test_message_e_message_any_da_mesma_mensagem_nao_duplicam`
  - `test_message_any_recebida_e_ignorada_mas_fromMe_e_gravada`
  - `test_fromMe_vira_fora_do_painel_no_ticket_aberto_e_nao_cria_ticket_novo`
  - `test_fromMe_sem_ticket_aberto_abre_em_atendimento_sem_responsavel`
  - `test_eco_completa_o_id_da_mensagem_pendente_do_painel_sem_duplicar`
  - `test_mensagem_de_grupo_e_broadcast_sao_ignoradas`
  - `test_payload_sem_body_timestamp_ou_id_nao_quebra` (sem id: retorna `null`; sem body: grava `body` vazio; sem timestamp: usa `received_at`)
  - `test_apos_encerrar_mensagem_nova_abre_ticket_novo`
  - em `HandleWahaEventTest`: evento `message` chama a ingestão e `session.status` continua atualizando o canal.
- [ ] **Step 2:** rodar; esperado FAIL.
- [ ] **Step 3:** implementar `IngestMessage` e ligar no job (`message`, `message.any`).
- [ ] **Step 4:** rodar os dois arquivos; esperado PASS; PHPStan limpo.
- [ ] **Step 5:** commit `feat: ingere mensagens do WAHA em contato, ticket e mensagem`.

### Task 4: Confirmações de entrega e leitura

**Files:**
- Create: `app/Services/Inbox/ApplyAck.php`
- Modify: `app/Jobs/HandleWahaEvent.php` (`message.ack`; reenfileira com `release(5)` até 6 tentativas)
- Test: `tests/Feature/Inbox/ApplyAckTest.php`

**Interfaces:**
- Produces: `ApplyAck::__invoke(WahaEvent $event): bool` — `true` quando aplicou ou ignorou de forma definitiva; `false` quando a mensagem ainda não existe (o job reenfileira; após a 6ª tentativa descarta com `Log::warning`). Nunca rebaixa: ordem `sent` < `delivered` < `read`; `failed` só se nada melhor. Preenche `delivered_at`/`read_at` na primeira vez.

- [ ] **Step 1: Testes:** ack 2 marca `delivered` e `delivered_at`; ack 3 e ack 4 marcam `read`; ack 1 sobre `read` não rebaixa; ack 0 e código desconhecido são ignorados; ack -1 marca `failed`; ack de mensagem inexistente devolve `false`; o job chama `release(5)` na primeira falta e descarta na 6ª (`Queue`/job fake com `attempts()`).
- [ ] **Step 2:** rodar; esperado FAIL. **Step 3:** implementar. **Step 4:** PASS + PHPStan.
- [ ] **Step 5:** commit `feat: aplica ack de entrega e leitura nas mensagens`.

### Task 5: Registro do envio pelo painel

**Files:**
- Create: `app/Services/Inbox/SendTicketMessage.php`
- Test: `tests/Feature/Inbox/SendTicketMessageTest.php`

**Interfaces:**
- Consumes: `WahaClient::sendText(string $chatId, string $text): array` (já existe, devolve o corpo com `id`).
- Produces: `SendTicketMessage::__invoke(Ticket $ticket, User $author, string $text): Message` — grava a linha antes (`direction=out`, `origin=painel`, `author_user_id`, `wa_message_id` nulo, `sent_at=now`), chama o WAHA, preenche `wa_message_id` com o `id` devolvido e atualiza `last_outbound_at`, `first_human_reply_at` (só na primeira vez) e o evento `message_out`. Em exceção do cliente, marca `delivery_status=failed`, mantém o texto e relança. Não valida permissão (isso é da Policy, Task 7).

- [ ] **Step 1: Testes** com `Http::fake`: `test_grava_a_linha_antes_de_chamar_o_waha` (assert na chamada fake que a linha já existe), `test_preenche_o_wa_message_id_devolvido`, `test_marca_first_human_reply_at_apenas_na_primeira_resposta`, `test_falha_do_waha_marca_failed_e_preserva_o_texto`, `test_eco_chegando_antes_da_resposta_do_waha_nao_duplica` (simular chamando `IngestMessage` dentro do callback do fake).
- [ ] **Step 2–4:** FAIL → implementar → PASS. **Step 5:** commit `feat: registra a mensagem enviada pelo painel antes do envio`.

### Task 6: Canais múltiplos na tela de Canais

**Files:**
- Create: `app/Livewire/Settings/ChannelCard.php`, `resources/views/livewire/settings/channel-card.blade.php`
- Modify: `app/Livewire/Settings/Channels.php` e `channels.blade.php` (viram a lista), `tests/Feature/Livewire/ChannelsScreenTest.php`

**Interfaces:**
- Produces: `ChannelCard` recebe `Channel $channel` e carrega a lógica por canal que hoje está em `Channels` (conectar, QR, código de pareamento, desconectar, status, renomear, texto de teste). `Channels` lista `Channel::query()->orderBy('id')->get()`, um `<livewire:settings.channel-card :channel="$channel" :key="...">` por canal e o formulário "Adicionar canal" (nome da sessão, regex `^[A-Za-z0-9_-]{1,60}$`, único).

- [ ] **Step 1: Testes** (adaptar os existentes para `ChannelCard` e acrescentar): `test_lista_um_cartao_por_canal`, `test_adiciona_segundo_canal_pelo_nome_da_sessao`, `test_sessao_duplicada_e_rejeitada`, `test_conectar_um_canal_nao_afeta_o_outro` (`Http::fake` e `assertSent` só na sessão certa), `test_nome_de_sessao_invalido_e_rejeitado`.
- [ ] **Step 2:** FAIL. **Step 3:** extrair o componente sem mudar comportamento, depois o formulário de adicionar. **Step 4:** suíte inteira verde.
- [ ] **Step 5:** commit `feat: tela de canais com varios numeros`. Depois: verificação global, push, PR A, CI, merge e conferência no beta com mensagem real (a prova do spec 8).

---

## PR B — Inbox e tempo real

### Task 7: Visibilidade e permissões

**Files:**
- Create: `app/Policies/TicketPolicy.php`; Modify: `app/Models/Ticket.php` (escopo `visibleTo(User)`), `app/Providers/AppServiceProvider.php` (registrar policy se necessário)
- Test: `tests/Feature/Inbox/TicketPolicyTest.php`

**Interfaces:**
- Produces: `Ticket::scopeVisibleTo(Builder, User)` — supervisor/admin (`User::canSeeAllSectors()`): todos; atendente: `status=aguardando` OU `assigned_user_id = $user->id`; usuário inativo: nenhum. `TicketPolicy::view|claim|reply|close(User, Ticket): bool` conforme spec 5.2 e 9 (reply exige responsável = usuário, ou supervisor/admin com ticket já assumido; claim só em ticket sem responsável e não encerrado; close pelo responsável, supervisor ou admin).

- [ ] **Step 1: Testes** matriz: atendente vê aguardando e os próprios, não vê `em_atendimento` de outro (`403` em `view`); supervisor vê tudo; inativo não vê nada; `reply` negado em ticket `aguardando` sem responsável, inclusive para supervisor; supervisor responde ticket de outro responsável; `claim` negado em ticket já atribuído ou encerrado.
- [ ] **Step 2–4:** FAIL → implementar → PASS. **Step 5:** commit `feat: visibilidade e permissoes de tickets`.

### Task 8: Assumir e encerrar

**Files:**
- Create: `app/Actions/Inbox/ClaimTicket.php`, `CloseTicket.php`
- Test: `tests/Feature/Inbox/ClaimAndCloseTest.php`

**Interfaces:**
- Produces: `ClaimTicket::__invoke(Ticket $ticket, User $user): bool` — `UPDATE tickets SET assigned_user_id, status='em_atendimento' WHERE id=? AND assigned_user_id IS NULL AND status <> 'encerrado'`; `true` se afetou 1 linha, grava `TicketEvent` `claimed`. `CloseTicket::__invoke(Ticket $ticket, User $user): void` — `status=encerrado`, `closed_at=now`, `TicketEvent` `closed` com o ator; idempotente em ticket já encerrado.

- [ ] **Step 1: Testes:** `test_dois_assumir_em_sequencia_so_o_primeiro_vence` (segundo recebe `false`, responsável não muda); `test_assumir_muda_para_em_atendimento_e_grava_evento`; `test_encerrar_grava_closed_at_e_evento_com_ator`; `test_encerrar_duas_vezes_nao_duplica_evento`.
- [ ] **Step 2–4:** FAIL → implementar → PASS. **Step 5:** commit `feat: assumir e encerrar ticket`.

### Task 9: Reverb e eventos de broadcast

**Files:**
- Modify: `composer.json`/lock (`laravel/reverb`), `package.json`/lock (`laravel-echo`, `pusher-js`), `.env.example` (`BROADCAST_CONNECTION=reverb`, `REVERB_*`, `VITE_REVERB_*`), `bootstrap/app.php` (broadcasting), `resources/js/echo.js` e `resources/js/app.js`
- Create: `routes/channels.php`, `app/Events/TicketUpdated.php`, `MessageCreated.php`, `MessageStatusChanged.php`
- Modify: `IngestMessage`, `ApplyAck`, `SendTicketMessage`, `ClaimTicket`, `CloseTicket` (disparar os eventos depois do commit)
- Test: `tests/Feature/Inbox/BroadcastTest.php`

**Interfaces:**
- Produces: eventos `ShouldBroadcast` no canal privado `inbox` e em `users.{id}` do responsável, carregando **apenas** `ticket_id`, `message_id` (quando houver) e `type`. `routes/channels.php`: `inbox` autorizado para usuário `active`; `users.{id}` só para o próprio usuário.

- [ ] **Step 1:** pesquisar e seguir a documentação oficial do Reverb para Laravel 13 antes de instalar (registrar a versão usada na descrição do PR); `composer require laravel/reverb`, `php artisan install:broadcasting`, `npm install laravel-echo pusher-js`.
- [ ] **Step 2: Testes:** `Event::fake` — ingestão de mensagem recebida dispara `MessageCreated` e `TicketUpdated`; ack dispara `MessageStatusChanged`; assumir e encerrar disparam `TicketUpdated`; `test_payload_do_evento_nao_contem_texto` (`broadcastWith()` só tem ids e tipo); autorização: usuário inativo recusado em `inbox`, usuário A recusado em `users.B`.
- [ ] **Step 3:** implementar. **Step 4:** PASS, `npm run build` ok, PHPStan limpo.
- [ ] **Step 5:** commit `feat: reverb e eventos de broadcast do inbox`.

### Task 10: Tela da Inbox

**Files:**
- Create: `app/Livewire/Inbox/Inbox.php`, `resources/views/livewire/inbox/inbox.blade.php`, `app/Jobs/SendTicketMessageJob.php`
- Modify: `routes/web.php` (rota `inbox`), `config/fortify.php` (`home` → inbox), `resources/views/partials` e o menu lateral (item "Fila do setor" e "Meus tickets"), remover `Route::view('dashboard'...)` e `tests/Feature/DashboardTest.php` se o dashboard sair
- Test: `tests/Feature/Livewire/InboxTest.php`

**Interfaces:**
- Consumes: `Ticket::visibleTo`, `TicketPolicy`, `ClaimTicket`, `CloseTicket`, `SendTicketMessage`.
- Produces: componente `Inbox` com propriedades `?int $ticketId`, `string $tab` (`todos|aguardando|meus`), `string $draft`; ações `select(int $ticketId)`, `claim()`, `close()`, `send()`; listeners Echo no canal `inbox` que apenas re-renderizam (o corpo vem do banco, já filtrado). `send()` valida texto não vazio (máx. 4096), autoriza `reply`, grava via `SendTicketMessage` dentro de `SendTicketMessageJob` (fila, nunca na requisição) e limpa o rascunho.
- Layout fiel ao Pencil (quatro colunas, abas, blocos Aguardando/Em atendimento, selo "fora do painel", ticks, painel de detalhes). Itens fora do escopo ficam ocultos.

- [ ] **Step 1: Testes Livewire:** `test_lista_mostra_so_o_que_o_usuario_pode_ver`; `test_abrir_ticket_de_outro_atendente_responde_403`; `test_assumir_move_o_ticket_para_meus`; `test_dois_usuarios_assumindo_o_mesmo_ticket_apenas_um_vence_e_o_outro_ve_aviso`; `test_enviar_despacha_job_e_limpa_rascunho`; `test_enviar_texto_vazio_e_rejeitado`; `test_atendente_nao_responde_ticket_nao_assumido`; `test_encerrar_remove_da_fila_e_mostra_somente_leitura`; `test_mensagem_fora_do_painel_mostra_o_selo`.
- [ ] **Step 2:** FAIL. **Step 3:** implementar tela, job e rota, abrindo o `Dimetal.pen` (tela Inbox) como referência visual; confirmar renderização com `npm run build` e uma checagem manual no navegador local. **Step 4:** PASS, suíte inteira.
- [ ] **Step 5:** commit `feat: tela da inbox com fila, conversa, assumir, responder e encerrar`.

### Task 11: Reverb no beta e deploy

**Files:**
- Modify: `.github/workflows/deploy-beta.yml` (UUID do `reverb-beta` na lista), `docs/deploy.md`, `docs/deploy-coolify-api.md`, `docs/superpowers/notes/2026-10-28-topologia-coolify.md`

- [ ] **Step 1:** criar o Application `reverb-beta` pela API do Coolify seguindo `docs/deploy-coolify-api.md` (mesmo Dockerfile do app, comando `php artisan reverb:start --host=0.0.0.0 --port=8080`, alias `reverb-beta`, healthcheck tipo `cmd` ou `http` conforme a porta, `AUTORUN_ENABLED=false`).
- [ ] **Step 2:** roteamento do WebSocket: decidir e documentar domínio ou rota para o navegador alcançar o Reverb (`wss`), e preencher `REVERB_*`/`VITE_REVERB_*` no app, worker e reverb; `BROADCAST_CONNECTION=reverb` no app e no worker.
- [ ] **Step 3:** adicionar o UUID do `reverb-beta` ao workflow de deploy e atualizar a tabela de recursos e variáveis nas docs.
- [ ] **Step 4:** verificar no beta: `reverb-beta` `running:healthy`; abrir a Inbox e confirmar a conexão WebSocket (aba de rede ou console).
- [ ] **Step 5:** commit `build: reverb-beta no coolify e deploy automatico`.

### Task 12: Prova real no beta

- [ ] **Step 1:** com dois usuários logados (atendente e supervisor) e um número conectado, mandar mensagem do celular de teste; confirmar que o ticket aparece sem recarregar nos dois.
- [ ] **Step 2:** assumir em um e tentar no outro ao mesmo tempo; confirmar um vencedor e aviso no outro.
- [ ] **Step 3:** responder pelo painel; confirmar chegada no celular e a progressão de ticks (um, dois, dois azuis); responder pelo celular e confirmar o selo "fora do painel"; confirmar que a resposta do painel não duplica.
- [ ] **Step 4:** encerrar e mandar nova mensagem; confirmar ticket novo `aguardando`.
- [ ] **Step 5:** registrar o resultado em `docs/superpowers/notes/` e abrir/mergear o PR B.
