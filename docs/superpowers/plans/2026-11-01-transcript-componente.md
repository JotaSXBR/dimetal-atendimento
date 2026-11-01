# Transcript como componente Livewire Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tirar o transcript do render do `Inbox`, para que digitar `/`, abrir painéis e eventos da lista não consultem nem renderizem as 200 mensagens.

**Architecture:** Componente filho `App\Livewire\Inbox\Transcript` (prop `ticketId`) que consulta as mensagens e inclui o parcial `livewire.inbox.transcript` já existente. O pai deixa de montar `messages`, o filho escuta os eventos do Echo e um evento `transcript-refresh` que o pai dispara depois de enviar ou reenviar.

**Tech Stack:** Laravel 13, Livewire 4 (`#[On]`, `skipRender()`), PHPUnit com `Livewire::test`, Postgres.

**Spec:** `docs/superpowers/specs/2026-11-01-transcript-componente-design.md`

## Global Constraints

- Mesmo HTML e mesma aparência: o parcial `resources/views/livewire/inbox/transcript.blade.php` continua a fonte, e o Histórico segue o incluindo com `readonly => true`.
- Limite de 200 mensagens, em ordem de `id` crescente, como hoje (`latest('id')->limit(200)->get()->reverse()`).
- O filho nunca confia no pai: busca o ticket com `Ticket::readableBy($user)`.
- Commits em português com prefixo, um por tarefa, no branch `perf/transcript-componente`. Sem PR.
- Testes: `QUEUE_CONNECTION=database php artisan test` com Postgres em 127.0.0.1:5432 (container `pg-test`; o `pg-perf` já ocupa a porta 5432, parar antes: `docker rm -f pg-perf`).

## Review Focus

- Atendente sem acesso ao ticket: o filho renderiza vazio, sem vazar mensagens (Tarefa 1).
- Evento de outro ticket não re-renderiza o filho (Tarefa 1).
- Mensagem enviada pelo composer (texto, anexo, voz) e reenvio aparecem na hora, sem depender do Echo (Tarefa 2).
- Trocar de ticket mostra as mensagens do novo ticket (`wire:key` por ticket) (Tarefa 2).
- Ticket com mais de 200 mensagens mostra as 200 últimas, em ordem (Tarefa 1).

---

### Task 1: Componente `Inbox\Transcript`

**Files:**
- Create: `app/Livewire/Inbox/Transcript.php`
- Create: `resources/views/livewire/inbox/messages.blade.php`
- Test: `tests/Feature/Livewire/InboxTranscriptTest.php`

**Interfaces:**
- Produces: `App\Livewire\Inbox\Transcript` com `public int $ticketId`; métodos `refreshForEvent(array $payload = []): void` (listeners `echo-private:inbox,.message.created` e `echo-private:inbox,.message.status`) e `refresh(): void` (listener `transcript-refresh`). View `livewire.inbox.messages`.

- [ ] **Step 1: Escrever os testes que falham** em `InboxTranscriptTest` (RefreshDatabase; usuário ADMIN e um ATENDENTE sem o setor; um ticket com `Channel::factory()`):
  - `test_mostra_as_200_ultimas_mensagens_em_ordem`: 205 mensagens com `body` "msg-N"; `Livewire::actingAs($admin)->test(Transcript::class, ['ticketId' => $t->id])` faz `assertSee('msg-205')`, `assertSee('msg-6')`, `assertDontSee('msg-5<')` (use bodies com sufixo, ex. "msg-5;") e confere a ordem com `assertSeeInOrder(['msg-6;', 'msg-205;'])`.
  - `test_usuario_sem_acesso_ao_ticket_nao_ve_mensagens`: atendente de outro setor no ticket de um setor; `assertDontSee` do body e `assertSet('ticketId', $t->id)` sem lançar exceção.
  - `test_evento_de_outro_ticket_nao_atualiza`: monta, cria mensagem nova "nova;", `->dispatch('echo-private:inbox,.message.created', ['ticket_id' => $outro->id])` e `assertDontSee('nova;')`; depois `->dispatch(..., ['ticket_id' => $t->id])` e `assertSee('nova;')`.
  - `test_transcript_refresh_atualiza`: cria "nova;" e `->dispatch('transcript-refresh')` faz `assertSee('nova;')`.

- [ ] **Step 2: Rodar e ver falhar:** `php artisan test tests/Feature/Livewire/InboxTranscriptTest.php` → FAIL com "Unable to find component" (classe inexistente).

- [ ] **Step 3: Implementar** `Transcript` em `app/Livewire/Inbox/Transcript.php`: `render(): View` busca `Ticket::query()->readableBy($user)->find($this->ticketId)`; sem ticket devolve a view com `messages` vazio; com ticket usa `$ticket->messages()->latest('id')->limit(200)->get()->reverse()`. `refreshForEvent(array $payload = [])` chama `$this->skipRender()` quando `(int) ($payload['ticket_id'] ?? 0) !== $this->ticketId`. `refresh()` é vazio (só força o render). A view `messages.blade.php` envolve `@include('livewire.inbox.transcript', ['messages' => $messages, 'readonly' => false])` na mesma estrutura `div.space-y-2` do pai (a `div` externa com `flex-col-reverse overflow-y-auto` continua no pai).

- [ ] **Step 4: Rodar e ver passar:** mesmo comando → PASS (4 testes).

- [ ] **Step 5: Commit:** `git add app/Livewire/Inbox/Transcript.php resources/views/livewire/inbox/messages.blade.php tests/Feature/Livewire/InboxTranscriptTest.php && git commit -m "feat: componente Transcript com as mensagens do ticket"`

### Task 2: Usar o componente no `Inbox`

**Files:**
- Modify: `app/Livewire/Inbox/Inbox.php` (render, listeners, `resend`)
- Modify: `app/Livewire/Inbox/Concerns/ComposesMessages.php` (`send`, `sendVoice`)
- Modify: `resources/views/livewire/inbox/inbox.blade.php:232-236`
- Modify: `resources/views/livewire/inbox/transcript.blade.php:52,54` (botão Reenviar)
- Test: `tests/Feature/Livewire/InboxTranscriptTest.php` e os testes do `Inbox` que o RED apontar

**Interfaces:**
- Consumes: `Transcript` da Tarefa 1 e o evento `transcript-refresh`.
- Produces: `Inbox::render` sem a chave `messages`; `Inbox::refresh()` só nos eventos `.ticket.updated` e `.message.created`.

- [ ] **Step 1: Escrever os testes que falham** (no mesmo arquivo):
  - `test_digitar_barra_nao_consulta_o_transcript`: com `DB::enableQueryLog()`, `Livewire::actingAs($me)->test(Inbox::class)->call('select', $id)`, depois `DB::flushQueryLog()` e `->set('draft', '/')`; falha se algum `DB::getQueryLog()` tiver `limit 200` (única consulta do transcript).
  - `test_inbox_nao_escuta_message_status`: `assertNotContains('echo-private:inbox,.message.status', array_keys($component->instance()->getEventsBeingListenedFor()))` e o mesmo para `.ticket.updated` com `assertContains`.
  - `test_envio_pelo_composer_aparece_no_transcript`: `Queue::fake()`; `set('draft', 'oi do atendente')->call('send')` faz `assertDispatched('transcript-refresh')` (o filho já montado reage ao evento).
  - `test_reenviar_dispara_refresh`: reaproveite o setup de `OutboxResendTest::failed`; `call('resend', $id, true)` faz `assertDispatched('transcript-refresh')`.

- [ ] **Step 2: Rodar e ver falhar:** `php artisan test tests/Feature/Livewire/InboxTranscriptTest.php` → FAIL nos quatro novos.

- [ ] **Step 3: Implementar.** Em `Inbox::render` remover `'messages'`. Em `inbox.blade.php` trocar o `@include` por `<livewire:inbox.transcript :ticket-id="$ticket->id" :key="'transcript-'.$ticket->id" />` mantendo as `div` de rolagem. Em `Inbox` manter `refresh()` com `#[On]` só em `.ticket.updated` e `.message.created` (remover `.message.status`). Disparar `$this->dispatch('transcript-refresh')` no fim de `send()` (depois do `foreach` de falhas), no fim de `sendVoice()` e em `resend()` quando o resultado for `sent`. No parcial, trocar `wire:click="resend({{ $message->id }}, true)"` por `wire:click="$parent.resend({{ $message->id }}, true)"` nas duas linhas.

- [ ] **Step 4: Rodar a suíte inteira** e consertar os testes que dependem do texto das mensagens depois de uma ação: onde o `assertSee` perder o texto, ler o HTML do filho (`Livewire::test(Transcript::class, ...)`) ou montar o `Inbox` com `select` logo antes do `assertSee`. Esperado: tudo verde.

- [ ] **Step 5: Commit:** `git add -A app resources tests && git commit -m "perf: transcript fora do render do Inbox"`

### Task 3: Medir, verificar e registrar

**Files:**
- Modify: `docs/superpowers/specs/2026-11-01-transcript-componente-design.md` (seção "Resultado da medição")
- Create: `.superpowers/sdd/2026-11-01-transcript-componente/progress.md` (uma linha `Task N: complete` por tarefa)

- [ ] **Step 1:** Repetir a medição local (servidor em `127.0.0.1:8099`, ticket de 300 mensagens do `pg-perf`, o mesmo script de `$refresh` e de digitar `/`). Esperado: `/` e eventos de lista não trazem mais o transcript; registrar antes/depois (ms e KB) na spec.
- [ ] **Step 2:** `vendor/bin/pint --parallel --test`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml` e `QUEUE_CONNECTION=database php artisan test` limpos; ler a saída.
- [ ] **Step 3: Commit:** `git add docs && git commit -m "docs: medição do transcript isolado"`
