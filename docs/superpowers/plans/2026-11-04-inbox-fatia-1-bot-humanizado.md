# Fatia 1: bot humanizado, presença do humano, frescor e prévia — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O bot fala com o mesmo ritmo do humano (visto uma vez por rodada, "digitando" com teto de 3 s, mesma fila `outbound`), o atendente que digita de verdade aparece digitando ao cliente sem ser atropelado pelo bot, o bot não é derrubado por atraso de fila nem por eco `@lid`, e a prévia da fila mostra a formatação do WhatsApp.

**Architecture:** Tudo passa por `Presence` (um só caminho para bot e humano). Duas marcas de cache dizem quem está ativo: `presence-human:{canal}:{chat}` (o bot não manda `typing`/`paused` nessa conversa) e `presence-human:{canal}` (o `offline` do canal espera). O navegador limita o `typing` do campo (throttle no início, debounce no fim); o servidor só protege com teto.

**Tech Stack:** Laravel 13, Livewire 4, Flux, Reverb, PostgreSQL 18, Redis (cache), WAHA 2026.9.2 (GOWS), Pest/PHPUnit (`php artisan test`).

**Spec:** `docs/superpowers/specs/2026-11-03-inbox-completa-design.md` (seção 5, fatia 1; decisões de 04/11 no topo).

## Global Constraints

- Textos de tela e mensagens em português; commits em português com prefixo (`feat:`, `fix:`), um commit por tarefa.
- Branch da fatia: `feat/inbox-bot-humanizado`, criada a partir de `feat/inbox-completa`. Nunca a `main`. Sem PR até o dono pedir.
- "Digitando" do bot: teto de **3000 ms** (humano segue 1000 a 6000 ms em `TypingDelay`).
- Falha de presença nunca impede o envio (`Presence::attempt`). Sem novo worker; a fila continua `outbound`.
- Sequência do WAHA (spec): `online` → `sendSeen` → `startTyping` → espera → `stopTyping` → `sendText` → `offline` agendado (45 s).
- Nada de presença gravada no banco; só cache.
- Testes: Postgres em 127.0.0.1:5432 (`docker run -d --name pg-test -e POSTGRES_USER=dimetal -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=dimetal_test -p 5432:5432 postgres:18-alpine`), `QUEUE_CONNECTION=database`, `php artisan test --parallel`. Fim: `composer test` (Pint, Larastan, PHPMD, testes).
- Busca de código do app só por `codegraph_explore`; o índice não cobre Blade: para achar view, uma 2ª chamada com outros nomes e, só então, `Grep` em `resources/`, avisando o dono.

## Review Focus

1. Bot e humano falando no mesmo chat ao mesmo tempo: o `paused`/`offline` do bot não pode apagar o "gravando" nem o "digitando" do atendente (Task 3).
2. Dois `SendTicketMessageJob` do bot em sequência (lote): só o primeiro manda visto (Task 2).
3. Cliente `@lid` cujo eco `fromMe` do bot não casa pelo contato: o ticket não pode sair do bot (Task 5).
4. Chat de tipo desconhecido (`@newsletter`, `@broadcast`) e dois números do mesmo cliente: nenhuma mensagem some nem muda de ticket (Task 7).
5. Texto da prévia com `<script>` ou `*` sem fechar: sai escapado e sem HTML cru (Task 6).

---

### Task 1: Frescor do bot mede a chegada do webhook, não o relógio do job

**Files:**
- Modify: `app/Services/Bot/BotEntry.php` (`shouldStart`, `isFresh`), `app/Services/Inbox/IngestMessage.php` (chamada de `shouldStart`; ler `openTicketFor` antes)
- Test: `tests/Feature/Bot/IngestIntegrationTest.php`

**Interfaces:**
- Produces: `BotEntry::shouldStart(Channel $channel, CarbonInterface $freshAt, CarbonInterface $arrivedAt): bool` e `BotEntry::isFresh(CarbonInterface $freshAt, CarbonInterface $arrivedAt): bool` (fresco = `abs($arrivedAt - $freshAt) <= 300 s`). Quem chama passa `$event->received_at` em `$arrivedAt`.

- [ ] **Step 1: Teste** `test_fila_inbound_atrasada_nao_derruba_o_bot` em `IngestIntegrationTest`: canal com workflow publicado; evento ao vivo com `received_at = now()`; `$this->travel(10)->minutes()` antes de rodar `HandleWahaEvent`; afirma ticket `pendente` e `BotSession` criada. Segundo teste `test_mensagem_recuperada_antiga_continua_sem_bot` com `mode=recovered` e `sentAt` de 1 h atrás: ticket `aguardando`, sem sessão.
- [ ] **Step 2:** `php artisan test --filter=IngestIntegrationTest` — o primeiro falha (ticket sai `aguardando`).
- [ ] **Step 3:** Trocar `now()` por `$arrivedAt` em `isFresh`; repassar o parâmetro em `shouldStart`; atualizar todas as chamadas (o explore lista os callers) passando `$event->received_at`.
- [ ] **Step 4:** Rodar `--filter=Bot` e `--filter=Inbox`: verdes.
- [ ] **Step 5:** `git commit -m "fix: frescor do bot mede a chegada do webhook, não o atraso da fila"`

### Task 2: Bot usa a presença do humano, com visto uma vez por rodada e teto de 3 s

**Files:**
- Modify: `app/Services/Inbox/Presence.php`, `app/Services/Inbox/TypingDelay.php`, `app/Services/Inbox/SendTicketMessage.php:98-102`, `app/Services/Waha/WahaClient.php` (`sendSeen` ganha timeout), `app/Services/Bot/BotEngine.php:139` (comentário do `say`)
- Test: `tests/Feature/Inbox/PresenceSendTest.php`, `tests/Unit/TypingDelayTest.php`

**Interfaces:**
- Produces: `TypingDelay::milliseconds(string $text, int $max = 6000): int`; `Presence::before(WahaClient $client, Ticket $ticket, Contact $contact, string $text, bool $typing = true, MessageOrigin $origin = MessageOrigin::PAINEL): void`; `WahaClient::sendSeen(string $chatId, array $ids, ?int $timeoutSeconds = null): void` (visto do bot com 3 s).
- Regra de "rodada": `Cache::add("presence-seen:{ticketId}", 1, 30)` — só quem consegue criar a chave manda o visto do bot. Escolha minha (rodada = mensagens do bot no mesmo ticket em 30 s).

- [ ] **Step 1: Testes** (padrão do `PresenceSendTest`, `Http::fake`): `test_bot_manda_online_visto_e_digitando_antes_do_texto`; `test_bot_em_lote_manda_visto_so_na_primeira` (duas mensagens `BOT` no mesmo ticket → 1 chamada `sendSeen`); `test_digitando_do_bot_passa_de_3_segundos_nunca` em `TypingDelayTest` (`milliseconds(str_repeat('a', 500), 3000) <= 3000` em 50 sorteios); `test_falha_no_visto_do_bot_nao_impede_o_envio` (visto responde 500, `sendText` sai).
- [ ] **Step 2:** Rodar `--filter=PresenceSendTest` e `--filter=TypingDelayTest`: falham.
- [ ] **Step 3:** Em `deliver`, remover o `if (origin !== BOT)` e passar `$message->origin` a `Presence::before`. Em `before`: `setPresence('online')` primeiro (dentro de `attempt`), visto (para BOT, só se `Cache::add` passar, com timeout 3 s), digitando com `TypingDelay::milliseconds($text, $origin === BOT ? 3000 : 6000)`. Áudio continua sem "digitando".
- [ ] **Step 4:** Rodar `--filter=Inbox` e `--filter=Bot`: verdes (ajustar `BotEngine` testes que contavam zero chamadas de presença).
- [ ] **Step 5:** `git commit -m "feat: bot usa visto e digitando do WhatsApp com teto de 3 s"`

### Task 3: A presença do bot não atropela a do humano

**Files:**
- Modify: `app/Services/Inbox/Presence.php`, `app/Livewire/Inbox/Concerns/ComposesMessages.php:200-229` (`recording`)
- Test: `tests/Feature/Inbox/PresenceSendTest.php`

**Interfaces:**
- Consumes: Task 2.
- Produces: `Presence::humanActive(Channel $channel, string $chatId, int $seconds = 10): void` (grava `presence-human:{canal}:{chat}` e `presence-human:{canal}`, e **troca o token** do `offline` pendente); `Presence::isHumanActive(Channel $channel, ?string $chatId = null): bool`.

- [ ] **Step 1: Testes:** `test_bot_nao_manda_typing_nem_paused_com_humano_ativo_na_conversa`; `test_offline_pendente_do_bot_nao_derruba_gravacao_do_atendente` (bot envia → `scheduleOffline`; atendente `recording(true)`; `GoOfflineJob` roda → nenhuma chamada `offline`); `test_offline_sai_quando_nenhum_humano_esta_ativo`.
- [ ] **Step 2:** Rodar `--filter=PresenceSendTest`: falham.
- [ ] **Step 3:** `recording(on=true)` e `Presence::before` com origem `PAINEL` chamam `humanActive`. Em `before` com origem `BOT`, pular `startTyping`/`stopTyping` se `isHumanActive($channel, $chatId)`. Em `goOffline`, se `isHumanActive($channel)`, reagendar com `scheduleOffline` em vez de enviar `offline`.
- [ ] **Step 4:** Rodar `--filter=Inbox`: verdes.
- [ ] **Step 5:** `git commit -m "fix: presença do bot não apaga o gravando e o digitando do atendente"`

### Task 4: Atendente que digita aparece digitando (throttle no navegador)

**Files:**
- Modify: `app/Livewire/Inbox/Concerns/ComposesMessages.php`, `app/Services/Inbox/Presence.php`, a view Blade do campo de resposta (achar com `codegraph_explore "draft textarea composer wire:model recording"`), `app/Services/Inbox/SendTicketMessage.php` (pular espera artificial quando o humano digitou)
- Test: `tests/Feature/Livewire/InboxTypingTest.php` (novo, no padrão de `InboxVoiceTest`)

**Interfaces:**
- Consumes: Task 3 (`humanActive`).
- Produces: `Presence::typing(WahaClient $client, string $chatId, bool $on): void` (`composing` / `paused`); Livewire `ComposesMessages::typing(bool $on, int $ticketId): void` (mesmas checagens e limite 20/min de `recording`; erro nunca volta ao navegador).
- Navegador: `typing(true)` no máximo **a cada 5 s** (throttle) enquanto há digitação; `typing(false)` após **3 s** sem digitar (debounce) e imediato ao enviar. Valores provisórios até a medição da fatia 2.

- [ ] **Step 1: Testes:** `test_digitar_manda_online_e_composing_ao_cliente`; `test_parar_de_digitar_manda_paused_e_agenda_offline`; `test_limite_de_20_por_minuto_nao_devolve_erro`; `test_ticket_sem_permissao_de_responder_nao_manda_presenca`; em `PresenceSendTest`: `test_envio_depois_de_digitar_de_verdade_nao_repete_a_espera_artificial` (com a marca `presence-human` ativa, `deliver` manda só `paused` + `sendText`).
- [ ] **Step 2:** Rodar `--filter=InboxTypingTest`: falha (método não existe).
- [ ] **Step 3:** Implementar `typing` espelhando `recording`; no `deliver` de origem `PAINEL`, se `isHumanActive($channel, $chatId)`, não chamar `startTyping` nem dormir. No Blade, `x-on:input` com throttle de 5 s chamando `$wire.typing(true, ticketId)` e temporizador de 3 s para `typing(false)`; limpar ao enviar e ao trocar de ticket (o `paused` ainda chega ao ticket certo, como em `recording`).
- [ ] **Step 4:** Rodar `--filter=InboxTypingTest`, `--filter=PresenceSendTest`: verdes. Conferir no navegador do beta digitando e enviando (descrever o resultado ao dono).
- [ ] **Step 5:** `git commit -m "feat: cliente vê o atendente digitando, com throttle no navegador"`

### Task 5: Eco do bot, inclusive `@lid`, não tira o ticket do bot

**Files:**
- Modify (se o teste falhar): `app/Services/Inbox/IngestMessage.php` (`matchPending`, `ticketFor`)
- Test: `tests/Feature/Bot/IngestIntegrationTest.php`

**Interfaces:** Produz nada novo; o contrato é: eco `fromMe` cujo corpo casa com uma mensagem `BOT` ainda sem `wa_message_id` do **mesmo ticket** é adotado, mesmo quando o contato do eco é o `@lid` e a linha está no contato do telefone.

- [ ] **Step 1: Teste** `test_eco_lid_do_bot_chegando_durante_o_digitando_nao_chama_human_took_over`: ticket `pendente` com sessão ativa e mensagem `BOT` sem `wa_message_id` no contato do telefone; eco `fromMe` chega com chat `@lid`; afirma ticket ainda `pendente`, sessão `ACTIVE`, nenhuma linha `FORA_DO_PAINEL`.
- [ ] **Step 2:** Rodar. Se **passar**, é regressão pura: commitar só o teste. Se falhar, seguir `superpowers:systematic-debugging` (causa raiz em `matchPending` por `contact_id`) antes do Step 3.
- [ ] **Step 3:** Corrigir na causa: casar o `BOT` pendente pelo ticket aberto do canal e corpo igual quando o contato do eco é `@lid` do mesmo cliente.
- [ ] **Step 4:** Rodar `--filter=IngestIntegrationTest`, `--filter=MessageChangesTest`: verdes.
- [ ] **Step 5:** `git commit -m "fix: eco @lid do bot não passa o ticket para humano"`

### Task 6: Prévia da fila com a formatação do WhatsApp

**Files:**
- Modify: a view da linha do ticket na fila (achar com `codegraph_explore "WhatsAppText transcript formatação"` e a linha da fila); reaproveitar o `WhatsAppText` que o transcript já usa
- Test: `tests/Feature/Livewire/InboxQueuePreviewTest.php` (novo)

**Interfaces:**
- Consumes: o conversor `WhatsAppText` do transcript (assinatura a confirmar no explore; a prévia chama o mesmo método).

- [ ] **Step 1: Testes:** `test_previa_da_fila_mostra_negrito_e_italico` (`*oi* _tudo_` → `<strong>oi</strong>` e `<em>tudo</em>`); `test_previa_escapa_html` (`<script>alert(1)</script>` sai escapado); `test_asterisco_sem_fechar_fica_literal`.
- [ ] **Step 2:** Rodar: falham (a prévia sai texto cru).
- [ ] **Step 3:** Trocar o texto cru pelo conversor, mantendo o corte de tamanho antes de converter (não cortar no meio de uma tag).
- [ ] **Step 4:** Rodar `--filter=InboxQueuePreviewTest` e `--filter=Livewire`: verdes.
- [ ] **Step 5:** `git commit -m "feat: prévia da fila com a formatação do WhatsApp"`

### Task 7: Regressões de dois números e chat de tipo desconhecido

**Files:**
- Test: `tests/Feature/Inbox/IngestMultiChannelTest.php` (novo)
- Modify (só se falhar): `app/Services/Inbox/IngestMessage.php`, `app/Services/Inbox/ResolveContact.php`

- [ ] **Step 1: Testes:** `test_mesmo_cliente_em_dois_canais_tem_dois_tickets_sem_misturar_mensagens`; `test_chat_newsletter_nao_cria_ticket_nem_contato`; `test_chat_broadcast_nao_cria_ticket_nem_contato` (payload com `from` terminando em `@newsletter` / `@broadcast`).
- [ ] **Step 2:** Rodar. Passando, é regressão e só o teste entra. Falhando, corrigir na causa (ignorar o chat desconhecido com `Log::info`, sem exceção, para o webhook não reprocessar).
- [ ] **Step 3:** `composer test` inteiro (Pint, Larastan, PHPMD, suíte) e ler a saída.
- [ ] **Step 4:** `git commit -m "test: regressões de dois números e chats de tipo desconhecido"`

---

## Self-Review (feito)

- **Cobertura da fatia 1 no spec:** visto uma vez por rodada e "digitando" (T2), falha mole e teto de tempo do visto (T2), resposta fora do horário no mesmo caminho (T2, via `deliver`), eco `@lid` (T5), frescor (T1), prévia (T6), regressões (T7), presença do humano e sequência WAHA (T3, T4). Resposta de fora do horário não ganha teste próprio: usa `deliver`; o teste de `AfterHoursReplyTest` existente precisa seguir verde.
- **Lacunas assumidas:** o conversor `WhatsAppText` e a view da fila e do campo não foram lidos (índice não cobre Blade); as Tasks 4 e 6 mandam localizá-los primeiro. `WahaClient::sendSeen` e `openTicketFor` não foram lidos por inteiro.
- **Escolhas minhas:** rodada = 30 s no mesmo ticket; marca "humano ativo" de 10 s; throttle 5 s e debounce 3 s; `online` explícito no início de cada ação.
