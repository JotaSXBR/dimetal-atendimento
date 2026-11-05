# Iniciar conversa pelo app e adicionar contato (fatia 5) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, regra do dono) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O atendente começa uma conversa pelo app (botão "Nova conversa" na Fila, "Conversar" em Contatos, na ficha e no cartão de contato recebido), escolhendo canal e setor, e adiciona ao contato quem veio em cartão.

**Architecture:** Uma ação de servidor `StartConversation` concentra as regras (permissão, número no WhatsApp, ritmo, reaproveitar ticket aberto, criar ticket + mensagem juntos); um componente Livewire de janela a chama e é reaproveitado pelos pontos de entrada por evento `start-conversation`.

**Tech Stack:** Laravel 13, Livewire 4, Flux, PostgreSQL, Redis (RateLimiter), WAHA (`GET /api/contacts/check-exists`), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-05-iniciar-conversa-design.md`

## Global Constraints

- Branch `feat/inbox-iniciar-conversa` (saído da `main`); sem PR até o dono pedir.
- O ticket só nasce junto com o envio da primeira mensagem: qualquer recusa (número sem WhatsApp, WAHA fora, ritmo, canal, setor, texto) acontece **antes** de criar linha alguma.
- Ticket novo: `status = em_atendimento`, `assigned_user_id` = autor, `sector_id` escolhido, `waiting_since` e `last_inbound_at` nulos (SLA não conta), sem bot (`BotEntry` não é chamado), evento `opened_by_agent` com o autor.
- Um ticket aberto por contato e canal (`tickets_one_open_per_contact_channel`): reaproveitar o aberto, com a mesma trava consultiva de `IngestMessage::openTicketFor` (`pg_advisory_xact_lock(hashtext('ticket:{contato}:{canal}'))`).
- Ritmo (só conversa iniciada pelo app): 1 por canal a cada 30 s e 4 por hora por contato, via `RateLimiter`.
- Texto da primeira mensagem: obrigatório, até 4096, com `Signature::apply` como no envio normal.
- Arquivos podem estar em CRLF: editar com `Edit` ou Python `newline=''`. Heredoc com crases ou `$` quebra o shell: criar arquivos com a ferramenta `Write`. Commits em português com prefixo e linha `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Testes: `php artisan test --filter=<Nome>` com Postgres `pg-test`; para o que usa fila, `QUEUE_CONNECTION=database`. Pint e Larastan em cada tarefa; PHPMD no fim (limites: 15 métodos públicos por classe, complexidade 50 por classe).

## Review Focus

1. Nenhuma linha (ticket, mensagem, evento) sobra quando a ação recusa: número sem WhatsApp, WAHA com erro, ritmo, setor, canal (Task 2).
2. Corrida: dois cliques ou dois atendentes iniciando o mesmo contato e canal geram **um** ticket (Task 2).
3. Atendente não inicia em setor que não cobre nem forjando o `sectorId` no Livewire (Task 2 e 3).
4. SLA não conta e o bot não entra até o cliente responder; a resposta do cliente cai no mesmo ticket (Task 2).
5. "Adicionar aos contatos" não duplica (variantes com e sem nono dígito) e o telefone do cartão pode vir sem DDI (Task 5).

## Rulings do plano

- Setor é obrigatório (nunca `null`) ao iniciar; se o sistema não tiver setor que receba tickets, a janela avisa e não deixa enviar. Custo: instalação sem setores não inicia conversa.
- Ao reaproveitar ticket aberto, o texto digitado vai ao campo de resposta dele (por `session()->flash`, lido no `mount` do `Inbox`) só quando o usuário pode responder; senão aparece só o aviso de quem é o ticket. Custo: o texto digitado se perde nesse segundo caso.
- Depois de iniciar, a janela redireciona para `/inbox?ticket={id}` (o `mount` do `Inbox` já abre por esse parâmetro). Custo: recarrega a tela em vez de trocar só o ticket.
- O formato da resposta do `check-exists` só se confirma no beta; o cliente trata `numberExists` como `true` apenas quando a resposta diz que existe (`numberExists: true`), e erro de HTTP vira "não foi possível confirmar". Custo se o formato for outro: ninguém inicia conversa até ajustar uma linha.

---

### Task 1: Cliente WAHA `numberExists` e rótulos de auditoria

**Files:**
- Modify: `app/Services/Waha/WahaClient.php`, `app/Support/TicketEventText.php`, `app/Support/TicketTimeline.php`
- Test: `tests/Feature/Inbox/NumberExistsTest.php`

**Interfaces:**
- Produces: `WahaClient::numberExists(string $waId): bool` (`$waId` como `5516999999999@c.us`; envia só os dígitos); tipo de evento `opened_by_agent` com rótulo "Conversa iniciada pela equipe" (auditoria) e verbo "iniciou a conversa".

- [ ] **Step 1: Escrever o teste (RED)**: com `Http::fake` (`*/api/contacts/check-exists*` → `['numberExists' => true, 'chatId' => '…@c.us']`), `numberExists('5516999999999@c.us')` é `true` e a requisição saiu com `phone=5516999999999` e `session` do canal; resposta `numberExists => false` dá `false`; status 500 lança `WahaRequestException`; `TicketEventText::label('opened_by_agent')` é "Conversa iniciada pela equipe".
- [ ] **Step 2: Rodar** `--filter=NumberExistsTest`. Expected: FAIL (método não existe).
- [ ] **Step 3: Implementar** `numberExists` em `WahaClient` no estilo de `sendText` (`$this->request()->get('/api/contacts/check-exists', ['phone' => dígitos, 'session' => $this->sessionName()])`, `decode`, `(bool) ($body['numberExists'] ?? false)`), e as duas linhas de rótulo.
- [ ] **Step 4: Rodar** o filtro. Expected: PASS. Pint e Larastan.
- [ ] **Step 5: Commit** `feat: cliente WAHA confere se o número tem WhatsApp`.

### Task 2: Ação `StartConversation`

**Files:**
- Create: `app/Actions/Inbox/StartConversation.php`, `app/Services/Inbox/ConversationNotStarted.php`
- Test: `tests/Feature/Inbox/StartConversationTest.php`

**Interfaces:**
- Consumes: `WahaClient::numberExists` (Task 1); `SendTicketMessage::create(Ticket, ?User, string, MessageOrigin, ?Message): Message`; `SendTicketMessageJob::dispatch(int $messageId)`; `Signature::apply(string, User, ?Sector): string`; `User::canHandleSector(?int)`; `Sector::receivingTickets` (scope) e `SectorStatus::receivesTickets()`.
- Produces: `StartConversation::__invoke(User $user, Contact $contact, Channel $channel, Sector $sector, string $text): array{ticket: Ticket, created: bool}`; `ConversationNotStarted extends RuntimeException` (mensagem pronta para a tela, padrão de `MessageActionFailed`).

- [ ] **Step 1: Escrever os testes (RED)** em `StartConversationTest` (`Http::fake`, `Queue::fake`, canal `WORKING`, contato `wa_id` `5516999999999@c.us`, atendente com o setor):
  - `test_cria_ticket_em_atendimento_com_dono_setor_evento_e_mensagem`: `created` verdadeiro; ticket `em_atendimento`, `assigned_user_id`, `sector_id`, `waiting_since` e `last_inbound_at` nulos; evento `opened_by_agent` com `actor_user_id`; uma `Message` de saída, origem painel, `author_user_id`, corpo = texto (com assinatura quando ligada); `SendTicketMessageJob` empurrado; o bot não criou `BotSession`.
  - `test_reaproveita_o_ticket_aberto_sem_criar_nem_enviar`: ticket aberto do contato no canal → `created` falso, mesmo ticket, nenhuma `Message` nova, nenhuma chamada HTTP, nenhum job.
  - `test_nada_sobra_quando_recusa` (um caso por motivo, `assertSame(0, Ticket::count())`, `Message::count()` e eventos): `numberExists` falso ("Este número não tem WhatsApp."); WAHA 500 ("Não foi possível confirmar o número agora…"); canal inativo e canal fora do ar; contato só com `lid`; texto vazio e de 4097 caracteres; setor `desativado`; atendente sem o setor.
  - `test_ritmo`: segunda conversa no mesmo canal em menos de 30 s recusa com "Aguarde N s"; o quinto início para o mesmo contato dentro de 1 h recusa (usar tickets encerrados entre uma tentativa e outra); `RateLimiter::clear` entre testes.
  - `test_corrida_gera_um_ticket_so`: duas chamadas seguidas para o mesmo contato e canal (a segunda após a primeira ter criado) resultam em um ticket aberto; e capturar `UniqueConstraintViolationException` não é necessário porque a trava consultiva serializa.
  - `test_resposta_do_cliente_cai_no_mesmo_ticket_e_comeca_o_sla`: depois de iniciar, `IngestMessage` de entrada do mesmo contato e canal usa o ticket criado e define `waiting_since` (reusar o helper de ingest dos testes existentes, ver `tests/Feature/Inbox/IngestIntegrationTest.php`).
- [ ] **Step 2: Rodar** `--filter=StartConversationTest`. Expected: FAIL (classe não existe).
- [ ] **Step 3: Implementar** a ação, na ordem: validar texto e permissões (usuário ativo; canal ativo e `isConnected`; `wa_id` terminando em `@c.us`; setor recebe tickets e `canHandleSector`); se há ticket aberto do contato no canal, devolver `created: false` (sem custo de WAHA nem de ritmo); `numberExists` (erro de WAHA vira `ConversationNotStarted`); ritmo com `RateLimiter::attempt` (`start-conversation:channel:{id}` 1/30 s; `start-conversation:contact:{id}` 4/3600 s; mensagem com `availableIn`); depois `DB::transaction` com a trava consultiva, nova checagem do ticket aberto, `Ticket::create`, `TicketEvent` `opened_by_agent` (payload `channel_id`, `sector_id`), `SendTicketMessage::create` com `Signature::apply`. Fora da transação: `SendTicketMessageJob::dispatch` e `TicketUpdated::dispatch`. Confirmar em `Ticket` que `assigned_user_id` e `status` são preenchíveis (o `Fillable` do modelo).
- [ ] **Step 4: Rodar** `QUEUE_CONNECTION=database php artisan test --filter=StartConversationTest`. Expected: PASS. Pint, Larastan.
- [ ] **Step 5: Commit** `feat: ação que inicia conversa com um contato`.

### Task 3: Janela "Nova conversa" e botão na Fila

**Files:**
- Create: `app/Livewire/Inbox/StartConversation.php`, `resources/views/livewire/inbox/start-conversation.blade.php`
- Modify: `resources/views/livewire/inbox/inbox.blade.php` (botão no cabeçalho da Fila e `<livewire:inbox.start-conversation />`), `app/Livewire/Inbox/Inbox.php` (`mount` lê `session('inbox-draft')` e `session('inbox-notice')`)
- Test: `tests/Feature/Livewire/StartConversationModalTest.php`

**Interfaces:**
- Consumes: `StartConversation` ação e `ConversationNotStarted` (Task 2); `CreateContact::__invoke(string $phone, string $name): array{contact: Contact, created: bool}`; `Contact::scopeSearch`.
- Produces: componente Livewire `inbox.start-conversation` que abre por `#[On('start-conversation')] open(?int $contactId = null)`; propriedades públicas `contactId`, `search`, `phone`, `name`, `channelId`, `sectorId`, `text`; método `submit()`.

- [ ] **Step 1: Escrever os testes (RED):** `open` com contato pré-seleciona canal do ticket mais recente do contato (ou o único ativo) e o setor quando o usuário só tem um; atendente com dois setores vê os dois e `submit` com `sectorId` de outro setor é recusado no servidor (forjando a propriedade); busca por nome lista contatos; telefone novo (`phone` + `name` vazio) cria o contato e inicia; `submit` com número sem WhatsApp mostra a mensagem da exceção em `form` e não cria nada; sucesso redireciona para `route('inbox', ['ticket' => id])` (`assertRedirect`); com ticket aberto reaproveitado o redirect leva `session('inbox-draft')` com o texto quando o usuário pode responder, e `session('inbox-notice')` com "Já existe conversa aberta com {contato} (de {atendente})" quando é de outro; o cabeçalho da Fila tem o botão "Nova conversa".
- [ ] **Step 2: Rodar** `--filter=StartConversationModalTest`. Expected: FAIL.
- [ ] **Step 3: Implementar** o componente (`flux:modal` como em `pages/settings/⚡delete-user-modal.blade.php`): busca de contato ou campo de telefone e nome; `flux:select` de canal (canais ativos, os fora do ar desabilitados com o motivo) e de setor (`canSeeAllSectors` → `Sector::receivingTickets()` por `menu_order`; senão `$user->sectors()->receivingTickets()`; um só vem preenchido); campo de texto de até 4096; `submit()` valida, resolve o contato (escolhido ou `CreateContact`), chama a ação e redireciona; `ConversationNotStarted` vira erro na janela. `Inbox::mount` lê as duas chaves da sessão (`draft` só se `can('reply')`). Botão no cabeçalho da Fila: `x-on:click="$dispatch('start-conversation')"`.
- [ ] **Step 4: Rodar** `--filter='StartConversationModalTest|InboxTest'` com `QUEUE_CONNECTION=database`. Expected: PASS. Pint, Larastan, `npm run build`.
- [ ] **Step 5: Commit** `feat: janela Nova conversa e botão na Fila`.

### Task 4: "Conversar" em Contatos e na ficha

**Files:**
- Modify: `resources/views/livewire/contacts/index.blade.php` (botão por linha e a janela), `resources/views/livewire/contacts/contact-card.blade.php` (botão), `app/Livewire/Contacts/Index.php` se precisar da janela na página
- Test: `tests/Feature/Contacts/StartConversationButtonsTest.php`

**Interfaces:**
- Consumes: evento `start-conversation` com `contactId` (Task 3).

- [ ] **Step 1: Escrever os testes (RED):** a lista e a ficha de contato com `wa_id` `@c.us` têm "Conversar" com `$dispatch('start-conversation', { contactId: id })` (HTML); contato só com `lid` tem o botão desabilitado e o motivo ("sem telefone"); a página Contatos inclui o componente da janela; a ficha somente leitura do Histórico não mostra o botão.
- [ ] **Step 2: Rodar.** Expected: FAIL.
- [ ] **Step 3: Implementar** os botões (ícone `chat-bubble-left-right`), usando `x-on:click.stop` na linha para não selecionar o contato, e `<livewire:inbox.start-conversation />` na página Contatos.
- [ ] **Step 4: Rodar** `--filter='StartConversationButtonsTest|ContactsIndexTest|ContactCard'`. Expected: PASS. Pint.
- [ ] **Step 5: Commit** `feat: Conversar na lista e na ficha de contatos`.

### Task 5: Cartão de contato recebido: "Adicionar aos contatos" e "Conversar"

**Files:**
- Modify: `app/Livewire/Inbox/Transcript.php` (`addContact`, `chatWith`), `resources/views/livewire/inbox/contact.blade.php`
- Test: `tests/Feature/Livewire/ContactCardMessageTest.php`

**Interfaces:**
- Consumes: `SpecialContent::contacts(string $body): list<array{name, initials, phones}>`; `CreateContact`; `PhoneFormat::toWaId` e `variants`; evento `start-conversation`.
- Produces: `Transcript::addContact(int $messageId, int $index): void` e `Transcript::chatWith(int $messageId, int $index): void`.

- [ ] **Step 1: Escrever os testes (RED):** mensagem de tipo `contact` com dois cartões; `addContact` cria o contato (`wa_id` normalizado, nome do cartão) e um segundo clique, ou um número com e sem nono dígito, não duplica; mensagem de outro ticket é ignorada; o cartão mostra "Adicionar aos contatos" enquanto o telefone não é contato e "Já está nos contatos" depois; `chatWith` cria/reaproveita o contato e despacha `start-conversation` com o `contactId`; o link `wa.me` saiu; cartão sem telefone válido não mostra os botões.
- [ ] **Step 2: Rodar.** Expected: FAIL.
- [ ] **Step 3: Implementar** os dois métodos no `Transcript` (acham a mensagem em `where('ticket_id', $this->ticketId)`, leem o cartão `$index`, usam o primeiro telefone com dígitos, `CreateContact`; `chatWith` termina com `$this->dispatch('start-conversation', contactId: $contact->id)`), e o Blade com os dois botões (`wire:click="addContact({{ $message->id }}, {{ $index }})"`). O estado "já está nos contatos" vem de `Contact::whereIn('wa_id', PhoneFormat::variants(...))->exists()` no render.
- [ ] **Step 4: Rodar** `--filter='ContactCardMessageTest|InboxTranscriptTest'`. Expected: PASS. Pint, Larastan, `npm run build`.
- [ ] **Step 5: Commit** `feat: adicionar aos contatos e conversar pelo cartão de contato recebido`.

## Depois das tarefas (portões)

Gate 6: perguntar ao dono sobre revisor independente; se negar, autorrevisão declarada (e conferir os cinco itens do Review Focus). Gate 7: suíte inteira com Redis em série (**subir o `redis-test` e conferir `PONG` antes**, e só removê-lo depois), Pint, Larastan e PHPMD lidos. Conferência no beta pelo Chrome: confirmar o formato real do `check-exists` do WAHA, iniciar conversa com o número de teste, setor único e múltiplo, ticket já aberto, cartão de contato. Gate 8: PR só quando o dono pedir.
