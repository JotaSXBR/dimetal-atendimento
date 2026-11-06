# Importação de histórico Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Importar o histórico em vários tickets encerrados por contato (pausa de 6 h), resolver o telefone de contatos `@lid` e mostrar na notificação o que foi ignorado.

**Architecture:** A regra do ticket sai do `IngestMessage` para a classe `ImportedTicket`. O `ImportHistory` classifica o que ignora e, após cada chat `@lid`, chama `ResolveLidPhone`, que consulta o WAHA (`WahaContacts::phoneForLid`) e funde via `ResolveContact`/`MergeLidContact`. Os motivos viajam em `RecoveryResult`, somam-se em cache no `ImportHistoryJob` e saem em `MessagesRecovered`/sino.

**Tech Stack:** Laravel 13, PHPUnit, PostgreSQL, Http fake.

**Spec:** `docs/superpowers/specs/2026-11-06-importacao-historico-design.md`

## Global Constraints

- Pausa que abre ticket novo: `6 h` (constante `ImportedTicket::GAP_HOURS = 6`). Teto de 30 dias e 15000 mensagens: inalterados.
- Mensagem importada não abre espera, não gera `TicketEvent` nem evento em tempo real.
- Falha do WAHA em `/lids` nunca derruba a importação.
- `IngestMessage` não pode crescer; Pint, Larastan e PHPMD limpos. Commits em português com prefixo.

## Review Focus

- Mensagem exatamente a 6 h do fim do ticket: fica no mesmo ticket (limite inclusivo).
- Mensagem fora de ordem que fecha o buraco entre dois tickets: os dois viram um.
- `/lids` devolve 404, erro ou `pn` vazio: contato segue como "Número oculto" e a importação termina.
- `@lid` cujo telefone já tem contato: fundido, sem perder tickets/mensagens.
- Reimportar o mesmo período: nada novo (idempotência).

---

### Task 1: `ImportedTicket` (vários tickets por pausa)

**Files:**
- Create: `app/Services/Inbox/ImportedTicket.php`
- Modify: `app/Services/Inbox/IngestMessage.php` (remover `importedTicketFor`; `ticketFor` chama a classe)
- Test: `tests/Feature/Recovery/ImportedTicketTest.php`

**Interfaces:**
- Produces: `ImportedTicket::__invoke(Channel $channel, Contact $contact, IncomingMessage $incoming): Ticket`; `public const GAP_HOURS = 6`.

- [ ] **Step 1: testes (via `ImportHistory`, como em `ImportHistoryTest`)**: `test_pausa_maior_que_6h_abre_outro_ticket` (msgs t, t+1h, t+8h → 2 tickets, datas `opened_at/closed_at` certas); `test_pausa_de_exatamente_6h_continua_no_mesmo_ticket`; `test_mensagem_fora_de_ordem_funde_dois_tickets` (ingerir t e t+8h, depois t+4h pelo `RecoverMessages::ingest(..., IngestMode::IMPORTED)` → 1 ticket com as 3 mensagens, `opened_at`=t, `closed_at`=t+8h); `test_reimportar_nao_cria_tickets`.
- [ ] **Step 2:** rodar `php artisan test --filter=ImportedTicketTest` → FAIL (um ticket só).
- [ ] **Step 3: implementar** a classe: lock `pg_advisory_xact_lock(hashtext('ticket:{contact}:{channel}'))` (mesmo do `IngestMessage`); busca tickets `closed_reason=importado` do contato/canal com `opened_at <= sentAt+GAP` e `closed_at >= sentAt-GAP`, ordenados por `opened_at`; nenhum → `Ticket::create` (encerrado, `closed_reason` IMPORTADO, `opened_at=closed_at=sentAt`); um ou mais → o primeiro sobrevive, `Message::where('ticket_id', outros)->update(['ticket_id'=>primeiro])`, apaga os outros, estende `opened_at` (min) e `closed_at` (max) com `sentAt`. Em `IngestMessage::ticketFor` trocar a chamada por `app(ImportedTicket::class)(...)` e apagar o método e imports sem uso.
- [ ] **Step 4:** testes do passo 1 + `tests/Feature/Recovery` + `tests/Feature/Inbox/IngestMessageTest.php` → PASS.
- [ ] **Step 5:** `git commit -m "feat: importação divide o histórico em tickets por pausa de 6 h"`

### Task 2: telefone do `@lid`

**Files:**
- Modify: `app/Services/Waha/WahaContacts.php` (`phoneForLid(string $lid): ?string`)
- Create: `app/Services/Inbox/ResolveLidPhone.php`
- Modify: `app/Services/Inbox/ImportHistory.php` (injeta `ResolveLidPhone`; após gravar os itens de um chat cujo `peer` termina em `@lid`, chama-o)
- Test: `tests/Feature/Waha/WahaContactsLidTest.php`, `tests/Feature/Recovery/ImportLidTest.php`

**Interfaces:**
- Produces: `WahaContacts::phoneForLid(string $lid): ?string` — `GET /api/{session}/lids/{lid}` (lid urlencoded), devolve `pn` ou `null` se 404/sem `pn`; outros erros lançam `WahaRequestException`. `ResolveLidPhone::__invoke(Channel $channel, string $lid): void` — só age se existe `Contact` com esse `lid` e `wa_id` nulo; nunca lança (loga `Log::warning`).

- [ ] **Step 1: testes**: `phoneForLid` devolve `5516...@c.us` com `Http::fake` e a URL com a sessão; devolve `null` no 404. Importação de chat `@lid` com `Http::fake` de `/lids/*` → `pn`: contato fica com `wa_id`, um só contato; com tickets/mensagens do contato antigo de telefone fundidos; com 500 em `/lids`: importação termina, contato segue sem `wa_id`; consulta feita uma vez por contato.
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: implementar**; em `ResolveLidPhone` chamar `(app(ResolveContact::class))($lid, $pn)` (o `senderAlt` é o `pn`; conferir que `phoneFromSenderAlt` aceita `@c.us`).
- [ ] **Step 4:** PASS + `tests/Feature/Recovery`.
- [ ] **Step 5:** `git commit -m "feat: importação resolve o telefone de contato @lid pelo WAHA"`

### Task 3: resumo do que foi ignorado

**Files:**
- Modify: `app/Services/Waha/WahaChat.php` (`ignoredKind(string $chat): ?string` → `'grupo'` | `'transmissão'` | `null`)
- Modify: `app/Services/Inbox/RecoveryResult.php` (3º parâmetro `public array $ignored = []`, `array<string,int>`)
- Modify: `app/Services/Inbox/ImportHistory.php` (em `chats()`, quando `fromEvent` devolve `null`, contar motivo: `WahaChat::ignoredKind` do `from`/`sentTo`, senão `'sem conteúdo'`; expor no `RecoveryResult`)
- Modify: `app/Jobs/ImportHistoryJob.php` (somar `ignored` em cache `import-totals:{canal}:ignored:{motivo}`, esquecer no fim, passar a `MessagesRecovered`)
- Modify: `app/Notifications/MessagesRecovered.php` (`array $ignored = []` no fim do construtor; `'ignored'` no `toArray` só se não vazio)
- Modify: `app/Livewire/Alerts/Bell.php` (`recoveryDetail` acrescenta ` · ignoradas: 3 de grupo, 1 sem conteúdo`)
- Test: `tests/Feature/Recovery/ImportIgnoredTest.php`, ajustar `ImportHistoryJobTest`, teste do sino

**Interfaces:**
- Consumes: Task 2 não é necessária. Produces: `RecoveryResult::$ignored`.

- [ ] **Step 1: testes**: importação com 1 grupo, 1 broadcast, 1 mensagem sem conteúdo e 1 normal → `ignored === ['grupo'=>1,'transmissão'=>1,'sem conteúdo'=>1]` e mensagem normal gravada; job soma entre duas rodadas e a notificação traz `ignored`; sino mostra o texto; sem ignoradas o texto atual não muda.
- [ ] **Step 2:** rodar → FAIL. **Step 3:** implementar. **Step 4:** PASS + `tests/Feature/Recovery` + `tests/Feature/Livewire` do sino.
- [ ] **Step 5:** `git commit -m "feat: notificação da importação mostra o que foi ignorado"`

### Task 4: documentação e ambiente do WAHA

**Files:** Modify `docs/superpowers/specs/waha-config.md`.

- [ ] **Step 1:** na matriz do `beta`, `WAHA_GOWS_DEVICE_REQUIRE_FULL_SYNC=true`; acrescentar nota "profundidade máxima do WAHA não documentada; testar teto maior em data futura (ler chat a chat)".
- [ ] **Step 2:** via API do Coolify, definir a variável no `waha-beta` (UUID em `.env.coolify.local`), reiniciar, `logout` + novo QR (o dono sincroniza o celular).
- [ ] **Step 3:** `git commit -m "docs: full sync no beta e nota de teste de profundidade"`

### Task 5: verificação, deploy no beta e teste no Chrome

- [ ] Suíte inteira (Postgres + espelho do CI com Redis), Pint, Larastan, PHPMD.
- [ ] Revisão (portão 6), push do branch, deploy do beta pelo Coolify, importar no beta e conferir no Chrome: vários tickets por contato, telefone resolvido, sino com ignoradas.
