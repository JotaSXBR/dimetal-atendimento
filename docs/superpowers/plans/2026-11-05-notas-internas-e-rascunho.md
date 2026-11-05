# Notas internas e rascunho (fatia 4) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, regra do dono) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Atendente escreve nota privada no ticket (seletor no campo de resposta, cartão âmbar na conversa, só o autor apaga) e o rascunho de cada modo sobrevive a troca de ticket e recarga.

**Architecture:** Tabela própria `ticket_notes` (nunca `messages`), gravada por uma ação Livewire `saveNote` que reaproveita a propriedade `draft` do campo; o `Transcript` junta mensagens e notas por horário. Modo (Responder/Nota) e rascunhos vivem num componente Alpine novo (`composerMode`) com `localStorage`.

**Tech Stack:** Laravel 13, Livewire 4, Alpine, Tailwind, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-05-notas-internas-e-rascunho-design.md`

## Global Constraints

- Branch `feat/inbox-notas-e-rascunho` (empilhado em `fix/reacao-na-borda`); sem PR até o dono pedir.
- Nota: texto `required|string|max:4096`; só o autor apaga (admin e supervisor também não); sem edição.
- Nota nunca cria `Message`, job de envio, chamada ao WAHA, nem mexe em `last_outbound_at`/SLA/bot/"humano ativo".
- O campo não chama `typing`/`recording` no modo nota.
- Chave do rascunho: `rascunho:{user_id}:{ticket_id}:{reply|note}` no `localStorage`.
- Arquivos Blade/PHP existentes podem estar em CRLF: editar com `Edit` ou Python `newline=''`. Commits em português com prefixo e linha `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Testes: `php artisan test --filter=<Nome>` com Postgres `pg-test` no ar; Pint em cada tarefa.

## Review Focus

1. Nota jamais vira `Message`/`SendTicketMessageJob`/chamada HTTP (Task 2 afirma `Queue::assertNothingPushed` e `Http::assertNothingSent`).
2. Digitar em modo nota não anuncia "digitando…" ao cliente (guarda `isTrusted && mode === 'reply'`; conferir no navegador).
3. Outro usuário, inclusive admin, não apaga nota alheia (Task 1 e Task 2).
4. Ticket que o usuário não vê não aceita nota; ticket encerrado também não (Task 2).
5. Texto vazio e acima de 4096 recusados com mensagem em `draft`.

## Rulings do plano

- A tela só mostra o campo (e logo o modo nota) a quem pode responder (`canReply`), como hoje; a política `note` do servidor é mais larga (qualquer um que vê o ticket não encerrado). Custo se errado: atendente que só olha o ticket de colega não consegue anotar; abrir o campo para ele é outra tarefa.
- Sem item "Copiar" no cartão da nota (só "Apagar", para o autor). Custo: um clique a menos de conveniência.
- `AttendanceReport` fica de fora: `byAgent` conta mensagens e não há ponto natural. Custo: nota não aparece no relatório.
- Notas não aparecem no Histórico (tela somente leitura): só no transcript do `Inbox`.

---

### Task 1: Tabela, modelo, política e rótulo de auditoria

**Files:**
- Create: `database/migrations/2026_11_05_000002_create_ticket_notes_table.php`, `app/Models/TicketNote.php`, `database/factories/TicketNoteFactory.php`, `app/Policies/TicketNotePolicy.php`
- Modify: `app/Models/Ticket.php` (relação `notes()`), `app/Policies/TicketPolicy.php` (`note`), `app/Support/TicketEventText.php`, `app/Support/TicketTimeline.php` (rótulo `note_deleted`)
- Test: `tests/Feature/Inbox/TicketNoteTest.php`

**Interfaces:**
- Produces: `TicketNote` (`id, ticket_id, user_id, body, created_at`; `ticket(): BelongsTo`, `author(): BelongsTo<User>`; `TicketNote::factory()`), `Ticket::notes(): HasMany`, `TicketPolicy::note(User, Ticket): bool`, `TicketNotePolicy::delete(User, TicketNote): bool`.

- [ ] **Step 1: Escrever o teste (RED)** em `TicketNoteTest`: `test_so_o_autor_apaga_a_nota` (autor `can('delete', $note)` verdadeiro; outro atendente e admin falsos); `test_note_exige_ver_o_ticket_e_nao_encerrado` (atendente de outro setor falso; ticket `ENCERRADO` falso; ticket visível verdadeiro); `test_nota_some_com_o_ticket` (apagar o ticket apaga a nota: `assertDatabaseMissing`).
- [ ] **Step 2: Rodar** `php artisan test --filter=TicketNoteTest`. Expected: FAIL (classe `TicketNote` não existe).
- [ ] **Step 3: Implementar.** Migration `ticket_notes`: `id`, `foreignId('ticket_id')->constrained()->cascadeOnDelete()`, `foreignId('user_id')->constrained()` (sem cascade: o usuário nunca é apagado, só inativado; conferir em `users`), `text('body')`, `timestamp('created_at')`, índice `(ticket_id, created_at)`. Modelo no estilo de `MessageEdit` (`#[Fillable(['ticket_id','user_id','body'])]`, `UPDATED_AT = null`, docblock `@property`). Política: `delete` = `$user->id === $note->user_id`. `TicketPolicy::note` = `$user->active && $ticket->status !== TicketStatus::ENCERRADO && $this->view($user, $ticket)`. Rótulos `note_deleted` ("Nota interna apagada" / "apagou uma nota interna") no mesmo formato dos vizinhos `message_revoked_by_agent`.
- [ ] **Step 4: Rodar** o filtro. Expected: PASS. Pint.
- [ ] **Step 5: Commit** `feat: tabela e política das notas internas`.

### Task 2: Salvar e apagar nota no servidor

**Files:**
- Create: `app/Livewire/Inbox/Concerns/TakesNotes.php`
- Modify: `app/Livewire/Inbox/Inbox.php` (usar o trait, junto de `ComposesMessages`), `app/Livewire/Inbox/Transcript.php` (`deleteNote`)
- Test: `tests/Feature/Inbox/SaveNoteTest.php`

**Interfaces:**
- Consumes: `TicketNote`, `TicketPolicy::note`, `TicketNotePolicy::delete` (Task 1); `reserveSends(int): bool` e `$draft` de `ComposesMessages`; `selected()` e `user()` do `Inbox`.
- Produces: `Inbox::saveNote(): void`; `Transcript::deleteNote(int $noteId): void`.

- [ ] **Step 1: Escrever os testes (RED)** em `SaveNoteTest` (Livewire, `Queue::fake()`, `Http::fake()`):
  - `test_salvar_nota_grava_limpa_o_campo_e_nao_envia_nada`: `set('draft','combinado X')->call('saveNote')` cria `TicketNote` do usuário; `draft` vazio; `Message::count()` igual ao anterior; `Queue::assertNothingPushed()`; `Http::assertNothingSent()`; `last_outbound_at` do ticket inalterado.
  - `test_nota_vazia_ou_longa_demais_e_recusada`: `''` e 4097 caracteres → `assertHasErrors('draft')`, nenhuma nota.
  - `test_nota_em_ticket_encerrado_ou_de_outro_setor_e_recusada` (403 ou `ModelNotFound`, como `send` faz hoje).
  - `test_so_o_autor_apaga_pelo_transcript`: `Livewire::test(Transcript::class, ['ticketId' => …])->call('deleteNote', $id)` apaga e grava `TicketEvent` `note_deleted` com `actor_user_id`; outro usuário (inclusive admin) recebe `assertForbidden` e a nota permanece; id de nota de outro ticket é ignorado.
- [ ] **Step 2: Rodar** `--filter=SaveNoteTest`. Expected: FAIL (`saveNote` não existe).
- [ ] **Step 3: Implementar** `saveNote()` no trait: `selected()`, `authorize('note', $ticket)`, `validate` de `draft` (`required|string|max:4096`, mensagens "Escreva a nota." e "A nota passa de 4096 caracteres."), `reserveSends(1)` (mesma mensagem de pressa do `send`), `TicketNote::create`, `TicketUpdated::dispatch($ticket)`, `reset('draft')`, `dispatch('transcript-refresh')`. `Transcript::deleteNote`: busca a nota com `where('ticket_id', $this->ticketId)`, `authorize('delete', $note)`, apaga, `TicketEvent::create` (type `note_deleted`, actor, payload `['note_id' => id]`), `TicketUpdated::dispatch`.
- [ ] **Step 4: Rodar** `--filter=SaveNoteTest`. Expected: PASS. Pint, Larastan nos arquivos novos.
- [ ] **Step 5: Commit** `feat: salvar e apagar nota interna`.

### Task 3: Notas no transcript (cartão âmbar, ordem por horário)

**Files:**
- Create: `resources/views/livewire/inbox/note.blade.php`
- Modify: `app/Livewire/Inbox/Transcript.php` (`render` passa `notes`), `resources/views/livewire/inbox/messages.blade.php`, `resources/views/livewire/inbox/transcript.blade.php` (junta e despacha para o cartão)
- Test: `tests/Feature/Livewire/InboxNotesTranscriptTest.php`

**Interfaces:**
- Consumes: `Ticket::notes()`, `TicketNote::author()` (Task 1); `Transcript::deleteNote` (Task 2).
- Produces: partial `livewire.inbox.note` (recebe `$note`); variável opcional `$notes` no partial `transcript` (ausente no Histórico, que segue sem notas).

- [ ] **Step 1: Escrever os testes (RED):** `test_nota_aparece_entre_as_mensagens_na_ordem_do_horario` (mensagem 10:00, nota 10:05, mensagem 10:10 → `assertSeeInOrder`); `test_cartao_da_nota_mostra_cadeado_rotulo_autor_e_hora_sem_tiques` (`data-testid="note-card"`, "Nota interna", nome do autor, `amber` na classe, sem `message-time`); `test_apagar_so_aparece_para_o_autor` (`wire:click="deleteNote(` presente para o autor, ausente para o admin); `test_historico_nao_mostra_notas` (partial sem `$notes`).
- [ ] **Step 2: Rodar.** Expected: FAIL.
- [ ] **Step 3: Implementar.** `render`: `notes` = notas do ticket com `author`, restritas a `created_at >= sent_at` da mensagem mais antiga carregada (todas se não houver mensagens), ordenadas por `created_at`. Em `transcript.blade.php`, antes do `@foreach`, `$timeline = $messages->concat($notes ?? collect())->sortBy(fn ($i) => $i instanceof \App\Models\TicketNote ? $i->created_at : $i->sent_at)->values();` e o loop passa a percorrer `$timeline`; no topo do corpo, `@if ($message instanceof \App\Models\TicketNote) @include('livewire.inbox.note', ['note' => $message]) @continue @endif`. `$localIds` continua só de `$messages`. Cartão: `wire:key="note-{id}"`, `data-testid="note-card"`, `bg-amber-50 border border-amber-300`, cadeado (`<flux:icon.lock-closed>`), "Nota interna · {autor} · d/m H:i" (fuso `config('app.display_timezone')`), corpo com `WhatsAppText::html`, botão "Apagar" (`wire:click="deleteNote(id)"` e `wire:confirm`) só se `$note->user_id === auth()->id()`.
- [ ] **Step 4: Rodar** o filtro e `--filter='InboxTranscriptTest|History'`. Expected: PASS. Pint.
- [ ] **Step 5: Commit** `feat: notas internas no transcript`.

### Task 4: Seletor Responder/Nota, cor da barra e rascunhos por modo

**Files:**
- Create: `resources/js/composer-mode.js`
- Modify: `resources/js/app.js` (import), `resources/views/livewire/inbox/inbox.blade.php` (rodapé), `resources/js/typing-presence.js` (ouvir `composer-mode`)
- Test: `tests/Feature/Livewire/InboxComposerModeTest.php`

**Interfaces:**
- Consumes: `Inbox::saveNote`, `Inbox::send` (Task 2 e existente).
- Produces: componente Alpine `composerMode($wire, ticketId, userId)` com `mode` (`'reply'|'note'`), `toggle(to?)`, `addToNote(text)`, `submit()`; evento `window` `composer-mode` (detail = novo modo) e escuta de `note-from-message` (detail = texto), usados pela Task 5.

- [ ] **Step 1: Escrever os testes de view (RED)** (`Livewire::test(Inbox::class)->call('select', …)->assertSeeHtml(…)`): o rodapé tem `x-data="composerMode($wire, {id}, {userId})"` e `wire:key="footer-{id}"`; botões `data-testid="mode-reply"` e `data-testid="mode-note"` ("Nota privada"); texto "Só a equipe vê"; botão "Salvar nota"; `x-on:keydown.alt.p.window`; o formulário usa `x-on:submit.prevent="submit()"` (e não mais `wire:submit="send"`); o textarea tem `$event.isTrusted && mode === 'reply'`. Ticket sem permissão de responder não renderiza o seletor.
- [ ] **Step 2: Rodar.** Expected: FAIL. Antes, `grep -rn 'wire:submit="send"' tests` e atualizar o teste que afirmar o antigo.
- [ ] **Step 3: Implementar** `composer-mode.js` (Alpine.data, padrão de `typing-presence.js`):
  - `key(mode)` = `rascunho:${userId}:${ticketId}:${mode}`; `field()` = `this.$root.querySelector('textarea')`; `save(mode, v)` grava ou remove (vazio) no `localStorage`.
  - `init()`: carrega o rascunho `reply` no campo (se vazio) via `set(value)`: atribui `field.value` e dispara `new Event('input', {bubbles: true})` para o `wire:model` pegar.
  - `toggle(to)`: guarda o texto do campo na chave do modo que sai, carrega o do que entra, troca `mode`, `$dispatch('composer-mode', mode)`.
  - O `x-on:input` do campo (só evento confiável) salva o rascunho do modo atual.
  - `submit()`: `if (uploading > 0) return;` guarda `const sent = this.mode;` chama `$wire.saveNote()` ou `$wire.send()` e, no `.then`, se `$wire.draft === ''`, remove a chave de `sent`.
  - `addToNote(text)`: vai para `note`, acrescenta `text` (em linha nova se já houver texto) e foca o campo.
  Na view: o rodapé ganha `x-data`, `wire:key`, `x-on:keydown.alt.p.window.prevent="toggle()"`, `x-on:note-from-message.window="addToNote($event.detail)"` e `x-bind:class` trocando `bg-surface border-line` por `bg-amber-50 border-amber-300` no modo nota; barra de seletor (dois botões segmentados, com "Só a equipe vê. O cliente não recebe esta nota." em `x-show`); anexar, microfone, assinatura e a barra "Respondendo a…" com `x-show="mode === 'reply'"`; placeholder por modo; botão de envio normal (`x-show` reply) e botão âmbar "Salvar nota" (`x-show` note), ambos `type="submit"`; Enter chama `submit()`. Em `typing-presence.js`, o `div` do campo ganha `x-on:composer-mode.window="if ($event.detail === 'note') stop()"`.
- [ ] **Step 4: Rodar** `--filter='InboxComposerModeTest|InboxTest|InboxVoiceTest|InboxTypingTest'` e `npm run build`. Expected: PASS e build sem erro. Pint.
- [ ] **Step 5: Commit** `feat: seletor de nota privada no campo de resposta e rascunho por modo`.

### Task 5: "Adicionar texto às notas" no menu da bolha

**Files:**
- Modify: `resources/views/livewire/inbox/transcript.blade.php` (item do menu)
- Test: `tests/Feature/Livewire/InboxTranscriptTest.php` (acrescentar)

**Interfaces:**
- Consumes: evento `note-from-message` (Task 4).

- [ ] **Step 1: Escrever os testes (RED):** `test_menu_tem_adicionar_texto_as_notas_so_com_texto_e_com_permissao` (aparece em mensagem com texto de ticket que o usuário responde; ausente em mídia sem legenda, em mensagem apagada e no modo somente leitura); o item usa `$dispatch('note-from-message'` com o corpo da mensagem.
- [ ] **Step 2: Rodar.** Expected: FAIL.
- [ ] **Step 3: Implementar** o item depois de "Copiar", no padrão dos outros (`role="menuitem"`, ícone SVG de nota, `x-on:click="$dispatch('note-from-message', @js($message->body)); open = false"`), dentro de `@can('reply', $message->ticket)` e das mesmas condições de "Copiar" (há texto, tipo não é localização/contato).
- [ ] **Step 4: Rodar** `--filter=InboxTranscriptTest`. Expected: PASS. Pint.
- [ ] **Step 5: Commit** `feat: adicionar texto da mensagem às notas pelo menu da bolha`.

## Depois das tarefas (portões)

Gate 6: `requesting-code-review` (pergunto ao dono sobre revisor; se negar, autorrevisão declarada). Gate 7: suíte inteira com Redis em série, Pint, Larastan e PHPMD lidos. Conferência no navegador (alternar modo, rascunho por modo e por ticket, "digitando" mudo na nota, menu da bolha) no beta junto do teste visual da reação, como o dono pediu. Gate 8: PR só quando o dono pedir.
