# Auditoria P0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, regra do dono). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Apagar diariamente os eventos de `waha_events` com mais de 7 dias, e juntar ao PR o fix do 500 no Histórico.

**Architecture:** Comando Artisan agendado no padrão dos demais expurgos (`PurgeNotificationsCommand`), apagando em lotes por `id`; índice novo em `received_at` para o filtro por data; doc curto com o checklist de produção.

**Tech Stack:** Laravel 13, PostgreSQL 18, PHPUnit (`php artisan test`), Pint, Larastan, PHPMD.

**Spec:** `docs/superpowers/specs/2026-11-13-auditoria-p0-design.md`

## Global Constraints

- Retenção: `RETENTION_DAYS = 7`; compara `received_at < now()->subDays(7)` (estritamente menor).
- Lote padrão: 5000 (`--batch`).
- Agendamento: `dailyAt('03:50')`, `withoutOverlapping(10)`, sem `timezone()` (UTC como `app:purge-media`).
- Commits em português com prefixo (`feat:`, `fix:`, `docs:`); trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Editar com `Edit`/`Write`; nada de Python em heredoc.
- Postgres de teste: `docker start pg-test` (container já existe) e `docker stop pg-test` no fim.

## Review Focus

- Evento exatamente no limite (`received_at = now()->subDays(7)`) fica (comparação `<`).
- Tabela vazia: sai com sucesso e imprime `0`.
- Mais eventos vencidos que o lote: apaga todos (laço até acabar).
- Evento sem canal (`channel_id` nulo) também é apagado.
- Evento recente com o mesmo `waha_event_id` de um apagado não é tocado (a deduplicação do webhook segue valendo).

---

### Task 1: Comando de limpeza, índice e agendamento

**Files:**
- Create: `app/Console/Commands/PurgeWahaEventsCommand.php`
- Create: `database/migrations/2026_11_13_000003_add_received_at_index_to_waha_events.php`
- Create: `tests/Feature/Console/PurgeWahaEventsCommandTest.php`
- Modify: `routes/console.php` (junto de `app:purge-notifications`)
- Modify: `tests/Feature/Console/ScheduleTest.php`

**Interfaces:**
- Produces: `App\Console\Commands\PurgeWahaEventsCommand` (`public const RETENTION_DAYS = 7`, assinatura `app:purge-waha-events {--batch=5000}`, `handle(): int` devolve `self::SUCCESS`).

- [ ] **Step 1: Escrever os testes que falham** em `PurgeWahaEventsCommandTest` (`RefreshDatabase`; helper `event(string $id, CarbonInterface $at, ?int $channelId = null): WahaEvent` com `WahaEvent::create([... 'event' => 'message', 'payload' => ['x' => 1], 'received_at' => $at])`; `congelar o relógio com $this->freezeTime()` para o teste do limite):
  - `test_apaga_so_os_eventos_com_mais_de_7_dias`: cria um de 8 dias, um de 30 dias e um de 6 dias; `artisan('app:purge-waha-events')->expectsOutputToContain('Eventos apagados: 2')->assertSuccessful()`; sobra só o de 6 dias.
  - `test_evento_no_limite_exato_fica`: `freezeTime()`; evento com `received_at = now()->subDays(7)`; roda; `WahaEvent::count() === 1`.
  - `test_apaga_em_varios_lotes`: 5 eventos de 10 dias + 1 recente; `artisan('app:purge-waha-events', ['--batch' => 2])`; sobra 1 e a saída tem `Eventos apagados: 5`.
  - `test_evento_sem_canal_tambem_e_apagado`: um evento de 10 dias com `channel_id` nulo some.
  - `test_sem_nada_a_apagar_sai_com_sucesso`: tabela vazia, `expectsOutputToContain('Eventos apagados: 0')`.
  - `test_recente_com_outro_id_nao_e_tocado`: um vencido `evt_a` e um recente `evt_b`; só `evt_b` sobra.
  Em `ScheduleTest` (padrão do `test_limpeza_de_bancos_guardados_diaria`): `test_limpeza_de_eventos_do_waha_diaria` com `event('app:purge-waha-events')`, `expression === '50 3 * * *'`, `withoutOverlapping === true`.

- [ ] **Step 2: Rodar e ver falhar**
  Run: `docker start pg-test; php artisan test tests/Feature/Console/PurgeWahaEventsCommandTest.php tests/Feature/Console/ScheduleTest.php`
  Expected: FAIL (comando inexistente / não agendado).

- [ ] **Step 3: Implementar `PurgeWahaEventsCommand`** (`handle(): int`): lê `--batch` (inteiro >= 1, senão 5000); laço que seleciona os `id` vencidos (`orderBy('id')->limit($batch)->pluck('id')`), apaga por `whereKey($ids)` via `toBase()` (sem eventos de modelo) e soma; para quando o lote vier menor que `$batch`; `$this->info("Eventos apagados: {$deleted}")`. Descrição em português no padrão dos outros expurgos.

- [ ] **Step 4: Migration** `add_received_at_index_to_waha_events`: `up()` cria `index('received_at')` em `waha_events`; `down()` o remove (`dropIndex(['received_at'])`). Comentar o porquê em uma linha (o índice `(event, received_at)` não serve ao filtro só por data).

- [ ] **Step 5: Agendar** em `routes/console.php`, depois de `app:purge-notifications`: `Schedule::command('app:purge-waha-events')->dailyAt('03:50')->withoutOverlapping(10);`

- [ ] **Step 6: Rodar e ver passar**
  Run: `php artisan test tests/Feature/Console/PurgeWahaEventsCommandTest.php tests/Feature/Console/ScheduleTest.php tests/Feature/Webhooks/WahaWebhookTest.php`
  Expected: PASS.

- [ ] **Step 7: Commit**
  `git add app/Console/Commands/PurgeWahaEventsCommand.php database/migrations/2026_11_13_000003_add_received_at_index_to_waha_events.php routes/console.php tests/Feature/Console/PurgeWahaEventsCommandTest.php tests/Feature/Console/ScheduleTest.php`
  `git commit -m "feat: limpeza diária dos eventos do WAHA com mais de 7 dias"` (com o trailer).

### Task 2: Checklist de produção em `docs/security.md`

**Files:**
- Modify: `docs/security.md` (nova seção curta antes de "Varreduras automáticas")

**Interfaces:**
- Consumes: nenhum.

- [ ] **Step 1: Adicionar a seção "Variáveis de produção"** com a tabela: `BACKUP_RESTORE_UPLOAD=false` (o `pg_restore` executa o arquivo enviado; só origem confiável), `LOG_CHANNEL=stderr`, `LOG_LEVEL=warning` (o beta usa `debug` enquanto está em desenvolvimento), `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `APP_MAINTENANCE_DRIVER=cache` e `APP_MAINTENANCE_STORE=redis`. Mencionar também a retenção do `waha_events` (7 dias, `app:purge-waha-events`) na lista de rotinas.

- [ ] **Step 2: Commit** `docs: checklist de variáveis de produção e retenção do waha_events` (com o trailer).

### Task 3: Juntar o fix do Histórico

**Files:** nenhum novo (merge de `fix/historico-aviso-aba-visivel`, commit `793162d`).

- [ ] **Step 1:** `git merge --no-ff fix/historico-aviso-aba-visivel -m "merge: fix do 500 no Histórico (aviso de aba visível)"` (com o trailer). Sem conflito esperado (arquivos diferentes).
- [ ] **Step 2:** Rodar `php artisan test tests/Feature/History tests/Feature/Inbox/AgentSeenTest.php tests/Feature/Console`. Expected: PASS.
