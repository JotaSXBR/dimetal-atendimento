# Número do canal, dois números e voltar ao ticket aberto: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gravar o número do canal a partir do WAHA, mostrar por qual número cada conversa acontece, avisar do mesmo contato em outro número, voltar ao ticket aberto e fazer o Desativar valer depois de um reboot do WAHA.

**Architecture:** `ChannelPhone::sync()` é o único ponto que lê `me.id` (usado pelo poll do `ChannelCard` e pelo webhook `session.status`). Selo de número e faixa do contato são só leitura. A faixa é um componente Livewire filho (`Inbox\ContactTickets`) para não recalcular a cada tecla. `Desativar` passa a fazer logout (a sessão sobe em Aguardando QR depois de um reboot).

**Tech Stack:** Laravel 13, Livewire 4, PHPUnit com `Livewire::test` e `Http::fake`, Postgres.

**Spec:** `docs/superpowers/specs/2026-11-01-dois-numeros-design.md`

## Global Constraints

- O número é gravado só em dígitos (`5516999990001`) e exibido com `PhoneFormat::display()` (ex.: `(16) 99999-0001`).
- `me.id` pode vir como `5516…@c.us`, `5516…:12@s.whatsapp.net` ou vazio; vazio nunca apaga o número gravado.
- Primeira gravação não alerta; troca real alerta no sino (`kind = connection`, `state = number_changed`, só ids e nomes) e mostra aviso no cartão.
- Selo de número e faixa só aparecem com 2 ou mais canais ativos; a faixa nunca mostra setor, atendente ou texto, e só leva link a quem pode ver o ticket (`readableBy`).
- Faixa: no máximo 5 itens, abertos primeiro, depois encerrados dos últimos 30 dias.
- A resposta sai sempre pelo número do ticket (nada muda no envio).
- Canal desativado: logout no WAHA, sem alerta em qualquer status, sem buscar QR; Reativar volta `active = true` e inicia a sessão (aparece o QR).
- Commits em português com prefixo, um por tarefa, no branch `feat/dois-numeros-e-navegacao`.
- Testes: `QUEUE_CONNECTION=database php artisan test` (Postgres 127.0.0.1:5432, `pg-test`); `node --test tests/js` não muda nesta fatia.

## Review Focus

- `me.id` em formatos diferentes e vazio (Tarefa 2).
- A troca de número alerta uma vez só, mesmo com poll e webhook gravando o mesmo número (Tarefa 2).
- Canal desativado em Aguardando QR não alerta nem busca QR (Tarefa 1).
- A faixa não vaza nada de ticket que o usuário não pode ver (Tarefa 4).
- Digitar no Inbox não consulta a faixa (Tarefa 4).
- O botão Voltar não reabre ticket que o usuário perdeu o acesso (Tarefa 5).

---

### Task 1: Desativar = desvincular e esperar QR

**Files:**
- Modify: `app/Livewire/Settings/ChannelCard.php`, `resources/views/livewire/settings/channel-card.blade.php`
- Test: `tests/Feature/Livewire/ChannelsScreenTest.php`

**Interfaces:**
- Produces: `ChannelCard::deactivate()` chama `WahaClient::logout()` (já existe) em vez de `stop()`; `reactivate()` seta `active = true` e chama `WahaClient::start()` (erro vira `errorMessage`, `active` fica `true`); `refreshStatus` só busca QR quando `active`.

- [ ] **Step 1: Escrever os testes que falham** em `ChannelsScreenTest`:
  - `test_desativar_desvincula_a_sessao_no_waha`: `Http::fake` 200; `ask('deactivate')->call('deactivate')`; `Http::assertSent` `POST /api/sessions/logout` com `name = main`; `active = false`; nunca `…/stop`.
  - `test_reativar_marca_ativo_e_inicia_a_sessao`: canal inativo, `Http::fake` (GET 404, POST 201); `reactivate` deixa `active = true` e envia `POST /api/sessions` com `name`; com WAHA 500 o canal fica `active = true` e `errorMessage` cita o WAHA.
  - `test_canal_desativado_em_aguardando_qr_nao_busca_qr_nem_alerta`: canal `active = false`, WAHA responde `SCAN_QR_CODE`; `refreshStatus` não chama `/auth/qr`, `qrValue` e `qr` nulos; `Notification::fake()` sem envios.
  - Ajustar `test_desativar_para_a_sessao_e_marca_inativo` (passa a esperar logout) e `test_reativar_volta_a_ativo_sem_iniciar_a_sessao` (passa a esperar o início).
- [ ] **Step 2: Rodar e ver falhar:** `php artisan test tests/Feature/Livewire/ChannelsScreenTest.php`.
- [ ] **Step 3: Implementar** as três mudanças; textos: confirmação de desativar ganha "O aparelho será desvinculado: para reativar é preciso escanear o QR de novo."; dica do canal desativado fica "Desligado de propósito: aparelho desvinculado, sem alertas e sem resposta nos tickets deste número.".
- [ ] **Step 4: Rodar e ver passar** o mesmo comando.
- [ ] **Step 5: Commit:** `git add app resources tests && git commit -m "feat: desativar desvincula o aparelho e reativar inicia a sessão"`

### Task 2: Número do canal vindo do WAHA

**Files:**
- Create: `database/migrations/2026_11_01_000001_add_phone_change_to_channels.php`, `app/Services/Waha/ChannelPhone.php`, `app/Notifications/ChannelNumberChanged.php`
- Modify: `app/Models/Channel.php`, `app/Services/Alerts/AlertDispatcher.php`, `app/Livewire/Alerts/Bell.php`, `app/Livewire/Settings/ChannelCard.php`, `app/Jobs/HandleWahaEvent.php`, `resources/views/livewire/settings/channel-card.blade.php`
- Test: `tests/Feature/Waha/ChannelPhoneTest.php`, `tests/Feature/Jobs/HandleWahaEventTest.php`, `tests/Feature/Livewire/ChannelsScreenTest.php`, `tests/Feature/Alertas/BellTest.php`

**Interfaces:**
- Produces: colunas `previous_phone` (string nula) e `phone_changed_at` (timestamp nulo) em `channels`, expostas em `Channel` (`$fillable`, cast `phone_changed_at => datetime`); `ChannelPhone::digits(?string $meId): ?string`; `ChannelPhone::sync(Channel $channel, ?string $meId): void`; `AlertDispatcher::numberChanged(Channel $channel, string $from): void`; `ChannelNumberChanged` (`kind = connection`, `state = number_changed`, `channel_id`, `channel_name`); `Channel::phoneLabel(): ?string` (`PhoneFormat::display($phone)` ou nulo).

- [ ] **Step 1: Escrever os testes que falham:**
  - `ChannelPhoneTest::test_digits_normaliza_os_formatos`: `5516999990001@c.us`, `5516999990001:12@s.whatsapp.net`, `5516999990001`, `''` e `null` → `'5516999990001'`, `'5516999990001'`, `'5516999990001'`, `null`, `null`.
  - `test_primeira_gravacao_nao_alerta`: `Notification::fake()`, canal sem número; `sync` grava, `previous_phone` e `phone_changed_at` nulos, nenhum alerta.
  - `test_numero_igual_ou_vazio_nao_muda_nada`: mesmo número e `''` não alteram a linha nem alertam.
  - `test_troca_de_numero_atualiza_guarda_o_anterior_e_alerta_uma_vez`: troca grava `previous_phone`, `phone_changed_at` e dispara `ChannelNumberChanged` para todos; um segundo `sync` com o mesmo número novo não alerta de novo.
  - `HandleWahaEventTest::test_status_com_me_grava_o_numero_do_canal`: payload `['me' => ['id' => '5516999990001@c.us'], 'payload' => ['status' => 'WORKING']]` grava o número.
  - `ChannelsScreenTest::test_poll_grava_o_numero_e_o_cartao_mostra`: WAHA `{status: WORKING, me: {id: …}}`; `refreshStatus` grava e o cartão mostra `(16) 99999-0001`; troca mostra "Número alterado de … para …".
  - `BellTest::test_alerta_de_troca_de_numero_aparece_no_sino`: item "Número do WhatsApp mudou" com o nome do canal.
- [ ] **Step 2: Rodar e ver falhar** (`php artisan test` nos arquivos acima).
- [ ] **Step 3: Implementar** migração e modelo; `ChannelPhone` (normaliza tirando `@…` e `:dispositivo`, ignora vazio, compara, atualiza em transação curta, chama `AlertDispatcher::numberChanged` só na troca real); `numberChanged` envia `ChannelNumberChanged` a `recipients->everyone()`; `Bell::present` ganha o caso `number_changed` (ícone `arrow-path`, tom `warn`, título "Número do WhatsApp mudou"); `ChannelCard::refreshStatus` e `HandleWahaEvent::applyStatus` leem `me.id` (`session()['me']['id']` e `payload.me.id`) e chamam `ChannelPhone::sync`; a view troca "número ainda não lido" por `$channel->phoneLabel() ?? 'número ainda não lido'` e mostra o aviso da troca com `previous_phone`/`phone_changed_at`.
- [ ] **Step 4: Rodar e ver passar.**
- [ ] **Step 5: Commit:** `git add app database resources tests && git commit -m "feat: o número do canal vem do WAHA, com aviso quando troca"`

### Task 3: Selo do número na lista e no cabeçalho

**Files:**
- Modify: `app/Livewire/Inbox/Inbox.php`, `resources/views/livewire/inbox/inbox.blade.php`
- Test: `tests/Feature/Livewire/InboxChannelBadgeTest.php`

**Interfaces:**
- Consumes: `Channel::phoneLabel()` (Tarefa 2).
- Produces: `Inbox::render` passa `showChannel` (bool: 2 ou mais canais com `active = true`); a lista carrega `channel`; item e cabeçalho mostram "nome do canal · número" só se `showChannel`.

- [ ] **Step 1: Testes que falham:** com 1 canal ativo o HTML da lista e do cabeçalho não traz o número; com 2 ativos traz nome e número do canal de cada ticket na lista e no cabeçalho; canal desativado não conta (1 ativo + 1 desativado = sem selo).
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar:** `->with('contact', 'channel')` na lista; `'showChannel' => Channel::query()->where('active', true)->count() >= 2`; selo no item (`flux:badge` ou o estilo já usado no item) e no cabeçalho (substitui o `channel->name` solto de hoje).
- [ ] **Step 4: Rodar e ver passar.**
- [ ] **Step 5: Commit:** `git commit -m "feat: selo do número do canal na lista e no cabeçalho com dois canais"`

### Task 4: Faixa "Outros tickets deste contato"

**Files:**
- Create: `app/Livewire/Inbox/ContactTickets.php`, `resources/views/livewire/inbox/contact-tickets.blade.php`
- Modify: `resources/views/livewire/inbox/inbox.blade.php`
- Test: `tests/Feature/Livewire/ContactTicketsTest.php`

**Interfaces:**
- Consumes: `Channel::phoneLabel()`, `Ticket::scopeReadableBy`.
- Produces: `App\Livewire\Inbox\ContactTickets` com `#[Locked] public int $ticketId`; `#[On('echo-private:inbox,.ticket.updated')] refresh()`; `render()` calcula até 5 itens `{protocol, number, status, link: ?int}`; só renderiza com mais de um canal ativo OU quando há outro ticket (a faixa nunca aparece vazia).

- [ ] **Step 1: Testes que falham:** (a) outros tickets do mesmo contato aparecem, abertos antes dos encerrados, encerrado com mais de 30 dias não; (b) ticket aberto do mesmo contato em outro número ganha o destaque "também tem ticket aberto no número …"; (c) quem pode ver o outro ticket recebe o link (`select`), quem não pode vê protocolo e número sem link e sem setor, atendente ou texto (`assertDontSee` do nome do setor, do atendente e do corpo da mensagem); (d) limite de 5; (e) ticket de outro contato nunca entra; (f) `set('draft', '/')` no `Inbox` não consulta `contact-tickets` (query log sem a consulta da faixa).
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** o componente (consulta de links com `readableBy`, consulta de existência sem link só com `id, protocol, channel_id, status, closed_at`), a view e o `<livewire:inbox.contact-tickets :ticket-id="$ticket->id" :key="'contact-tickets-'.$ticket->id" />` no cabeçalho do ticket.
- [ ] **Step 4: Rodar e ver passar.**
- [ ] **Step 5: Commit:** `git commit -m "feat: faixa com os outros tickets do mesmo contato"`

### Task 5: Voltar ao ticket anterior

**Files:**
- Modify: `app/Livewire/Inbox/Inbox.php`, `resources/views/livewire/inbox/inbox.blade.php`
- Test: `tests/Feature/Livewire/InboxGoBackTest.php`

**Interfaces:**
- Produces: `public ?int $previousTicketId` em `Inbox`; `select()` grava o ticket que estava aberto (se existir e for diferente); `goBack(): void` reabre o anterior só se `readableBy`, e limpa; o cabeçalho mostra "Voltar ao ticket anterior (PROTOCOLO)" quando há anterior legível.

- [ ] **Step 1: Testes que falham:** selecionar A depois B mostra o botão com o protocolo de A; `goBack` volta a A e o botão some; selecionar o mesmo ticket não cria "anterior"; se A passou a ser invisível ao usuário, `goBack` não o abre e limpa; `previousTicketId` forjado pelo cliente (ticket sem acesso) não abre nada (`set('previousTicketId', …)` + `goBack`).
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** (`readableBy` ao exibir e ao usar; ticket anterior carregado só para o protocolo).
- [ ] **Step 4: Rodar e ver passar.**
- [ ] **Step 5: Commit:** `git commit -m "feat: botão voltar ao ticket anterior"`

### Task 6: Verificação e fechamento

**Files:**
- Modify: `docs/superpowers/specs/2026-11-01-dois-numeros-design.md` (resultado), memória do projeto

- [ ] **Step 1:** Suíte inteira, `node --test tests/js`, Pint, Larastan e PHPMD limpos, lidos nesta sessão.
- [ ] **Step 2:** Conferência no Chrome contra um WAHA falso com `me.id` (cartão com número, troca de número, selo, faixa, voltar, desativar).
- [ ] **Step 3:** Revisão do branch inteiro pelo advisor; críticos e importantes corrigidos com teste RED→GREEN.
- [ ] **Step 4:** Resultado no spec e commit `docs: resultado da fatia de dois números`.
