# Recuperação de mensagens, importação inicial e eventos de mensagem: plano

> **Para quem executa:** use `superpowers:executing-plans` (execução inline, regra do projeto). Os passos usam caixas `- [ ]`.

**Meta:** recuperar sozinho as mensagens perdidas numa queda do canal, permitir puxá-las de novo à mão, importar o histórico inicial como tickets encerrados e tratar reação, edição e apagamento de mensagem.

**Arquitetura:** um `WahaClient::messages()` lista mensagens por janela; cada uma vira um `WahaEvent` sintético (`mode` no payload) e passa pelo `IngestMessage`, que ganha três modos (`live`, `recovered`, `imported`). Jobs na fila nova `history` fazem a recuperação e a importação. Reação, edição e apagamento ganham tratadores ao vivo.

**Stack:** Laravel 13, Livewire 4, Flux, PostgreSQL, filas em `docker/worker.sh`.

**Spec:** `docs/superpowers/specs/2026-11-03-recuperacao-historico-design.md`

## Restrições globais (valores do spec)

- Queda que recupera: **5 min ou mais**. Janela: `[down_since − 10 min, agora]`, busca **60 s** depois da volta, máximo de **7 dias**.
- Varredura diária às **04:15**, últimas **24 h**. Seletor manual: **1 h, 6 h, 24 h, 72 h**, padrão **24 h**.
- Importação: `backfill_days` limitado a **30** na tela; chat aberto = última mensagem do cliente, sem resposta depois, com até **48 h**; um ticket encerrado por chat, `closed_reason = importado`.
- Fila `history` (worker de concorrência 1, timeout 900 s) para recuperação, importação e a mídia delas.
- Reação não é atividade (não mexe em última mensagem, não lidas, espera, SLA). Apagada continua visível, com a marca "apagada pelo cliente".
- Textos de tela e commits em português. Commits com prefixo (`feat:`, `fix:`); um commit por tarefa; nunca na `main`.
- Testes: `php artisan test --parallel` (Postgres em 127.0.0.1:5432). Fechamento: `composer test` (Pint, Larastan, PHPMD).

## Review Focus

1. A mesma janela recuperada duas vezes (manual, varredura, webhook ao vivo) cria **zero** mensagens, tickets e avisos novos. → Tarefas 3 e 6.
2. Reação que chega antes da mensagem tenta de novo e depois é descartada; reação em `message`/`message.any` nunca vira mensagem. → Tarefa 5.
3. Número trocado durante a queda (aparelho novo) não recupera nada do outro número. → Tarefa 3.
4. Mensagem enviada pelo painel durante a queda e o eco dela não duplicam. → Tarefa 3.
5. Canal desativado, desconectado ou apagado quando o job roda não recupera nem importa. → Tarefas 3 e 6.

---

### Task 1: `WahaClient::messages()` com contrato real

**Arquivos:**
- Modificar: `app/Services/Waha/WahaClient.php`
- Criar: `tests/Fixtures/waha/chats-all-messages.json`
- Testar: `tests/Feature/Services/WahaClientTest.php`

**Interfaces:**
- Produz: `WahaClient::messages(CarbonInterface $from, CarbonInterface $to): \Generator<int, array<string, mixed>>` (uma mensagem por item, no formato do `payload` do webhook). Constantes `MESSAGES_PAGE_SIZE = 100`, `MESSAGES_MAX_PAGES = 200`.

- [ ] **Passo 1: capturar uma resposta real do beta.** No terminal do contêiner `app-beta` (Coolify), com `X-Api-Key` lida do ambiente e **sem imprimir a chave**, chamar `GET {WAHA}/api/main/chats/all/messages?filter.timestamp.gte=<há 24 h>&limit=5&downloadMedia=false`. Salvar o JSON sem telefones e textos reais em `tests/Fixtures/waha/chats-all-messages.json`. Registrar no corpo do commit duas respostas: (a) `chats/all` funciona neste GOWS? (b) a resposta traz campos de reação, edição ou apagamento? Se (a) falhar, implementar `messages()` pela abordagem 2 do spec (listar `GET /api/main/chats/overview` ordenado por `messageTimestamp`, filtrar os com atividade na janela, buscar `chats/{id}/messages` de cada um); a assinatura não muda. Se não houver acesso ao beta, pedir ao dono para rodar a chamada.
- [ ] **Passo 2: teste que falha.** Em `WahaClientTest`:
  - `test_messages_pagina_ate_a_ultima_pagina` com `Http::fake` devolvendo 100 itens e depois 3: o gerador entrega 103; a 1ª requisição tem `filter.timestamp.gte`/`lte` em segundos Unix, `limit=100`, `offset=0`, `downloadMedia=false`; a 2ª tem `offset=100`.
  - `test_messages_para_no_teto_de_paginas` (todas as páginas cheias) entrega no máximo `200 × 100` itens e para sem laço infinito.
  - `test_messages_falha_do_waha_lanca_waha_request_exception` (HTTP 500).
- [ ] **Passo 3: ver falhar.** `php artisan test tests/Feature/Services/WahaClientTest.php --filter=messages` → FAIL (método inexistente).
- [ ] **Passo 4: implementar** `messages()` em `WahaClient` usando `$this->request()`, `ensureOk` e `sessionPath('/chats/all/messages')`; `offset += limit` até a página vir menor que o limite ou chegar ao teto.
- [ ] **Passo 5: ver passar** o mesmo comando → PASS.
- [ ] **Passo 6: commit** `feat: WahaClient lista mensagens por janela`.

---

### Task 2: modos de ingestão (`recovered`), regra de frescor no bot, ack e fila da mídia

**Arquivos:**
- Criar: `app/Services/Inbox/IngestMode.php`
- Modificar: `app/Services/Inbox/IncomingMessage.php`, `app/Services/Inbox/IngestMessage.php`, `app/Services/Bot/BotEntry.php`, `app/Services/Inbox/ApplyAck.php`, `app/Enums/DeliveryStatus.php`, `app/Jobs/ResolveMessageMediaJob.php`
- Testar: `tests/Feature/Inbox/RecoveredModeTest.php` (novo), `tests/Feature/Inbox/QueueRoutingTest.php`, `tests/Feature/Inbox/ApplyAckTest.php`

**Interfaces:**
- Produz: `enum IngestMode: string { LIVE = 'live'; RECOVERED = 'recovered'; IMPORTED = 'imported'; }`
- Produz: `IncomingMessage` ganha `public IngestMode $mode = IngestMode::LIVE`, `public ?int $ack = null` e `withMode(IngestMode $mode): self`. `fromEvent` lê o modo de `$event->payload['mode']` (valor desconhecido = `LIVE`) e o ack de `payload.ack`.
- Produz: `DeliveryStatus::fromAck(mixed $code): ?self` (a tabela hoje em `ApplyAck::statusFor`: -1 `FAILED`, 1 `SENT`, 2 `DELIVERED`, 3 e 4 `READ`, resto `null`); `ApplyAck` passa a chamá-lo.
- Produz: `ResolveMessageMediaJob::__construct(int $messageId, string $queue = 'inbound')`.
- Produz: `BotEntry::isFresh(CarbonInterface $sentAt): bool` (regra de 300 s, hoje inline em `shouldStart`).

- [ ] **Passo 1: testes que falham** em `RecoveredModeTest` (use o helper de evento de `IngestMessageTest`, com `payload['mode'] = 'recovered'`):
  - `test_recuperada_em_ticket_pendente_nao_avanca_o_bot`: canal com workflow, ticket `pendente` com sessão de bot, mensagem com `timestamp` de 2 h atrás → `Bus::assertNotDispatched(AdvanceBotSessionJob::class)`.
  - `test_recuperada_sem_ticket_abre_aguardando_sem_som_e_sem_resposta_automatica`: ticket novo com status `AGUARDANDO`, `Event::assertNotDispatched(TicketQueued::class)`, nenhuma `Message` com origem `BOT`, `messages.sent_at` igual ao horário original.
  - `test_ao_vivo_continua_tocando_o_som_de_fila`: o mesmo caso com modo `live` dispara `TicketQueued`.
  - `test_recuperada_conta_a_espera_desde_a_hora_real`: `waiting_since` do ticket aberto = horário da mensagem; se existir saída posterior no ticket, `waiting_since` fica `null`.
  - `test_recuperada_do_celular_usa_o_ack`: `fromMe` com `ack = 3` → `delivery_status` `READ`; sem ack → `SENT`.
  - `test_midia_recuperada_vai_para_a_fila_history`: `Queue::assertPushedOn('history', ResolveMessageMediaJob::class)`; a mesma mensagem ao vivo vai para `inbound`.
  - Em `QueueRoutingTest`: `'download de midia em recuperacao' => [new ResolveMessageMediaJob(1, 'history'), 'history']`.
- [ ] **Passo 2: ver falhar** `php artisan test tests/Feature/Inbox/RecoveredModeTest.php` → FAIL.
- [ ] **Passo 3: implementar.** `IngestMessage`: `openTicketFor` só chama `AlertDispatcher::queued` quando `$incoming->mode === IngestMode::LIVE`; `store()` grava `delivery_status` de `fromMe` como `DeliveryStatus::fromAck($incoming->ack) ?? SENT`; a mídia é despachada com `$incoming->mode === LIVE ? 'inbound' : 'history'`. `BotEntry::handle` passa a sair sem fazer nada quando `! isFresh($incoming->sent_at)`. `ApplyAck::statusFor` é removido a favor de `DeliveryStatus::fromAck`.
- [ ] **Passo 4: ver passar** o arquivo novo, `ApplyAckTest`, `QueueRoutingTest`, `IngestMessageTest`, `AfterHoursReplyTest` e `tests/Feature/Bot` inteiros → PASS (a regra de frescor no `handle` não pode quebrar testes antigos; se algum usa mensagem com `sent_at` antigo, ajuste o `sent_at` do teste, não a regra).
- [ ] **Passo 5: commit** `feat: modo recuperado na ingestão, ack e fila da mídia`.

---

### Task 3: recuperação automática após queda

**Arquivos:**
- Criar: `database/migrations/2026_11_03_000001_add_down_since_to_channels.php`, `app/Services/Inbox/RecoverMessages.php`, `app/Services/Inbox/RecoveryResult.php`, `app/Jobs/RecoverMessagesJob.php`, `app/Notifications/MessagesRecovered.php`
- Modificar: `app/Models/Channel.php`, `app/Observers/ChannelObserver.php`, `app/Livewire/Alerts/Bell.php`, `docker/worker.sh`, `tests/Feature/Inbox/QueueRoutingTest.php`
- Testar: `tests/Feature/Recovery/RecoverMessagesTest.php`, `tests/Feature/Recovery/DownSinceTest.php`, `tests/Feature/Recovery/RecoverMessagesJobTest.php` (novos)

**Interfaces:**
- Consome: `WahaClient::messages`, `IngestMode`, `IngestMessage::__invoke(WahaEvent): ?Message`.
- Produz: `final readonly class RecoveryResult { public function __construct(public int $messages, public int $conversations) {} }`
- Produz: `RecoverMessages::__invoke(Channel $channel, CarbonInterface $from, CarbonInterface $to): RecoveryResult`. Limita `$from` a `$to − 7 dias`. Cada item vira `WahaEvent::firstOrCreate(['waha_event_id' => "recovered:{canal}:".Message::waKey($data['id'])], [... 'event' => 'message', 'payload' => ['payload' => $data, 'mode' => 'recovered'], 'received_at' => now()])`, é ingerido numa tentativa/captura por mensagem (`Log::warning` e segue) e conta só `wasRecentlyCreated`; `conversations` = tickets distintos tocados.
- Produz: `RecoverMessagesJob::__construct(public int $channelId, public CarbonImmutable $from, public CarbonImmutable $to, public bool $notifyAlways = false, public ?CarbonImmutable $downSince = null)`; implementa `ShouldQueue` e `ShouldBeUnique` (`uniqueId()` = id do canal); fila `history`; `$tries = 1`; `$timeout = 840`; middleware `WithoutOverlapping("history:{canal}")` (`releaseAfter(60)`, `expireAfter(900)`).
- Produz: `channels.down_since` (timestamp nulo; cast `datetime`, em `$fillable`, `@property`).
- Produz: `MessagesRecovered(Channel $channel, int $messages, int $conversations, string $state = 'recovered')` → `['kind' => 'recovery', 'level' => 'info', 'state' => $state, 'channel_id', 'channel_name', 'messages', 'conversations']`.

- [ ] **Passo 1: testes que falham.**
  - `RecoverMessagesTest` (`Http::fake` com 2 chats): `test_cria_mensagens_e_tickets_da_janela`; **`test_rodar_duas_vezes_nao_cria_nada_novo`** (contagens de `Message`, `Ticket` e `WahaEvent` iguais; segundo `RecoveryResult->messages === 0`); `test_mensagem_que_falha_nao_derruba_as_outras` (uma com payload inválido); `test_janela_maior_que_7_dias_e_cortada` (a requisição leva `filter.timestamp.gte` de 7 dias); **`test_eco_de_mensagem_do_painel_nao_duplica`** (linha `PAINEL` sem `wa_message_id`, mesma `type`/`body`, e a recuperação traz o eco `fromMe` → uma só linha, com `wa_message_id` preenchido).
  - `DownSinceTest`: `test_sair_de_working_grava_down_since`; `test_queda_menor_que_5_min_nao_agenda` e limpa `down_since`; `test_queda_de_5_min_agenda_job_com_janela_e_atraso` (`Bus::assertDispatched(RecoverMessagesJob::class, fn ($j) => $j->channelId === $c->id && $j->from->equalTo($since->subMinutes(10)))` e atraso de 60 s); `test_oscilacao_preserva_o_primeiro_down_since` (WORKING→STARTING→SCAN_QR_CODE→WORKING); `test_canal_desativado_nao_agenda`.
  - `RecoverMessagesJobTest`: **`test_numero_trocado_durante_a_queda_nao_recupera`** (`phone_changed_at >= downSince` → o serviço não é chamado); `test_canal_fora_de_working_ou_inativo_nao_recupera`; `test_avisa_quando_recuperou_algo`; `test_automatica_sem_nada_novo_nao_avisa`; `test_manual_sem_nada_novo_avisa` (`notifyAlways`); `test_e_unico_por_canal`.
  - `QueueRoutingTest`: `'recuperacao' => [new RecoverMessagesJob(1, now()->toImmutable(), now()->toImmutable()), 'history']`; o teste do worker passa a exigir a fila `history`.
- [ ] **Passo 2: ver falhar** `php artisan test tests/Feature/Recovery tests/Feature/Inbox/QueueRoutingTest.php` → FAIL.
- [ ] **Passo 3: implementar.** Migration + `Channel`. `ChannelObserver::updated`: ao sair de `WORKING` sem `down_since`, `forceFill(['down_since' => now()])->saveQuietly()`; ao voltar a `WORKING` com `down_since` preenchido, limpar (`saveQuietly`) e, se o canal está `active` e passaram **5 min ou mais**, `RecoverMessagesJob::dispatch($id, $since->subMinutes(10), now(), false, $since)->delay(60)`. O job sai sem fazer nada se o canal sumiu, está inativo ou fora de `WORKING`, ou se `$downSince !== null && $channel->phone_changed_at >= $downSince`. `Bell::present` ganha o braço `'recovery'`: ícone `inbox-arrow-down`, tom `accent`, título "Mensagens recuperadas" (`state = imported` → "Histórico importado"), detalhe "{canal}: N mensagens em M conversas". Confirmar em `resources/js/alerts.js` e `alerts-rules.js` que `kind` desconhecido não toca som; se tocar, acrescentar `recovery` à lista de silenciosos, com teste em `tests/js`.
- [ ] **Passo 4: worker.** Em `docker/worker.sh`, `start()` passa a usar `--timeout="${TIMEOUT:-120}"`; acrescentar `TIMEOUT=900 start --queue=history --tries=1` e documentar a fila no comentário do topo.
- [ ] **Passo 5: ver passar** os mesmos testes → PASS.
- [ ] **Passo 6: commit** `feat: recuperação automática de mensagens após queda do canal`.

---

### Task 4: botão manual e varredura diária

**Arquivos:**
- Criar: `app/Console/Commands/RecoverMessagesCommand.php`
- Modificar: `app/Livewire/Settings/ChannelCard.php`, `resources/views/livewire/settings/channel-card.blade.php`, `routes/console.php`
- Testar: `tests/Feature/Livewire/RecoverMessagesCardTest.php`, `tests/Feature/Console/RecoverMessagesCommandTest.php` (novos)

**Interfaces:**
- Consome: `RecoverMessagesJob` (Tarefa 3).
- Produz: `ChannelCard::$recoverHours = 24` e `ChannelCard::recover(): void` (valida `Rule::in([1, 6, 24, 72])`; só para canal ativo e `isConnected()`; despacha `RecoverMessagesJob::dispatch($id, now()->subHours($h)->toImmutable(), now()->toImmutable(), notifyAlways: true)` e mostra "Recuperação iniciada. O resultado aparece no sino.").
- Produz: comando `app:recover-messages` (um job de 24 h por canal ativo e `WORKING`).

- [ ] **Passo 1: testes que falham.** Card: `test_recuperar_despacha_o_job_com_notify_always`; `test_janela_invalida_e_recusada` (5 h); `test_canal_desconectado_nao_mostra_nem_despacha` (`assertDontSee('Recuperar mensagens')`). Comando: `test_despacha_so_para_canais_ativos_e_conectados`; `test_esta_agendado_para_as_0415` (siga `PurgeMediaTest::test_esta_agendado_para_rodar_todo_dia`).
- [ ] **Passo 2: ver falhar** os dois arquivos → FAIL.
- [ ] **Passo 3: implementar** o método, o seletor (`flux:select` 1 h/6 h/24 h/72 h) e o botão "Recuperar mensagens" na faixa de ações do card, o comando e `Schedule::command('app:recover-messages')->dailyAt('04:15')->withoutOverlapping(10)` em `routes/console.php`.
- [ ] **Passo 4: ver passar** → PASS.
- [ ] **Passo 5: commit** `feat: recuperação manual no card do canal e varredura diária`.

---

### Task 5: reações, edições e mensagens apagadas

**Arquivos:**
- Criar: `database/migrations/2026_11_03_000002_create_message_reactions_table.php`, `database/migrations/2026_11_03_000003_add_edit_and_revoke_to_messages.php`, `app/Models/MessageReaction.php`, `app/Services/Inbox/ApplyMessageChange.php`
- Modificar: `app/Models/Message.php`, `app/Jobs/HandleWahaEvent.php`, `app/Services/Waha/WahaClient.php` (`sessionConfig`), `app/Livewire/Inbox/Transcript.php`, `resources/views/livewire/inbox/transcript.blade.php`, `app/Livewire/Inbox/Inbox.php`
- Testar: `tests/Feature/Inbox/MessageChangesTest.php` (novo), `tests/Feature/Services/WahaClientTest.php`, `tests/Feature/Jobs/HandleWahaEventTest.php`

**Interfaces:**
- Produz: tabela `message_reactions` (`id`, `message_id` FK com cascata, `by` string 10 = `contact`|`me`, `emoji` string 32, `reacted_at` timestamp, timestamps; único em `(message_id, by)`); colunas nulas `messages.edited_at` e `messages.revoked_at` (casts `datetime`).
- Produz: `Message::reactions(): HasMany`; `MessageReaction` com `message()`.
- Produz: `ApplyMessageChange` com `reaction(WahaEvent): bool`, `edited(WahaEvent): bool`, `revoked(WahaEvent): bool` — `false` só quando a mensagem de destino ainda não existe (o job tenta de novo, como no ack); `true` nos demais casos. A mensagem de destino é achada por `channel_id` + `Message::waKey(<id do evento>)`.

- [ ] **Passo 1: confirmar o formato.** Ler os DTOs do WAHA (`gh api repos/devlikeapro/waha/contents/src/structures/webhooks.dto.ts` e `responses.dto.ts`, classes de `message.reaction`, `message.edited`, `message.revoked`) e fixar os caminhos exatos no topo de `MessageChangesTest` (o spec dá: `payload.reaction.text`, `payload.reaction.messageId`, `payload.fromMe`; `editedMessageId`, `body`; `revokedMessageId`).
- [ ] **Passo 2: testes que falham** em `MessageChangesTest`:
  - `test_reacao_cria_e_troca_a_do_mesmo_autor` (uma linha por mensagem e autor); `test_texto_vazio_remove_a_reacao`.
  - **`test_reacao_nao_mexe_na_conversa`**: `tickets.updated_at`, `last_inbound_at`, `waiting_since` e o número de mensagens ficam iguais; nenhum `TicketEvent` novo.
  - `test_edicao_atualiza_corpo_e_edited_at`; `test_apagada_grava_revoked_at_e_mantem_o_corpo`.
  - **`test_destino_ausente_pede_nova_tentativa`**: `ApplyMessageChange::reaction` devolve `false`; em `HandleWahaEventTest`, o job chama `release(5)` até 6 tentativas e depois descarta com `Log::warning`.
  - **`test_reacao_em_message_any_nao_vira_mensagem`**: evento `message.any` com `payload.reaction` ou `type = reaction` → `IngestMessage` devolve `null` e nada é gravado (corrigir `IncomingMessage::content` se falhar).
  - `WahaClientTest`: a configuração enviada inclui `message.edited`.
  - Tela (`Livewire::test(Transcript::class)`): vê "apagada pelo cliente" **e** o texto original; vê "editada"; vê o emoji da reação.
- [ ] **Passo 3: ver falhar** os três arquivos → FAIL.
- [ ] **Passo 4: implementar.** Migrations e modelos. `HandleWahaEvent`: três braços novos no `match`, partilhando com `applyAck` um método privado de tentativa (`retryUnlessDone(bool $done, string $label)`). Cada tratador termina com `MessageStatusChanged::dispatch($message)` para a tela se atualizar. `Transcript`/`Inbox` carregam `reactions` junto das mensagens (sem N+1). Na view: marca "apagada pelo cliente" ao lado do texto (que continua visível), "editada" e os emojis sob a bolha.
- [ ] **Passo 5: ver passar** os mesmos arquivos e `tests/Feature/Inbox` + `tests/Feature/Webhooks` inteiros → PASS.
- [ ] **Passo 6: commit** `feat: reações, edições e mensagens apagadas`.

---

### Task 6: importação inicial

**Arquivos:**
- Criar: `app/Services/Inbox/ImportHistory.php`, `app/Jobs/ImportHistoryJob.php`
- Modificar: `app/Enums/ClosedReason.php`, `app/Services/Inbox/IngestMessage.php`, `app/Observers/ChannelObserver.php`, `app/Livewire/Settings/ChannelCard.php`, `resources/views/livewire/settings/channel-card.blade.php`, `app/Services/Reports/AttendanceReport.php`, `app/Livewire/Inbox/Inbox.php`
- Testar: `tests/Feature/Recovery/ImportHistoryTest.php`, `tests/Feature/Recovery/ImportTriggerTest.php` (novos), `tests/Feature/Livewire/ChannelsScreenTest.php`, `tests/Feature/Reports/*`

**Interfaces:**
- Consome: `WahaClient::messages`, `IngestMode::IMPORTED`, `IncomingMessage::withMode`, `MessagesRecovered(state: 'imported')`.
- Produz: `ClosedReason::IMPORTADO = 'importado'` (rótulo "Histórico importado").
- Produz: `ImportHistory::__invoke(Channel $channel): RecoveryResult` (`messages` = importadas; `conversations` = chats; a contagem dos que ficaram abertos vai em `ImportHistory::$open` público e legível após a chamada).
- Produz: `ImportHistoryJob::__construct(public int $channelId)`; fila `history`; `ShouldBeUnique`; mesmo `WithoutOverlapping("history:{canal}")` da Tarefa 3; `failed()` volta `backfill_last_run_at` para `null`.

- [ ] **Passo 1: testes que falham.**
  - `ImportHistoryTest` (`Http::fake` com 4 chats): `test_chat_respondido_vira_ticket_encerrado_de_importacao` (status `ENCERRADO`, `closed_reason = importado`, `opened_at`/`closed_at` = primeira/última mensagem, `waiting_since` nulo, contato criado com nome e telefone, **um** ticket por chat); `test_cliente_sem_resposta_ate_48h_abre_ticket_aguardando` (todas as mensagens do chat no mesmo ticket aberto, `waiting_since` preenchido, sem bot); `test_cliente_sem_resposta_ha_mais_de_48h_entra_encerrado`; `test_importada_nao_dispara_som_nem_tempo_real_nem_eventos_de_ticket` (`Event::assertNotDispatched(TicketQueued|MessageCreated|TicketUpdated)`, nenhum `TicketEvent` de mensagem); **`test_contato_com_ticket_aberto_recebe_as_novas_nele`** (mensagem com `sentAt >= opened_at` do ticket aberto segue o caminho normal; as anteriores vão para o ticket de importação); `test_importar_de_novo_nao_cria_nada` (contagens iguais); `test_midia_importada_vai_para_a_fila_history`; `test_resumo_no_sino_conta_conversas_e_abertas`.
  - `ImportTriggerTest`: `test_primeira_vez_em_working_com_backfill_dispara_uma_vez` (e grava `backfill_last_run_at` na hora; uma oscilação logo depois não dispara outra); `test_sem_backfill_days_nao_dispara`; `test_job_que_falha_libera_nova_tentativa`; **`test_canal_inativo_ou_fora_de_working_nao_importa`**.
  - `ChannelsScreenTest`: `backfillDays` acima de 30 é recusado, com mensagem "No máximo 30 dias."; o campo na tela tem `max="30"`.
  - Relatórios/caixa: `test_ticket_importado_nao_entra_no_relatorio_de_atendimento` (`AttendanceReport::for` não o conta em `opened`, `by_sector` nem `by_agent`); `test_ticket_importado_nao_aparece_na_aba_encerrados` (`Inbox::recentlyClosed`). Rodar `grep -rn "ENCERRADO\|closed_at\|closed_reason" app` e cobrir com teste qualquer outra consulta que **conte atendimento** (histórico e ficha do contato devem **continuar** mostrando o importado).
- [ ] **Passo 2: ver falhar** → FAIL.
- [ ] **Passo 3: implementar.** `IngestMessage`: em `IMPORTED`, `ticketFor` usa `importedTicketFor` (trava `pg_advisory_xact_lock` por contato e canal, como `openTicketFor`; acha ou cria o ticket encerrado do contato e canal com `closed_reason = importado`, ajustando `opened_at` para o menor e `closed_at` para o maior horário), `store()` não chama `recordInbound`/`recordOutbound`, não cria `TicketEvent` de mensagem, não dispara `MessageCreated`/`TicketUpdated` e não roda os ganchos de bot e fora do horário; e, logo após resolver o contato, se há ticket aberto dele no canal com `opened_at <= sentAt`, trata a mensagem como `withMode(RECOVERED)`. `ImportHistory`: janela dos últimos `min(backfill_days, 30)` dias; agrupa as mensagens por chat (`IncomingMessage::peer`, montando o `IncomingMessage` de um `WahaEvent` não salvo); o chat é "aberto" quando a última mensagem é do cliente e tem até 48 h (mensagens com `mode = recovered`), senão `imported`; mesmo `waha_event_id` sintético `imported:{canal}:{id}`. `ChannelObserver`: ao ir a `WORKING` com `backfill_days > 0` e `backfill_last_run_at` nulo, grava `backfill_last_run_at = now()` (`saveQuietly`) e despacha `ImportHistoryJob` com atraso de 60 s. `AttendanceReport`: `$period` ganha `->where(fn ($q) => $q->whereNull('tickets.closed_reason')->orWhere('tickets.closed_reason', '<>', ClosedReason::IMPORTADO->value))`. `Inbox::recentlyClosed` exclui o importado. `ChannelCard`: validação `max:30` e mensagem em português.
- [ ] **Passo 4: ver passar** os arquivos acima e `tests/Feature/Reports` e `tests/Feature/History` inteiros → PASS.
- [ ] **Passo 5: commit** `feat: importação inicial do histórico como tickets encerrados`.

---

### Task 7: documentação e verificação final

**Arquivos:**
- Modificar: `docs/superpowers/specs/waha-config.md` (o parágrafo "Mensagem que chega com o WAHA fora do ar" passa a descrever a recuperação implementada), `docs/deploy.md` (fila `history` e redeploy do `worker-beta`; reaplicar a configuração do webhook nos canais conectados para assinar `message.edited`: o `Connect` do card faz `PUT` e causa uma queda de cerca de 1 s; `backfill_days` máximo de 30)

- [ ] **Passo 1:** atualizar os dois documentos.
- [ ] **Passo 2:** `php artisan test --parallel` (Postgres de teste no ar) → toda a suíte verde; ler a saída.
- [ ] **Passo 3:** `vendor/bin/pint --test`, Larastan e PHPMD (`composer test` roda os três) → sem erros; ler a saída.
- [ ] **Passo 4:** no beta, após o deploy e o redeploy do `worker-beta`: reaplicar a configuração do webhook no canal conectado (botão de conectar do card) para assinar `message.edited`; abrir o card do canal, rodar "Recuperar mensagens" com 1 h e conferir o aviso no sino e a ausência de duplicatas ao rodar de novo.
- [ ] **Passo 5: commit** `docs: recuperação, fila history e deploy`.
