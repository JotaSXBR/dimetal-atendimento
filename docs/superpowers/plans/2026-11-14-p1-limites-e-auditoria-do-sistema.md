# P1: limites de login e webhook e auditoria do sistema Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, regra do dono). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Limite de login por e-mail, limite do webhook que só conta assinatura inválida, e auditoria do sistema (login, usuários, backup/restauração, configurações) com tela para o admin.

**Architecture:** Dois limitadores ajustados (`FortifyServiceProvider`, `AppServiceProvider`). Auditoria: tabela `audit_logs`, serviço estático `Auditor::record()` que nunca lança, chamado de listeners de autenticação e de cada ponto, e uma página `auditoria/sistema` (componente próprio, abas compartilhadas com a Auditoria de tickets).

**Tech Stack:** Laravel 13, Livewire 4, Flux 2, PostgreSQL 18, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-14-p1-limites-e-auditoria-do-sistema-design.md`

## Global Constraints

- Login: regra atual (5/min por e-mail e IP) mantida; nova regra `Limit::perHour(config('security.login_per_hour_per_email'))` por e-mail em minúsculas e sem espaços; padrão 20 (`env('SECURITY_LOGIN_PER_HOUR', 20)`).
- Webhook: assinatura válida → `Limit::none()`; inválida → `Limit::perMinute(config('security.webhook_invalid_per_minute'))` por IP, padrão 60. Removidas `webhook_per_minute` e `presence_per_minute`.
- Auditoria: retenção `RETENTION_DAYS = 365`; purga diária 03:55 (`dailyAt('03:55')`, `withoutOverlapping(10)`), em lotes de 5000 com teto de 20000 (`--batch`), comparação `created_at < now()->subDays(365)` (estrita).
- `detail` nunca guarda senha nem token; textos da tela nunca mostram JSON.
- Falha ao gravar auditoria: `Log::warning` e segue (nunca lança).
- Nomes (decisão do plano, para não colidir com o model): model `App\Models\AuditLog`; serviço `App\Services\Audit\Auditor`.
- Commits em português com prefixo, trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; editar com `Edit`/`Write`; sem Python em heredoc.
- Testes: `docker start pg-test` antes; `docker stop pg-test` no fim. RED visto antes de cada código. Blade: `grep` pelo Bash; PHP e testes: `codegraph_explore`.

## Review Focus

- E-mail com maiúsculas e espaços conta como o mesmo no teto por hora; outro e-mail não é afetado.
- Assinatura válida nunca recebe 429, mesmo com milhares de requisições; inválida acima do teto recebe 429; algoritmo diferente de sha512, cabeçalho ausente e segredo vazio contam como inválidos.
- A auditoria nunca derruba a ação: falha ao gravar não lança e o login, o backup ou a restauração concluem.
- Nenhum segredo aparece no `detail` nem na tela: a senha digitada num login falho e a senha definida para um usuário nunca são gravadas.
- Login falho com e-mail inexistente ou lixo (acima de 255 caracteres) não quebra a gravação (o e-mail é truncado).

---

### Task 1: Limite de login por e-mail

**Files:**
- Modify: `app/Providers/FortifyServiceProvider.php` (limitador `login`), `config/security.php`, `docs/security.md` (tabela "Limites de requisição")
- Test: `tests/Feature/Security/LoginRateLimitTest.php` (novo)

**Interfaces:**
- Produces: `config('security.login_per_hour_per_email')` (int, padrão 20).

- [ ] **Step 1: Testes que falham.** `test_o_teto_por_hora_vale_para_o_e_mail_mesmo_trocando_de_ip` (`config(['security.login_per_hour_per_email' => 3])`; 3 posts a `/login` com a mesma conta, cada um com `REMOTE_ADDR` diferente via `withServerVariables`, senha errada, esperam 302/422 e o 4º, de outro IP, 429); `test_maiusculas_e_espacos_contam_como_o_mesmo_e_mail` (`Ana@X.com`, ` ana@x.com ` e `ANA@X.COM` somam); `test_outro_e_mail_nao_e_afetado`; `test_a_regra_por_minuto_por_e_mail_e_ip_continua` (6 posts do mesmo IP e e-mail: o 6º dá 429).
- [ ] **Step 2: Rodar e ver falhar.** Run: `docker start pg-test; php artisan test tests/Feature/Security/LoginRateLimitTest.php`. Expected: FAIL nos dois primeiros.
- [ ] **Step 3: Implementar.** `config/security.php`: `'login_per_hour_per_email' => (int) env('SECURITY_LOGIN_PER_HOUR', 20)`. `configureRateLimiting`: `RateLimiter::for('login', fn (Request $request) => [...])` devolvendo a regra atual e `Limit::perHour(config()->integer('security.login_per_hour_per_email'))->by('login-email:'.<e-mail em minúsculas, trim, transliterado>)`. Docs: linha na tabela de limites.
- [ ] **Step 4: Rodar e ver passar**, mais `tests/Feature/Auth`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: teto de logins por hora por e-mail, independente do IP`.

### Task 2: Webhook só conta assinatura inválida

**Files:**
- Create: `app/Services/Waha/WebhookSignature.php`
- Modify: `app/Http/Controllers/Webhooks/WahaWebhookController.php` (usa o serviço no lugar de `signatureIsValid`), `app/Providers/AppServiceProvider.php` (limitador `webhook`), `config/security.php`, `docs/security.md`
- Test: `tests/Feature/Services/WebhookSignatureTest.php` (novo), `tests/Feature/Security/RateLimitTest.php` (reescrever os 3 testes do webhook)

**Interfaces:**
- Produces: `WebhookSignature::valid(Illuminate\Http\Request $request): bool` (estático): falso sem segredo, sem cabeçalho `X-Webhook-Hmac`, ou com `X-Webhook-Hmac-Algorithm` diferente de `sha512` (padrão `sha512`); compara `hash_equals` do HMAC SHA-512 do corpo bruto com o segredo de `WahaSettings::webhookSecret()`. `config('security.webhook_invalid_per_minute')` (int, 60).

- [ ] **Step 1: Testes que falham.** `WebhookSignatureTest`: válida; corpo diferente; sem cabeçalho; algoritmo `sha256`; segredo vazio. `RateLimitTest` (usar o helper de assinatura do `WahaWebhookTest`): `test_assinatura_valida_nunca_recebe_429` (com `webhook_invalid_per_minute = 3`, 10 eventos válidos com ids diferentes, todos 200), `test_assinatura_invalida_leva_429_acima_do_teto_por_ip` (4º inválido 429, 401 antes), `test_invalidas_de_um_ip_nao_gastam_o_de_outro`, `test_valida_nao_gasta_o_teto_das_invalidas` (3 inválidas, 1 válida 200, a próxima inválida 429), `test_presence_update_valido_tambem_passa_sem_limite`.
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Services/WebhookSignatureTest.php tests/Feature/Security/RateLimitTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** o serviço (mover a lógica do controller), trocar o controller para chamá-lo, e o limitador `webhook` em `AppServiceProvider::configureRateLimiting` (`WebhookSignature::valid($request) ? Limit::none() : Limit::perMinute(...)->by('webhook-invalid:'.$request->ip())`). Config: remover as duas chaves antigas, acrescentar a nova. Docs: tabela de limites.
- [ ] **Step 4: Rodar e ver passar**, mais `tests/Feature/Webhooks`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: o limite do webhook só conta assinatura inválida`.

### Task 3: Base da auditoria (tabela, serviço, textos e retenção)

**Files:**
- Create: `database/migrations/2026_11_14_000001_create_audit_logs_table.php`, `app/Models/AuditLog.php`, `app/Enums/AuditAction.php`, `app/Services/Audit/Auditor.php`, `app/Support/AuditLogText.php`, `app/Console/Commands/PurgeAuditLogsCommand.php`
- Modify: `routes/console.php`, `docs/security.md` (tabela de retenção)
- Test: `tests/Feature/Audit/AuditorTest.php`, `tests/Feature/Audit/AuditActionTest.php`, `tests/Feature/Console/PurgeAuditLogsCommandTest.php`, `tests/Feature/Console/ScheduleTest.php`

**Interfaces:**
- Produces:
  - Tabela `audit_logs`: `id`, `actor_user_id` (nulo, `nullOnDelete`), `actor_label` (string 255 nulo), `action` (string 60), `subject` (string 255 nulo), `ip` (string 45 nulo), `detail` (json nulo), `created_at`; índices `created_at` e `(action, created_at)`.
  - `AuditAction` (enum string com os 19 valores do spec, ex.: `AuditAction::Login = 'auth.login'`), `label(): string` (português) e `group(): string` (`Acesso`, `Usuários`, `Backup`, `Configurações`); `AuditAction::groups(): list<string>`.
  - `Auditor::record(AuditAction $action, ?User $actor = null, ?string $subject = null, array $detail = [], ?string $actorLabel = null): void` — `actor_label` padrão `"{nome} <{email}>"` do ator; IP de `request()->ip()` quando há request; trunca `subject` e `actor_label` a 255; nunca lança.
  - `AuditLogText::sentence(AuditLog $log): string` — frase em português por ação (ex.: "Ana criou o usuário Beto (atendente)"), sem JSON; ação desconhecida vira o próprio `action`.

- [ ] **Step 1: Testes que falham.** `AuditorTest`: grava ator, rótulo, IP (`$this->get`/`withServerVariables` ou `request()->server`), assunto e detalhe; sem ator e com `actorLabel` explícito; `subject` de 400 caracteres é truncado; **falha de gravação não lança** (forçar com `detail` que não serializa, `['x' => NAN]`, e `Log::spy()` vê `warning`). `AuditActionTest`: todo caso tem `label()` e `group()` não vazios e os grupos são os 4. `AuditLogTextTest` (no mesmo arquivo ou em `tests/Unit`): uma frase por ação, nunca contém `{`, e `detail` com chave `password` não aparece. `PurgeAuditLogsCommandTest` no molde de `PurgeWahaEventsCommandTest`: só apaga com mais de 365 dias, no limite exato fica, lotes (`--batch=2`), teto do lote (20000), tabela vazia. `ScheduleTest`: `app:purge-audit-logs` às `55 3 * * *`, sem sobreposição.
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Audit tests/Feature/Console/PurgeAuditLogsCommandTest.php tests/Feature/Console/ScheduleTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** migration, model (`#[Fillable]`, `UPDATED_AT = null`, `casts` com `detail` array), enum, `Auditor` (`try/catch Throwable` → `Log::warning`), `AuditLogText`, comando (copiar a estrutura de `PurgeWahaEventsCommand`) e agendamento em `routes/console.php`. Docs: linha na tabela de retenção.
- [ ] **Step 4: Rodar e ver passar**, mais `tests/Feature/Console`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: base da auditoria do sistema (tabela, serviço, textos e retenção de 365 dias)`.

### Task 4: Login, falha de login e logout

**Files:**
- Create: `app/Listeners/RecordAuthEvents.php`
- Test: `tests/Feature/Audit/AuthAuditTest.php`

**Interfaces:**
- Consumes: `Auditor::record`, `AuditAction::Login|LoginFailed|Logout`.
- Produces: o listener é descoberto pelo Laravel (métodos tipados para `Illuminate\Auth\Events\Login`, `Failed` e `Logout`); confirme a descoberta nos testes, e só registre em `AppServiceProvider` se não for descoberto.

- [ ] **Step 1: Testes que falham.** Pelo HTTP: `test_login_grava_quem_entrou_e_o_ip`; `test_senha_errada_grava_a_falha_com_o_e_mail_e_sem_a_senha` (o `detail` e o `subject` não contêm a senha digitada); `test_e_mail_inexistente_ou_gigante_nao_quebra` (e-mail de 400 caracteres: linha gravada, `subject` truncado em 255); `test_logout_grava`; `test_login_de_usuario_inativo_conta_como_falha`.
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Audit/AuthAuditTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** o listener. `Failed`: `subject` = e-mail digitado (`$event->credentials['email'] ?? ''`), sem ator; `Login`/`Logout`: ator = `$event->user`.
- [ ] **Step 4: Rodar e ver passar**, mais `tests/Feature/Auth`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: auditoria de login, falha de login e logout`.

### Task 5: Auditoria de usuários

**Files:**
- Modify: `app/Livewire/Settings/Users.php` (`persist`, `toggleActive`)
- Test: `tests/Feature/Audit/UserAuditTest.php`

**Interfaces:**
- Consumes: `Auditor::record`.
- Produces: ações `user.created` (detalhe: papel), `user.updated` (detalhe: `fields` com os nomes dos campos alterados entre nome, e-mail, `can_transfer` e setores), `user.role_changed` (detalhe: `from`, `to`), `user.password_set` (sem detalhe), `user.deactivated`, `user.activated`; `subject` = nome do usuário alvo; ator = quem está logado.

- [ ] **Step 1: Testes que falham**, no molde de `UsersPageTest`: criar grava só `user.created` (a senha inicial não gera `user.password_set`); editar nome grava `user.updated` com `fields = ['name']`; sem mudança nenhuma não grava; trocar o papel grava `user.role_changed` com `from`/`to`; definir senha na edição grava `user.password_set` e a senha nunca aparece em nenhuma linha; desativar e ativar gravam; ninguém grava como ator o usuário errado (supervisor edita atendente: ator é o supervisor).
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Audit/UserAuditTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** em `persist` (depois da transação) e `toggleActive`. Para os campos alterados use `getChanges()` do modelo antes do `sync` de setores e o retorno do `sync()`.
- [ ] **Step 4: Rodar e ver passar**, mais `tests/Feature/Livewire/UsersPageTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: auditoria de criação e mudança de usuários`.

### Task 6: Auditoria de backup e restauração

**Files:**
- Modify: `app/Http/Controllers/BackupDownloadController.php`, `app/Livewire/Settings/Backups.php` (`runNow`, `start`), `app/Services/Backup/Restore/RestoreDatabase.php` (`log`)
- Test: `tests/Feature/Audit/BackupAuditTest.php`

**Interfaces:**
- Consumes: `Auditor::record(..., actorLabel:)`.
- Produces: `backup.downloaded` (subject = nome do arquivo), `backup.manual_requested`, `restore.requested` (subject = origem), `restore.switched` (pedido de voltar ao banco guardado; subject = nome do banco), `restore.succeeded` e `restore.failed` (de `RestoreDatabase::log`: `result` `ok` ou `falhou`; ator = `$run->request->actor` como `actorLabel`; detalhe: `mode`, `source`, `message` — a mensagem pública que a página de andamento já mostra).

- [ ] **Step 1: Testes que falham**: download grava (e 404 não grava); `runNow` grava só quando enfileira (não quando recusado por restauração em andamento); `restore()` grava `restore.requested` com o admin como ator e só quando `start` não é bloqueado; `switchBack()` grava `restore.switched`; teste de `RestoreDatabase` (no molde dos testes existentes de `Backup/Restore`) com sucesso grava `restore.succeeded` e com falha grava `restore.failed`, ambos com `actor_label` igual ao ator do pedido e sem a saída bruta do `pg_restore`.
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Audit/BackupAuditTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** as chamadas nos pontos citados (`start` grava depois de passar pelo `blocker()` e antes do `redirect`; `log()` grava além do `Log::notice`).
- [ ] **Step 4: Rodar e ver passar**, mais `tests/Feature/Backup tests/Feature/Livewire/BackupsPageTest.php tests/Feature/Livewire/BackupsRestoreTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: auditoria de backup e restauração`.

### Task 7: Auditoria de configurações sensíveis

**Files:**
- Modify: `app/Http/Controllers/GoogleConnectionController.php` (`callback`), `app/Livewire/Settings/GoogleContacts.php` (`disconnect`), `app/Livewire/Settings/Channels.php` (`addChannel`), o componente que dispara `channel-deleted` (`ChannelCard`; localizar o método de apagar o canal), `app/Livewire/Settings/Retention.php` (`purgeNow`)
- Test: `tests/Feature/Audit/SettingsAuditTest.php`

**Interfaces:**
- Produces: `google.connected` (subject = e-mail da conta Google; o token nunca entra), `google.disconnected`, `channel.created` (subject = nome da sessão), `channel.deleted` (subject = nome do canal), `retention.purge_requested`.

- [ ] **Step 1: Testes que falham**: um por ação, com o admin como ator; conexão Google que falha (callback com `error`) não grava `google.connected`; criar canal inválido (sessão duplicada) não grava; o `detail`/`subject` do Google nunca contém o token.
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Audit/SettingsAuditTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** as chamadas depois do sucesso de cada ação.
- [ ] **Step 4: Rodar e ver passar**, mais `tests/Feature/Google tests/Feature/Livewire/ChannelsScreenTest.php tests/Feature/Livewire/RetentionPageTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: auditoria de Google, canais e expurgo manual`.

### Task 8: Tela "Sistema" e abas

**Files:**
- Create: `app/Livewire/Audit/SystemLog.php`, `resources/views/livewire/audit/system-log.blade.php`, `resources/views/components/audit-tabs.blade.php`
- Modify: `routes/web.php` (rota `auditoria/sistema`, nome `auditoria.sistema`, `can:admin`), `resources/views/livewire/audit/index.blade.php` (abas), `app/Support/Navigation.php` (o item Auditoria passa a casar `auditoria*`), `docs/security.md` (seção "Auditoria do sistema")
- Test: `tests/Feature/Audit/SystemLogPageTest.php`

**Interfaces:**
- Consumes: `AuditLog`, `AuditLogText::sentence`, `AuditAction::groups()`, `Audit\Index::PERIODS`.
- Produces: `SystemLog` (`#[Url]` `period` padrão `7`, `group` vazio, `userId` nulo; `PER_PAGE = 25`; `mount` faz `authorize('admin')`); `<x-audit-tabs active="tickets|sistema" />`.

- [ ] **Step 1: Testes que falham**: só admin entra (supervisor e atendente 403); lista do mais novo ao mais velho com data no fuso de exibição, quem, frase e IP; filtro de período (`hoje` começa à meia-noite de São Paulo, como na Auditoria de tickets), de grupo e de usuário; 25 por página (marcador: horário do evento com `travelTo`, como `test_pagina_de_25_em_25`); nunca mostra `{` nem `detail` bruto; ator apagado aparece pelo `actor_label`; a página de tickets mostra as duas abas e a de sistema também; o item "Auditoria" do menu segue ativo na página nova.
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Audit/SystemLogPageTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** componente (`WithPagination`, `resetPage` ao mudar filtro), view e parcial de abas, rota e `Navigation`. Docs: seção curta (o que é gravado, retenção, quem vê).
- [ ] **Step 4: Rodar e ver passar**, mais `tests/Feature/Livewire/AuditPageTest.php tests/Feature/History/HistoryTimelineTest.php tests/Feature/Layout`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: tela de auditoria do sistema para o admin`.
