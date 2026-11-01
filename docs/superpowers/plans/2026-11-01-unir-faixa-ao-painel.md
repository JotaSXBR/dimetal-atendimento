# Unir a faixa de outros tickets ao painel da ficha Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tirar a faixa "Outros tickets deste contato" da coluna do meio do Inbox e passar o que ela mostrava (número do canal, destaque de ticket aberto em outro número, link seguro) para "Atendimentos anteriores", na ficha da direita.

**Architecture:** `ContactCard` (modo compacto) já lista os 10 últimos tickets do contato. Ele ganha o canal de cada item, a lista de tickets que o usuário pode abrir (uma consulta com `readableBy`, no lugar de um `can('view')` por linha), o link `$parent.select()` para ticket aberto e um `refresh()` ligado a `ticket.updated`. Depois o componente `Inbox\ContactTickets` é apagado.

**Tech Stack:** Laravel 13, Livewire 4, Blade, PHPUnit com `Livewire::test`.

**Spec:** desenho curto aprovado no chat em 01/11/2026 (mudança pequena; sem arquivo próprio). Contexto: `docs/superpowers/specs/2026-11-01-dois-numeros-design.md` (item 4 e "Em aberto").

## Global Constraints

- Quem não pode abrir o ticket vê só protocolo, número e estado: nunca setor, atendente ou texto.
- A ficha no modo completo (tela Contatos) não muda.
- A lista continua com os 10 mais recentes, sem janela de 30 dias; o ticket atual não aparece nela.
- Link de ticket aberto só fora do `readonly` (o Histórico não tem `select`).
- Commits em português com prefixo e linha `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; sem citar projetos consultados.

## Review Focus

- Atendente sem acesso ao ticket de outro setor: o setor dele não pode aparecer na ficha (hoje aparece).
- Ficha em edição recebendo `ticket.updated`: não pode perder o que foi digitado.
- Histórico (`readonly`): ticket aberto do mesmo contato aparece sem link e sem chamar `$parent.select`.
- Contato com um só canal: sem número repetido em cada linha.
- Canal apagado ou nulo no ticket antigo: não quebra a view.

---

### Task 1: Ficha mostra número, destaque e link

**Files:**
- Modify: `app/Livewire/Contacts/ContactCard.php`, `resources/views/livewire/contacts/contact-card.blade.php` (bloco "Atendimentos anteriores", linhas 26-43)
- Test: `tests/Feature/Livewire/ContactCardCompactTest.php`

**Interfaces:**
- Consumes: `Ticket::scopeReadableBy(User)`, `Channel::phoneLabel(): ?string`, `Inbox::select(int)` (público, via `$parent`).
- Produces: `ContactCard::refresh(): void` com `#[On('echo-private:inbox,.ticket.updated')]` (pula o render se `editing` ou se não for `compact`); a view recebe `$openable` (`list<int>`) e `$currentChannelId` (`?int`); `$history` passa a vir com `channel`.

- [ ] **Step 1: Escrever os testes que falham** em `ContactCardCompactTest`, com um helper `ticketOn(Channel, status, ?Sector)`:
  - `test_ticket_de_outro_numero_mostra_o_numero`: canais A e B (phone `5516999990001` / `5516988887777`), ticket atual em A, encerrado em B → vê `(16) 98888-7777`; o encerrado em A (mesmo número) não repete número.
  - `test_ticket_aberto_em_outro_numero_ganha_destaque_e_link_para_o_inbox`: aberto em B → vê `também tem ticket aberto no número (16) 98888-7777` e o HTML contém `$parent.select(<id>)`.
  - `test_ticket_aberto_no_mesmo_numero_tambem_linka`: aberto em A → HTML contém `$parent.select(<id>)` e não contém `também tem ticket aberto`.
  - `test_somente_leitura_mostra_o_aberto_sem_link`: com `'readonly' => true` o protocolo aparece e `$parent.select(` não.
  - `test_sem_acesso_nao_mostra_setor_nem_link`: atendente de outro setor; ticket em atendimento de setor "Setor Reservado" → vê o protocolo, não vê `Setor Reservado` nem `$parent.select(`.
  - `test_refresh_traz_ticket_novo_e_nao_mexe_na_edicao`: card compacto; cria ticket aberto em B depois do mount; `call('refresh')` mostra o protocolo novo; em outro card, `toggleEdit`, `set('form.name','Digitado')`, `call('refresh')` → `form.name` continua `Digitado` e `editing` é `true`.
- [ ] **Step 2: Rodar e ver falhar**
  Run: `php artisan test --filter=ContactCardCompactTest`
  Expected: os seis novos FALHAM (número ausente, `$parent.select` ausente, setor visível, `refresh` inexistente); os antigos passam.
- [ ] **Step 3: Implementar em `ContactCard`:** `render()` carrega `->with(['sector','channel'])`; busca `$openable = Ticket::query()->readableBy($user)->whereIn('id', $history->modelKeys())->pluck('id')->all()` e `$currentChannelId = Ticket::query()->whereKey($this->currentTicketId)->value('channel_id')`; adiciona `refresh()` com `#[On('echo-private:inbox,.ticket.updated')]` que chama `$this->skipRender()` quando `$this->editing || ! $this->compact`. Usar `assert($user instanceof User)` como no componente antigo.
- [ ] **Step 4: Implementar a view (modo compacto):** por item, `$mine = in_array($past->id, $openable, true)` e `$otherNumber = $currentChannelId !== null && $past->channel_id !== $currentChannelId`.
  - Protocolo: encerrado e `$mine` → link do Histórico (como hoje); aberto e `$mine` e não `$readonly` → `<button type="button" wire:click="$parent.select({{ $past->id }})">`; senão texto.
  - Data e setor: setor só se `$mine`.
  - Linha de estado: aberto + `$otherNumber` → `também tem ticket aberto no número X (estado)` em destaque (`text-warn`); aberto → estado; encerrado → estado e desfecho, mais `· número X` se `$otherNumber`. `X` = `$past->channel?->phoneLabel() ?? $past->channel?->name ?? ''`.
- [ ] **Step 5: Rodar e ver passar**
  Run: `php artisan test --filter=ContactCard`
  Expected: PASS (`ContactCardTest` e `ContactCardCompactTest`, inclusive o teste antigo da lista de anteriores).
- [ ] **Step 6: Commit** `feat: ficha do contato mostra número, destaque e link dos outros tickets`

---

### Task 2: Remover a faixa da coluna do meio

**Files:**
- Delete: `app/Livewire/Inbox/ContactTickets.php`, `resources/views/livewire/inbox/contact-tickets.blade.php`, `tests/Feature/Livewire/ContactTicketsTest.php`
- Modify: `resources/views/livewire/inbox/inbox.blade.php` (linha 237), `docs/superpowers/specs/2026-11-01-dois-numeros-design.md` (item 4 e "Em aberto")
- Test: `tests/Feature/Livewire/InboxGoBackTest.php`

**Interfaces:**
- Consumes: Task 1 (a ficha cobre o que a faixa mostrava).
- Produces: nenhuma classe nova; `Inbox\ContactTickets` deixa de existir.

- [ ] **Step 1: Teste que falha** em `InboxGoBackTest`: `test_coluna_do_meio_nao_repete_os_outros_tickets_do_contato`: dois tickets do mesmo contato; abrir um; `assertDontSee('Outros tickets deste contato')`.
- [ ] **Step 2: Rodar e ver falhar**
  Run: `php artisan test --filter=test_coluna_do_meio_nao_repete`
  Expected: FAIL (a faixa ainda aparece).
- [ ] **Step 3: Remover** a linha `<livewire:inbox.contact-tickets ... />` do `inbox.blade.php`, apagar os três arquivos do componente e ajustar o spec: item 4 passa a dizer que a informação mora no painel "Atendimentos anteriores" e a linha "Em aberto" fica resolvida.
- [ ] **Step 4: Conferir que nada mais referencia o componente**
  Run: `grep -rn "ContactTickets\|contact-tickets" app resources tests routes`
  Expected: sem resultados.
- [ ] **Step 5: Rodar tudo**
  Run: `QUEUE_CONNECTION=database composer test`
  Expected: Pint, Larastan, PHPMD e a suíte inteira verdes (a contagem cai pelos testes do componente apagado).
- [ ] **Step 6: Commit** `refactor: remove a faixa de outros tickets; o painel da ficha cobre`
