# Acabamentos depois da revisão no beta: plano

> **Para quem executa:** use `superpowers:executing-plans` (inline). Passos com caixas `- [ ]`.

**Meta:** fechar o que a revisão no beta e o dono apontaram depois do PR #65: ordem das mensagens, prévia da conversa apagada, versões anteriores de uma mensagem editada, botão para importar o histórico de novo e os dois minors da recuperação.

**Arquitetura:** mudanças pequenas e independentes sobre o que já existe (`Transcript`, `ApplyMessageChange`, `ImportHistoryJob`, `WahaMessages`, `RecoverMessagesJob`); uma tabela nova (`message_edits`).

**Branch:** `fix/bot-mensagem-tardia` (a mesma da correção do bot; pedido do dono).

## Decisões do dono (chat de 2026-11-03)

- A mensagem editada mostra o texto novo **e** as versões anteriores, juntas na bolha (o desenho original previa isso; o código só guardava a nova).
- Botão **"Importar histórico agora"** no card do canal; a importação hoje roda uma vez só por canal.
- Ordem da conversa por horário de envio; prévia da fila marca a mensagem apagada; os dois minors entram.

## Decisões minhas (custo se erradas)

- O botão reaproveita o campo de dias (1 a 30) e o `ImportHistoryJob` (idempotente), com confirmação. Se errado: ajustar o texto ou a janela.
- Todas as versões anteriores aparecem (ordem cronológica, em cinza). Se errado: mostrar só a original.
- Listagem do WAHA em fatias de 24 h, com aviso no log se uma fatia bater o teto de 20 mil. Se errado: um dia muito cheio perde mensagens com aviso no log.

## Restrições globais

- Textos em português; commits com prefixo, um por tarefa; nada na `main`.
- `QUEUE_CONNECTION=database php artisan test --parallel`; no fim, Pint, Larastan e PHPMD limpos.

## Review Focus

1. Mensagem editada duas vezes mostra as duas versões anteriores, e o mesmo evento repetido pelo WAHA não duplica a versão. → Task 2.
2. Botão de importação com canal desconectado ou já com importação em andamento não duplica nem perde o pedido. → Task 3.
3. Recuperação automática que cai num instante de oscilação reenfileira em vez de sumir. → Task 4.

---

### Task 1: ordem por horário de envio e prévia da apagada

**Arquivos:** `app/Livewire/Inbox/Transcript.php`, `app/Livewire/History/Index.php`, `app/Livewire/Inbox/Inbox.php` (`PREVIEW_SQL`); testes em `tests/Feature/Inbox/MessageChangesTest.php` (ou novo `TranscriptOrderTest.php`) e `tests/Feature/Livewire`.

- [ ] **Passo 1: testes que falham.** (a) Duas mensagens do mesmo ticket, a de `id` maior com `sent_at` **anterior**: o `Transcript` mostra a de horário anterior primeiro (e a tela do Histórico também). (b) Com mais de 200 mensagens, as 200 mostradas são as de maior `sent_at`. (c) A prévia da fila de uma mensagem com `revoked_at` começa com "Apagada · " e o texto.
- [ ] **Passo 2: ver falhar**; **Passo 3: implementar** (`orderByDesc('sent_at')->orderByDesc('id')` antes do `limit`, depois `reverse()`; `PREVIEW_SQL` ganha o prefixo quando `revoked_at is not null`); **Passo 4: ver passar**; **Passo 5: commit** `fix: conversa ordenada por horário de envio e prévia da apagada`.

---

### Task 2: versões anteriores da mensagem editada

**Arquivos:** criar `database/migrations/2026_11_03_000005_create_message_edits_table.php`, `app/Models/MessageEdit.php`; modificar `app/Models/Message.php` (`edits()`), `app/Services/Inbox/ApplyMessageChange.php`, `app/Livewire/Inbox/Transcript.php` e `app/Livewire/History/Index.php` (`with('edits')`), `resources/views/livewire/inbox/transcript.blade.php`; testes em `tests/Feature/Inbox/MessageChangesTest.php`.

**Interfaces:** `message_edits` (`id`, `message_id` FK com cascata, `previous_body` text, `edited_at` timestamp, timestamps); `Message::edits(): HasMany` (por `id`); `ApplyMessageChange::edited` guarda o corpo anterior **antes** de trocar e ignora um evento cujo `body` já é o atual.

- [ ] **Passo 1: testes que falham.** `test_edicao_guarda_a_versao_anterior`; `test_duas_edicoes_guardam_as_duas_anteriores_em_ordem`; `test_o_mesmo_evento_repetido_nao_duplica_a_versao`; tela: a bolha mostra o texto novo, "editada" e as versões anteriores (ex.: "Antes: texto errado").
- [ ] **Passo 2: ver falhar**; **Passo 3: implementar**; **Passo 4: ver passar** (inclui `tests/Feature/Inbox`, `History`); **Passo 5: commit** `feat: mensagem editada mostra as versões anteriores`.

---

### Task 3: botão "Importar histórico agora"

**Arquivos:** `app/Livewire/Settings/ChannelCard.php`, `resources/views/livewire/settings/channel-card.blade.php`; testes em `tests/Feature/Livewire/ImportNowCardTest.php` (novo).

**Interfaces:** `ChannelCard::importNow(): void` — valida `backfillDays` (1 a 30, mesma mensagem da conexão), só para canal ativo e `WORKING`, grava `backfill_days`, reserva `backfill_last_run_at = now()` e despacha `ImportHistoryJob::dispatch($id)`; mostra "Importação pedida. Se já houver uma em andamento neste canal, ela é reaproveitada. O resultado aparece no sino."

- [ ] **Passo 1: testes que falham.** despacha o job e grava os dias; recusa `0` e `31`; canal desconectado não mostra o botão nem despacha; botão com `wire:confirm`.
- [ ] **Passo 2: ver falhar**; **Passo 3: implementar** (botão ao lado do campo de dias, visível com o canal conectado); **Passo 4: ver passar**; **Passo 5: commit** `feat: botão para importar o histórico de novo`.

---

### Task 4: minors da recuperação

**Arquivos:** `app/Services/Waha/WahaMessages.php`, `app/Jobs/RecoverMessagesJob.php`; testes em `tests/Feature/Services/WahaMessagesTest.php`, `tests/Feature/Recovery/RecoverMessagesJobTest.php`.

- [ ] **Passo 1: testes que falham.** (a) `between` com janela de 3 dias faz as requisições por fatias de 24 h (`gte`/`lte` contíguos, sem sobreposição) e entrega as mensagens de todas; uma fatia que bate o teto de 200 páginas escreve um `Log::warning`. (b) `RecoverMessagesJob` com canal ativo e fora de `WORKING` chama `release(60)` (e não grava nada); canal inativo ou apagado continua saindo sem reenfileirar; `retryUntil` de 1 h no lugar de `$tries = 1`.
- [ ] **Passo 2: ver falhar**; **Passo 3: implementar**; **Passo 4: ver passar**; **Passo 5: commit** `fix: listagem em fatias de 24 h e recuperação que reenfileira`.

---

### Task 5: documentação e verificação final

- [ ] `docs/deploy.md` (botão de importar, versões da edição e a migration nova) e a memória do projeto atualizada.
- [ ] Suíte inteira, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml` → limpos; ler a saída.
- [ ] commit `docs: acabamentos depois da revisão no beta`.
