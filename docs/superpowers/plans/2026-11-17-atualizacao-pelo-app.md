# Atualização pelo app: plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** aviso de versão nova e botão Atualizar em Configurações > Sistema, que confere tudo, faz backup, grava no Coolify o
compose e a versão da Release, reinicia, fecha ou devolve sozinho; mais o ajuste do workflow da Release, a fumaça de atualização e a
documentação.

**Architecture:** serviços em `app/Services/Update/` (versão instalada, feed de Releases, cliente do Coolify, pré-voo, aplicação),
estado em `system_updates`, um job (`ApplyUpdateJob`, fila `history`) que só termina em `reiniciando`, e um comando agendado a cada
minuto (`app:update-watch`, no scheduler) que fecha, devolve ou dá por falho. Tela Livewire `Settings\System`.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2, PostgreSQL 18, Redis 8, API v1 do Coolify, API do GitHub, GHCR.

**Spec:** `docs/superpowers/specs/2026-11-17-atualizacao-pelo-app-design.md` (aprovada pelo dono em 17/11). Ler as seções "Fatos
provados no Coolify" e "Decisões minhas depois da revisão do construtor" antes de cada tarefa que toca o Coolify.

## Global Constraints

- Instalação de terceiro e production = **Service** com o compose da Release colado; staging = Application (não passa pelo botão).
- Token do Coolify: permissões `read`, `read:sensitive`, `write`, `deploy`; nunca `delete`; gravado como `Setting` tipo `secret`;
  nunca em log, auditoria, exceção mostrada ou HTML.
- API do Coolify (fatos de 17/11): `GET /services/{uuid}` traz `docker_compose_raw` (só com `read:sensitive`); `PATCH /services/{uuid}`
  com `docker_compose_raw` em base64; `POST /services/{uuid}/envs` cria (409 se existe); `PATCH /services/{uuid}/envs` atualiza (404
  se falta); sempre `is_preview: false`; `POST /services/{uuid}/restart?latest=true` puxa e reinicia (200 "queued", sem id);
  prova de `deploy` = `POST /services/<uuid-inexistente>/restart` → 404 tem, 403 não tem.
- Nada é apagado no Coolify: devolver = regravar compose e `FLUXIE_VERSION` guardados.
- Versão de Release = semver `X.Y.Z` com sufixo de pré-release opcional (`0.0.0-teste.1`); `sha-…`, `edge`, `dev` = desenvolvimento.
- Consulta ao GitHub: `User-Agent: Fluxie-Inbox`, sem autenticação, nenhum dado da instalação; `/releases/latest`, ou `/releases`
  com `FLUXIE_UPDATE_PRERELEASE=true`.
- Toda mudança de dados vai em migração (regra nova, vale para este branch).
- Regra do compose: o compose novo precisa funcionar aplicado por cima de uma instalação existente (variável nova com padrão).
- CSP `enforce`; convenções de tela (`tests/Unit/ViewConventionsTest.php`); textos em português no tom das telas atuais.
- Testes com Postgres; HTTP externo sempre com `Http::fake` (nenhum teste chama a rede).

## Review Focus

1. Token sem `deploy` ou sem `read:sensitive`: o pré-voo para antes do backup e nada muda no Coolify (Tarefa 5).
2. Job morto no meio (`conferindo`, `backup`, `aplicando`): o vigia fecha ou devolve e a trava não fica presa (Tarefa 6).
3. Reinício que falha depois de gravar compose e versão: devolve os dois e grava `revertida` (Tarefa 5).
4. Pull que falha (imagem não chega): o vigia percebe pela versão em 10 min, devolve e avisa (Tarefa 6).
5. Instalação sem `FLUXIE_VERSION` gravada no Service (production na 0.1.0): o pré-voo cria com a versão rodando; a volta regrava
   (Tarefa 5).

---

### Task 1: versão da instalação

**Files:** Create `config/fluxie.php`, `app/Services/Update/Version.php`, `app/Services/Update/InstalledVersion.php`,
`tests/Unit/Update/VersionTest.php`, `tests/Feature/Update/InstalledVersionTest.php`. Modify `docker/Dockerfile` (estágio `runtime`:
`ENV FLUXIE_BUILD=${VERSION}`), `docker-compose.yml` (âncora `x-app-env`: `FLUXIE_VERSION: ${FLUXIE_VERSION:-0.1.0}`, mesma
expressão da imagem), `.github/workflows/release-image.yml` (o `sed` do job `compose-asset` troca as duas ocorrências e confere 2).

**Interfaces — Produces:**
- `config('fluxie.version')` ← `env('FLUXIE_VERSION')`; `config('fluxie.build')` ← `env('FLUXIE_BUILD', 'dev')`;
  `config('fluxie.releases_repo')` ← `env('FLUXIE_RELEASES_REPO', 'fluxie-tech/fluxie-inbox')`;
  `config('fluxie.update_prerelease')` ← `env('FLUXIE_UPDATE_PRERELEASE', false)`; `config('fluxie.image')` = `ghcr.io/fluxie-tech/fluxie-inbox`.
- `final readonly class Version { static parse(string): ?self; isRelease(): bool; greaterThan(self): bool; __toString() }` — semver
  2.0 (núcleo numérico; pré-release menor que o núcleo igual; identificadores comparados um a um, números antes de texto); aceita
  prefixo `v`.
- `final class InstalledVersion { current(): string; release(): ?Version }` — `fluxie.version` se preenchida, senão `fluxie.build`.

- [ ] **Step 1: testes que falham** — `VersionTest`: `test_ordem_semver` com
  `['0.1.0' < '0.2.0', '0.2.0' < '0.10.0', '1.0.0-teste.1' < '1.0.0', '0.0.0-teste.1' < '0.0.0-teste.2', '0.0.0-teste.2' < '0.0.0-teste.10']`;
  `test_desenvolvimento_nao_e_release` (`sha-abc`, `edge`, `dev` → `parse` null); `test_prefixo_v` (`v0.2.0` = `0.2.0`).
  `InstalledVersionTest`: versão do env vence o build; sem env cai no build; `sha-…` → `release()` null.
- [ ] **Step 2:** rodar e ver falhar.
- [ ] **Step 3:** implementar; ajustar compose, Dockerfile e o `sed` do workflow (`grep -c` = 2).
- [ ] **Step 4:** rodar os testes e a fumaça do compose local (`bash docker/smoke/compose-smoke.sh`) → passam.
- [ ] **Step 5:** commit `feat: a instalação sabe a própria versão`.

### Task 2: consulta diária às Releases

**Files:** Create `app/Services/Update/ReleaseFeed.php`, `app/Settings/UpdateSettings.php`, `app/Notifications/NewVersionAvailable.php`,
`app/Console/Commands/CheckReleasesCommand.php` (`app:check-releases`), testes em `tests/Feature/Update/`. Modify `routes/console.php`
(diário, 07:40 do fuso de exibição, só se `UpdateSettings::checkEnabled()`).

**Interfaces — Consumes:** `Version`, `InstalledVersion`. **Produces:**
- `UpdateSettings` (em `settings`): `checkEnabled(): bool` (padrão true) / `setCheckEnabled(bool)`; `lastCheck(): ?array{at: CarbonImmutable, ok: bool, error: ?string}`;
  `latest(): ?array{tag: string, published_at: string, notes_url: string, compose_url: string}`; `notifiedVersion(): ?string`;
  `panelUrl(): ?string` / `setPanelUrl(?string)`; `token(): ?string` / `setToken(?string)` (tipo `secret`) / `tokenSavedAt(): ?CarbonImmutable`.
- `ReleaseFeed::refresh(): void` — chama o GitHub (`releases/latest` ou `releases` filtrando rascunhos e, sem pré-release ligada, as
  pré-releases), grava `latest` com a URL do asset `docker-compose.yml`, grava `lastCheck`; se `latest` é release maior que a
  instalada e ainda não avisada, manda `NewVersionAvailable` aos admins (`AlertRecipients::admins()`) e grava `notifiedVersion`.

- [ ] **Step 1: testes que falham** (`Http::fake`): avisa uma vez por versão; não avisa versão igual/menor; ignora pré-release por
  padrão e aceita com a opção; falha de rede grava `lastCheck.ok=false` sem alerta; release sem o asset `docker-compose.yml` é
  ignorada; o request leva `User-Agent: Fluxie-Inbox` e nenhum outro dado; consulta desligada → o agendamento não roda.
- [ ] **Step 2-4:** ver falhar, implementar, passar.
- [ ] **Step 5:** commit `feat: consulta diária às Releases e aviso de versão nova`.

### Task 3: cliente do Coolify

**Files:** Create `app/Services/Update/Coolify.php`, `app/Services/Update/CoolifyException.php`, fixtures
`tests/Fixtures/coolify/*.json` (formas das respostas de 17/11: service com `docker_compose_raw`, envs, 403 "Missing required
permissions: write", 403 "Missing required permissions: deploy", 404 "Service not found.", 409 de env existente, 200 "Service
restarting request queued."), `tests/Feature/Update/CoolifyTest.php`.

**Interfaces — Consumes:** `UpdateSettings::panelUrl/token`, `env('COOLIFY_RESOURCE_UUID')` via `config('fluxie.resource_uuid')`
(acrescentar em `config/fluxie.php`). **Produces** (`Coolify`, todas lançam `CoolifyException` com mensagem em português e o código
HTTP; nunca incluem o token):
`compose(): string` (GET service, exige `docker_compose_raw`), `envs(): array<string, ?string>` (só `is_preview=false`),
`setEnv(string $key, string $value): void` (PATCH; 404 → POST), `putCompose(string $raw): void`, `restart(bool $pull): void`,
`canDeploy(): bool` (restart de `uuid` inexistente: 404 → true, 403 → false, outro → exceção).

- [ ] **Step 1: testes que falham** com as fixtures: cada método no caminho feliz e em 401/403/404/409/500; `setEnv` cai em POST no
  404; `putCompose` manda base64; a mensagem da exceção nunca contém o token; URL do painel com e sem `/` no fim.
- [ ] **Step 2-4.**
- [ ] **Step 5:** commit `feat: cliente da API do Coolify para a atualização`.

### Task 4: estado da atualização e trava mútua

**Files:** Create migração `system_updates` (id, `status`, `from_version`, `to_version`, `requested_by` FK users nula, `backup_file`,
`previous_compose` texto criptografado, `previous_version` nula, `message`, `started_at`, `step_at`, `finished_at`, timestamps),
`app/Models/SystemUpdate.php`, `app/Enums/UpdateStatus.php` (`conferindo, backup, aplicando, reiniciando, concluida, falhou,
revertida`; `isOpen()`), testes. Modify `app/Livewire/Settings/Backups.php` (`runNow` e `blocker` recusam com atualização aberta),
`app/Console/Commands/BackupDatabaseCommand.php` (diário pula com log quando há atualização em `aplicando`/`reiniciando`),
`app/Enums/AuditAction.php` + `app/Support/AuditLogText.php` (`UpdateRequested`, `UpdateSucceeded`, `UpdateReverted`, `UpdateFailed`).

**Interfaces — Produces:** `SystemUpdate::open(): ?self` (a aberta mais recente); `SystemUpdate::moveTo(UpdateStatus, ?string $message = null): void`
(grava `step_at`; fechadas gravam `finished_at`); cast `previous_compose` → `encrypted`.

- [ ] **Step 1: testes que falham:** só uma aberta por vez (a segunda `start` é recusada dentro de transação com
  `pg_advisory_xact_lock`); backup manual e restauração recusados com atualização aberta (mensagens na tela); diário pulado em
  `aplicando`; compose guardado não aparece em texto puro no banco; frases da auditoria.
- [ ] **Step 2-4.**
- [ ] **Step 5:** commit `feat: estado da atualização e trava com backup e restauração`.

### Task 5: pré-voo e aplicação

**Files:** Create `app/Services/Update/Preflight.php`, `app/Services/Update/ApplyUpdate.php`, `app/Jobs/ApplyUpdateJob.php`
(fila `history`, `tries = 1`, `timeout = 900`), testes.

**Interfaces — Consumes:** Tasks 1-4, `RunBackup` (tipo `BackupKind::Manual`), `RestoreProgress::isRunning`, `ManualBackupState::isRunning`.
**Produces:** `Preflight::check(Version $target, string $composeRaw): list<string>` (erros em português; vazio = ok) — ordem da spec:
leitura do compose, leitura/escrita de variáveis (`FLUXIE_VERSION` regravada; se falta, criada com a versão rodando), `canDeploy`,
manifesto do GHCR (`GET https://ghcr.io/v2/fluxie-tech/fluxie-inbox/manifests/<tag>` com token anônimo do GHCR → 200), compose da
Release válido (YAML, serviço `app`, imagem com `${FLUXIE_VERSION:-<tag>}`), nada em andamento. `Preflight::composeEdited(): bool`
(compose atual ≠ asset da versão atual). `ApplyUpdate::__invoke(SystemUpdate $update): void` — `conferindo` → pré-voo → `backup` →
`RunBackup` → `aplicando` (guarda compose e `FLUXIE_VERSION` atuais; grava compose e versão novos) → `reiniciando` → `restart(pull: true)`;
falha no restart → devolve (regrava compose e versão anteriores) → `revertida`. Cada passo checa o estado antes (job repetido não
refaz nada).

- [ ] **Step 1: testes que falham** (`Http::fake`, `RunBackup` falso): cada item do pré-voo falhando para em `falhou` sem nenhuma
  chamada de escrita depois dele; token sem `deploy` → nenhum backup; backup falho → `falhou`, nenhuma escrita no Coolify; restart
  falho → compose e versão regravados com os valores guardados, `revertida`; production sem `FLUXIE_VERSION` → criada com a
  versão rodando no pré-voo e regravada na volta; job rodado de novo com o estado em `reiniciando` não chama nada.
- [ ] **Step 2-4.**
- [ ] **Step 5:** commit `feat: pré-voo e aplicação da atualização`.

### Task 6: vigia e fechamento

**Files:** Create `app/Console/Commands/UpdateWatchCommand.php` (`app:update-watch`), `app/Notifications/UpdateFinished.php`
(`kind` e `state`: concluida/revertida/falhou), testes. Modify `routes/console.php` (a cada minuto, `withoutOverlapping`).

Decisão do plano: o fechamento roda no scheduler (um processo, sobe junto com a versão nova), não no boot do `app` — a spec deixa o
lugar ao construtor desde que seja um só dono e fora de request. Ledger: `Ruling`.

**Interfaces — Consumes:** Tasks 1, 3, 4, 5.
**Comportamento:** `reiniciando` e versão rodando = destino → `concluida` + auditoria + alerta; `reiniciando` há mais de 10 min com
versão = origem → devolve → `revertida` + alerta; `conferindo`/`backup` parados há mais de 20 min → `falhou`; `aplicando` parado há
mais de 5 min → devolve → `revertida`; devolução que falha → `falhou` + alerta com o link da recuperação.

- [ ] **Step 1: testes que falham** para cada linha acima (com `travel` não: usar `step_at` gravado no passado, ver CLAUDE.md sobre
  Redis) e que um alerta sai uma vez só.
- [ ] **Step 2-4.**
- [ ] **Step 5:** commit `feat: vigia da atualização (fecha, devolve ou avisa)`.

### Task 7: tela Configurações > Sistema

**Files:** Create `app/Livewire/Settings/System.php`, `resources/views/livewire/settings/system.blade.php`, testes. Modify
`app/Support/Navigation.php` (`Sistema`, rota `configuracoes.sistema`, admin, grupo `Empresa`, ícone `arrow-path`), `routes/web.php`.

Conteúdo e textos da spec ("Tela Configurações > Sistema"): versão (ou "desenvolvimento" + sha curto), última consulta,
liga/desliga, Consultar agora, versão disponível + "Ver novidades", Conexão com o Coolify (URL do painel, token salvo/Trocar/Remover,
recurso de `COOLIFY_RESOURCE_UUID`), Testar conexão (itens 1-3 do pré-voo), Atualizar para X.Y.Z com confirmação por palavra
(`ATUALIZAR`) e aviso de compose editado; acompanhamento dos passos com polling enquanto aberta; ao perder conexão, "Atualizando. A
página volta sozinha quando o sistema subir" (404 e 502 = ainda subindo).

- [ ] **Step 1: testes que falham:** só admin; token nunca no HTML (nem depois de salvar); sem `COOLIFY_RESOURCE_UUID` não há botão;
  versão de desenvolvimento não tem botão; Testar conexão mostra cada falha em português; Atualizar sem a palavra não dispara;
  com a palavra cria `SystemUpdate` e despacha `ApplyUpdateJob`; atualização aberta mostra os passos.
- [ ] **Step 2-4.**
- [ ] **Step 5:** conferência visual (agente `conferente-visual`, servidor local) em 1280, 1920, 2560 e 390.
- [ ] **Step 6:** commit `feat: tela Sistema com aviso de versão e botão Atualizar`.

### Task 8: versão no backup

**Files:** Modify `app/Services/Backup/BackupCatalog.php` (`name()` acrescenta `-v<versão>` quando há versão de Release; o parser
aceita nomes com e sem o sufixo), `app/Services/Backup/BackupFile.php` (`?string $version`), a tela de Backups (mostra "feito na X"),
`app/Services/Backup/Restore/SideDatabase.php` (mensagem de migração desconhecida cita a versão do backup quando houver), testes.

- [ ] **Step 1: testes que falham:** nome com versão; nome antigo continua listado e rotacionado; mensagem nova; rotação conta os dois
  formatos juntos.
- [ ] **Step 2-4.** **Step 5:** commit `feat: o backup guarda a versão em que foi feito`.

### Task 9: workflow da Release

**Files:** Modify `.github/scripts/coolify.sh` (`coolify_get_compose`, `coolify_put_compose`, `coolify_set_env` usa PATCH→POST),
`tests/scripts/coolify-test.sh`, `.github/workflows/release-image.yml` (job `production`: pula pré-release; guarda compose e
`FLUXIE_VERSION`, grava os da Release, `restart?latest=true`; se o restart falhar, devolve e falha o job).

Decisão do dono: este job faz só a primeira subida da production (0.1.0 → primeira versão com o botão); um PR depois dessa Release
o remove. Comentário no job dizendo isso.

- [ ] **Step 1:** testes do script (o `curl` falso já existente) para get/put compose e para a devolução → falham.
- [ ] **Step 2-4.** **Step 5:** commit `ci: production recebe compose e versão da Release, com devolução`.

### Task 10: fumaça de atualização

**Files:** Create `docker/smoke/upgrade-smoke.sh`; Modify `.github/workflows/release-image.yml` (job `edge`, depois da fumaça do
compose) e `compose-smoke.yml` se fizer sentido.

Roteiro: baixa o `docker-compose.yml` da Release anterior (`gh release download`), sobe com a imagem dela, cria admin
(`app:create-user` com senha por stdin), setor, etiqueta e resposta rápida (`php artisan tinker --execute`, só no script), troca para o compose candidato com
`FLUXIE_VERSION` = imagem candidata, sobe, confere `/up` 200, `/login` 200 (não 302 para `/instalar`), contagens preservadas e
`migrations` sem pendência. Sai com código ≠ 0 em qualquer falha.

- [ ] **Step 1:** rodar contra a imagem candidata local com uma migração quebrada de propósito → falha (RED); tirar → passa.
- [ ] **Step 2:** commit `ci: fumaça de atualização a partir da Release anterior`.

### Task 11: documentação

**Files:** Modify `README.md` (passo curto "Atualizar pelo app"), `docs/deploy.md` (token: Keys & Tokens → API Tokens, permissões e
por quê, aparece uma vez, dono admin do time, acesso à API ligado, vale para o time inteiro; colar no app e Testar conexão; trocar e
remover; com `FLUXIE_VERSION` gravada, colar compose não troca a imagem; recuperação guiada com o backup do botão; regra do compose e
de dados em migração).

- [ ] **Step 1:** escrever. **Step 2:** commit `docs: atualização pelo app no README e no deploy`.

### Task 12: ponta a ponta no Coolify (com o dono)

Precisa do "sim" do dono para criar e apagar o Service descartável e as etiquetas de teste no GHCR, e dos tokens
`COOLIFY_TEST_TOKEN_FULL/_NODEPLOY/_READ` do `.env.coolify.local`.

- [ ] Publicar a imagem do branch como `0.0.0-teste.1` e `0.0.0-teste.2` (pré-releases) com o asset do compose.
- [ ] Service descartável com o compose de `0.0.0-teste.1`, `FLUXIE_UPDATE_PRERELEASE=true`; dono cria o admin pelo `/instalar`.
- [ ] Token `read` e `nodeploy` na tela: Testar conexão e Atualizar param no pré-voo, nada muda (conferir compose e envs pela API).
- [ ] Token `full`: Atualizar leva a `0.0.0-teste.2`; tela acompanha; alerta de concluída; dados intactos.
- [ ] Apagar Service, pré-releases e etiquetas de teste; dono apaga os tokens.

---

## Self-review (feito)

Spec coberta: versão (T1), consulta (T2), tela (T7), conexão e pré-voo (T3, T5), estado e trava (T4), fechamento e vigia (T6),
workflow (T9), restauração entre versões (T8; a recusa já existe), testes 1-4 da spec (T1-T8, T10, T12, visual em T7), documentação
(T11). Nomes conferidos entre tarefas (`Version`, `InstalledVersion`, `UpdateSettings`, `Coolify`, `SystemUpdate`, `UpdateStatus`,
`Preflight`, `ApplyUpdate`, `ApplyUpdateJob`).
