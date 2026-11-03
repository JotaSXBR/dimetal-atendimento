# Alerta de queda com tolerância e acabamentos da recuperação: plano

> **Para quem executa:** use `superpowers:executing-plans` (inline). Passos com caixas `- [ ]`.

**Meta:** o alerta de "WhatsApp desconectado" (faixa, som crítico e sino) só dispara quando a queda se confirma, e os pontos menores deixados pela fatia de recuperação ficam resolvidos.

**Arquitetura:** o canal ganha `down_alerted_at` (queda já anunciada neste episódio). Sair de `WORKING` agenda `ConfirmChannelDownJob` para 60 s depois; `SCAN_QR_CODE` e `FAILED` confirmam na hora. A faixa e o contador da barra lateral passam a ler só o que foi confirmado. O "voltou" só sai se o "caiu" saiu.

**Stack:** Laravel 13, Livewire 4, filas em `docker/worker.sh`.

**Base:** branch `feat/recuperacao-historico` (usa `channels.down_since`). Decisões do dono (chat de 2026-11-03): tolerância de **60 s**; **Aguardando QR e Falhou avisam na hora**; **nada na faixa durante a espera**; na importação, a conversa com ticket aberto fica junta (sem código).

## Restrições globais

- Tolerância: **60 s** (`ChannelAlerts::GRACE_SECONDS`). Canal que nunca esteve conectado (`down_since` nulo) continua avisando na hora, como hoje.
- "Voltou" (`ChannelUp`) só se o "caiu" (`ChannelDown`) foi enviado no mesmo episódio.
- Canal desativado nunca alerta. Textos em português; commits com prefixo; um por tarefa; nunca na `main`.
- Testes com `QUEUE_CONNECTION=database php artisan test --parallel`; no fim, Pint, Larastan e PHPMD limpos.

## Review Focus

1. Oscilação de ~1 s (cai e volta) não gera faixa, som nem sino. → Tarefa 1.
2. Duas quedas seguidas dentro dos 60 s: o job da primeira não pode alertar a segunda cedo. → Tarefa 1.
3. Aguardando QR / Falhou alertam na hora e o job tardio não alerta de novo. → Tarefa 1.
4. Importação grande que passa do tempo continua de onde parou, sem duplicar nem avisar pela metade. → Tarefa 4.

---

### Task 1: queda confirmada (tolerância de 60 s)

**Arquivos:**
- Criar: `database/migrations/2026_11_03_000004_add_down_alerted_at_to_channels.php`, `app/Jobs/ConfirmChannelDownJob.php`
- Modificar: `app/Models/Channel.php`, `app/Services/Alerts/AlertDispatcher.php`, `app/Support/ConnectionState.php`, `app/Support/SidebarState.php`
- Testar: `tests/Feature/Alertas/ChannelDownGraceTest.php` (novo); ajustar `tests/Feature/Alertas/DisparosTest.php`, `NotificationsTest.php`, `tests/Feature/Alertas/ConnectionStateTest.php`, `tests/Feature/Livewire/InactiveChannelTest.php` conforme quebrarem

**Interfaces:**
- Produz: `channels.down_alerted_at` (timestamp nulo; cast `datetime`, em `$fillable`, `@property`).
- Produz: `Channel::scopeConfirmedDown(Builder $query): void` = ativo, `status <> WORKING` e (`down_alerted_at` não nulo **ou** `down_since` nulo).
- Produz: `AlertDispatcher::GRACE_SECONDS = 60`; `AlertDispatcher::confirmDown(Channel $channel): void` (atômico: só o primeiro `update ... where down_alerted_at is null and active and status <> WORKING` envia `ChannelDown` para `AlertRecipients::everyone()` e `ChannelStatusChanged`); `channelChanged` passa a: `WORKING` → `ChannelUp` só se `down_alerted_at` estava preenchido (limpa atômico); `SCAN_QR_CODE`/`FAILED` → `confirmDown` na hora; saída de `WORKING` para outro estado → `ConfirmChannelDownJob::dispatch($channel->id)->delay(GRACE_SECONDS)`.
- Produz: `ConfirmChannelDownJob(public int $channelId)`, fila `realtime`: sai se o canal sumiu, está inativo, voltou a `WORKING` ou se `down_since` é nulo ou mais novo que `agora − 60 s`; senão chama `confirmDown`.

- [ ] **Passo 1: testes que falham** em `ChannelDownGraceTest` (`Notification::fake`, `Queue::fake`, `travelTo`):
  - `test_oscilacao_de_1s_nao_avisa_nem_aparece_na_faixa`: conectado → `STARTING` → roda o job pendente 60 s depois já com o canal de volta a `WORKING` → nenhum `ChannelDown`/`ChannelUp`; `Channel::confirmedDown()->count() === 0` o tempo todo.
  - `test_queda_confirma_depois_de_60s`: conectado → `STARTING`; antes dos 60 s `confirmedDown()` vazio; rodar `ConfirmChannelDownJob` com 60 s passados → `ChannelDown` para todos os ativos, `down_alerted_at` preenchido, `confirmedDown()` com o canal.
  - `test_volta_so_avisa_se_a_queda_foi_anunciada`: volta sem anúncio → nenhum `ChannelUp`; volta com anúncio → `ChannelUp` e `down_alerted_at` nulo.
  - `test_job_da_primeira_queda_nao_alerta_a_segunda_cedo`: cai em t0, volta em t0+5, cai em t0+30; o job de t0 roda em t0+60 → sem alerta; o de t0+30 roda em t0+90 → alerta.
  - `test_aguardando_qr_e_falhou_avisam_na_hora_e_o_job_tardio_nao_repete`: `SCAN_QR_CODE` → `ChannelDown` imediato; rodar o job depois → ainda um só envio. Idem `FAILED`.
  - `test_canal_nunca_conectado_continua_na_faixa_na_hora`: factory `STOPPED` (sem `down_since`) está em `confirmedDown()`.
  - `test_canal_desativado_nao_alerta`.
- [ ] **Passo 2: ver falhar** `php artisan test tests/Feature/Alertas/ChannelDownGraceTest.php` → FAIL.
- [ ] **Passo 3: implementar** conforme as Interfaces. `ConnectionState::down()` e `SidebarState::whatsappDown()` usam `Channel::confirmedDown()`.
- [ ] **Passo 4: ajustar os testes antigos** que esperavam o aviso imediato ao sair de `WORKING` (usar `SCAN_QR_CODE` ou avançar o tempo e rodar o job); a regra nova é a do spec do dono, então o teste muda, não a regra.
- [ ] **Passo 5: ver passar** `tests/Feature/Alertas`, `tests/Feature/Livewire`, `tests/Feature/Recovery` → PASS.
- [ ] **Passo 6: commit** `feat: alerta de queda só depois de 60 s (QR e falha na hora)`.

---

### Task 2: textos e documentação

**Arquivos:**
- Modificar: `resources/views/livewire/inbox/transcript.blade.php`, `app/Livewire/Settings/ChannelCard.php`, `README.md`
- Testar: `tests/Feature/Inbox/MessageChangesTest.php`, `tests/Feature/Livewire/RecoverMessagesCardTest.php`

- [ ] **Passo 1: testes que falham.** Mensagem **enviada** apagada mostra "Mensagem apagada no celular" (e a do cliente continua "apagada pelo cliente"); o aviso do botão manual passa a ser "Recuperação pedida. Se já houver uma em andamento neste canal, ela é reaproveitada. O resultado aparece no sino."
- [ ] **Passo 2: ver falhar**; **Passo 3: implementar**; no `README.md`, a linha local do `queue:work` ganha a fila `history` (`redis_history` não se aplica ao dev) e `media`.
- [ ] **Passo 4: ver passar**; **Passo 5: commit** `fix: textos da recuperação e README`.

---

### Task 3: fila própria para a mídia

**Arquivos:**
- Modificar: `app/Services/Inbox/IngestMessage.php`, `docker/worker.sh`, `docs/deploy.md`
- Testar: `tests/Feature/Inbox/RecoveredModeTest.php`, `tests/Feature/Recovery/ImportHistoryTest.php`, `tests/Feature/Inbox/QueueRoutingTest.php`

**Interfaces:** mídia de mensagem recuperada ou importada vai para a fila `media` (`ResolveMessageMediaJob(int, 'media')`); a fila `history` fica só com `RecoverMessagesJob` e `ImportHistoryJob`.

- [ ] **Passo 1: testes que falham.** Onde hoje se espera `history` para a mídia, esperar `media`; `QueueRoutingTest`: `new ResolveMessageMediaJob(1, 'media')` na fila `media` e o `worker.sh` consumindo `media`.
- [ ] **Passo 2: ver falhar**; **Passo 3: implementar** (`worker.sh`: `start --queue=media --tries=3`; `docs/deploy.md`: tabela de filas e o aviso de redeploy do `worker-beta`).
- [ ] **Passo 4: ver passar**; **Passo 5: commit** `feat: fila media separada da fila history`.

---

### Task 4: importação que continua de onde parou

**Arquivos:**
- Modificar: `app/Services/Inbox/ImportHistory.php`, `app/Jobs/ImportHistoryJob.php`
- Testar: `tests/Feature/Recovery/ImportHistoryTest.php`, `tests/Feature/Recovery/ImportHistoryJobTest.php`

**Interfaces:**
- `ImportHistory::__invoke(Channel $channel, ?CarbonImmutable $deadline = null): RecoveryResult`; passado o `$deadline`, para antes do próximo chat e deixa `public bool $complete = false`; sem estourar, `true`.
- `ImportHistoryJob`: `retryUntil()` em 3 h no lugar de `$tries = 1`; `handle` usa prazo de `agora + 780 s`; se `! $import->complete`, soma os totais da rodada num contador de cache (`import:{canal}:messages|conversations|open`, 1 dia) e chama `$this->release(30)` (mantém o lock de unicidade) sem avisar; na rodada que completa, soma, avisa com os totais e limpa o cache. Os totais de conversas podem contar duas vezes um ticket que atravessou duas rodadas (aproximação documentada no código).

- [ ] **Passo 1: testes que falham.** `test_para_no_prazo_e_marca_incompleta` (prazo já vencido: nenhuma mensagem gravada, `complete === false`); `test_continuar_depois_conclui_sem_duplicar` (uma rodada com prazo vencido, outra sem: contagens finais iguais a uma rodada só); job: `test_incompleta_reenfileira_sem_avisar` (`release(30)`, sem notificação) e `test_ultima_rodada_avisa_com_os_totais_acumulados`.
- [ ] **Passo 2: ver falhar**; **Passo 3: implementar**; **Passo 4: ver passar**; **Passo 5: commit** `feat: importação continua de onde parou`.

---

### Task 5: documentação e verificação final

- [ ] **Passo 1:** `docs/deploy.md` (fila `media`, alerta de queda com 60 s, `down_alerted_at`) e `docs/superpowers/specs/waha-config.md` (formato real da listagem: `to: null` e `_data.Info.Chat` nas mensagens enviadas por nós).
- [ ] **Passo 2:** `QUEUE_CONNECTION=database php artisan test --parallel`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml` → tudo limpo; ler a saída.
- [ ] **Passo 3: commit** `docs: alerta de queda, fila media e formato da listagem`.
