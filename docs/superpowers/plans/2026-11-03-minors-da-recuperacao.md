# Minors da recuperação: plano

> **Para quem executa:** use `superpowers:executing-plans` (inline). Passos com caixas `- [ ]`.

**Meta:** fechar os quatro minors deixados na revisão do branch `fix/bot-mensagem-tardia` antes do PR.

**Branch:** `fix/bot-mensagem-tardia`.

## Decisões do dono (chat de 2026-11-03)

- Importação: **teto de 50 mil mensagens**, com aviso no log e no resumo do sino.
- Canal **sem workflow**: a mensagem ao vivo com até 30 min de atraso recebe a resposta de fora do horário (mesma regra do bot).
- A prévia da fila mostra a última mensagem por **horário de envio**.
- O botão Importar agora ganha **seletor próprio** de dias (1, 5, 15 ou 30); o campo da conexão volta a ser desabilitado sem a caixa marcada.

## Decisão minha

- Dedupe exato da edição: coluna `message_edits.action_id` (id do evento de edição) com índice único; editada na migration `000005`, que ainda não foi mesclada nem implantada. Se errado: nenhum custo (só evita reverter o texto num evento repetido tardio).

## Restrições globais

Textos em português; um commit por tarefa; nada na `main`; no fim, suíte, Pint, Larastan e PHPMD limpos.

## Review Focus

1. Evento de edição antigo repetido tardiamente não reverte o texto nem grava versão errada. → Task 1.
2. A resposta de fora do horário continua sem sair para recuperada, importada ou com mais de 30 min de atraso. → Task 2.

---

### Task 1: dedupe exato da edição

**Arquivos:** `database/migrations/2026_11_03_000005_create_message_edits_table.php`, `app/Models/MessageEdit.php`, `app/Services/Inbox/ApplyMessageChange.php`; teste em `tests/Feature/Inbox/MessageChangesTest.php`.

- [ ] **Passo 1: teste que falha.** `test_evento_de_edicao_antigo_repetido_nao_reverte_o_texto`: edição A (v2) e edição B (v3); repetir o evento A → corpo continua v3 e há exatamente 2 versões anteriores (original e v2).
- [ ] **Passo 2: ver falhar**; **Passo 3: implementar** (`action_id` = `Message::waKey($data['id'])` quando houver; se já existe `MessageEdit` com esse `action_id`, devolve `true` sem tocar; mantém a guarda de corpo igual); **Passo 4: ver passar**; **Passo 5: commit** `fix: edição repetida não reverte o texto`.

---

### Task 2: resposta de fora do horário com a mesma tolerância do bot

**Arquivos:** `app/Services/Inbox/AfterHoursReply.php`, `app/Services/Inbox/IngestMessage.php`; testes em `tests/Feature/Inbox/AfterHoursReplyTest.php`.

**Interfaces:** `AfterHoursReply::__invoke(Message $incoming, ?CarbonInterface $freshAt = null)`; a checagem de frescor usa `$freshAt ?? $incoming->sent_at`. `IngestMessage::announce` passa o `$freshAt` que já calcula.

- [ ] **Passo 1: testes que falham.** (a) ao vivo, enviada há 10 min e chegando agora, fora do horário → uma resposta automática; (b) ao vivo com 40 min de atraso → nenhuma; (c) o teste existente de "mensagem antiga reprocessada" segue sem resposta.
- [ ] **Passo 2: ver falhar**; **Passo 3: implementar**; **Passo 4: ver passar** (`tests/Feature/Inbox`); **Passo 5: commit** `fix: resposta de fora do horário para mensagem que chega atrasada ao vivo`.

---

### Task 3: prévia da fila por horário de envio

**Arquivos:** `app/Livewire/Inbox/Inbox.php`; teste em `tests/Feature/Inbox/TranscriptOrderTest.php`.

- [ ] **Passo 1: teste que falha.** Duas mensagens, a de `id` maior com `sent_at` anterior: a prévia mostra o texto da de horário posterior.
- [ ] **Passo 2: ver falhar**; **Passo 3: implementar** (`orderByDesc('sent_at')->orderByDesc('id')` na subconsulta `preview`); **Passo 4: ver passar**; **Passo 5: commit** `fix: prévia da fila por horário de envio`.

---

### Task 4: seletor próprio de dias no botão Importar agora

**Arquivos:** `app/Livewire/Settings/Concerns/RecoversHistory.php`, `resources/views/livewire/settings/channel-card.blade.php`; testes em `tests/Feature/Livewire/ImportNowCardTest.php`.

**Interfaces:** `RecoversHistory::$importDays = 5` validada com `Rule::in([1, 5, 15, 30])` ("Escolha uma das opções."); `importNow()` grava `importDays` em `backfill_days`; o campo `backfillDays` da conexão volta a ter `:disabled="! $backfill"`.

- [ ] **Passo 1: testes que falham.** `importNow` usa `importDays` (15 → `backfill_days = 15`); `importDays` 31 ou 7 é recusado; o campo de dias da conexão fica desabilitado sem a caixa marcada (`assertSeeHtml('disabled')` no estado padrão) e habilitado com ela.
- [ ] **Passo 2: ver falhar**; **Passo 3: implementar** (seletor ao lado do botão); **Passo 4: ver passar**; **Passo 5: commit** `fix: seletor próprio de dias para importar agora`.

---

### Task 5: teto de 50 mil mensagens na importação

**Arquivos:** `app/Services/Inbox/ImportHistory.php`, `app/Jobs/ImportHistoryJob.php`, `app/Notifications/MessagesRecovered.php`, `app/Livewire/Alerts/Bell.php`; testes em `tests/Feature/Recovery/ImportHistoryTest.php`, `ImportHistoryJobTest.php`, `tests/Feature/Alertas/BellTest.php`.

**Interfaces:** `ImportHistory::MAX_MESSAGES = 50000` e `public bool $truncated`; ao passar do teto a leitura para, grava `Log::warning('Importação limitada ao teto de mensagens', …)` e `truncated = true`. `MessagesRecovered(..., int $open = 0, bool $limited = false)` põe `'limited' => true` no payload só quando limitada; o sino acrescenta " · limitada a 50 mil mensagens".

- [ ] **Passo 1: testes que falham.** (a) com o teto reduzido por uma constante acessível ao teste (subclasse com `MAX_MESSAGES` menor) a leitura para, `truncated` é `true` e o log recebe o aviso; (b) abaixo do teto `truncated` é `false`; (c) o job repassa `limited` ao aviso; (d) o sino mostra "limitada".
- [ ] **Passo 2: ver falhar**; **Passo 3: implementar**; **Passo 4: ver passar**; **Passo 5: commit** `feat: teto de mensagens na importação com aviso`.

---

### Task 6: documentação e verificação final

- [ ] `docs/deploy.md` (teto da importação, seletor do botão) e memória do projeto atualizada.
- [ ] Suíte inteira, Pint, Larastan e PHPMD limpos; ler a saída.
- [ ] commit `docs: minors da recuperação`.
