# Backup diário do banco: plano de implementação

> **Para quem executar:** execução inline com `superpowers:executing-plans` (decisão do dono). Os passos usam `- [ ]`.

**Objetivo:** `pg_dump` diário do banco para o RustFS (7 diários + 2 semanais + 3 manuais), com tela de admin para listar, baixar e fazer um na hora, alerta de falha/atraso e roteiro de restauração testado.

**Arquitetura:** o comando `app:backup-database` (scheduler 02:15; fila para o manual) chama `RunBackup`, que usa `DatabaseDumper` (Process + `pg_dump -Fc --compress=zstd:3`) e `BackupCatalog` (nomes, listagem e rotação sobre o disco `backups`). A tela Livewire e o controller de download leem o mesmo disco. Estado do último resultado em `settings`.

**Stack:** Laravel 13, Livewire 4, Flux 2, Flysystem S3 (RustFS), PostgreSQL 18, `postgresql-client-18` (PGDG) nas imagens worker e scheduler.

**Spec:** `docs/superpowers/specs/2026-11-12-backup-banco-design.md`

## Restrições globais

- Só o beta. Nunca tocar a `main`/produção do Coolify; sem PR sem pedido do dono.
- Dump: `pg_dump -Fc --compress=zstd:3`, cliente 18; senha só em `PGPASSWORD` no ambiente do processo (nunca na linha de comando, nunca em log, erro gravado ou UI).
- Retenção: 7 diários, 2 semanais, 3 manuais; rotação só depois de um backup novo gravado e conferido (`pg_restore --list` e tamanho > 0).
- Nomes: `dimetal-AAAA-MM-DD-HHMM-diario|semanal|manual.dump`, no fuso `app.display_timezone`. Diário às 02:15; semanal = cópia do diário de domingo.
- Disco `backups` (bucket `BACKUP_BUCKET`, mesmas credenciais `AWS_*`), separado do disco de mídia. O bucket é criado sozinho pelo `app:prepare-media` (pós-deploy do app, já configurado no Coolify), que passa a preparar também o disco `backups`; ninguém cria bucket à mão. Sem `BACKUP_BUCKET` (dev, testes) o disco `backups` é local em `storage/app/backups`.
- Só admin (`can:admin`); download repassado em stream pelo app, com `Log::notice` (usuário e arquivo). Sem URL pública.
- Alertas só para admins ativos, no sino, sem som próprio. Atraso: > 26 h sem backup bom, no máximo um aviso a cada 24 h.
- Textos de tela em português, no padrão de `Retention`; tokens de cor existentes (`warn`, `danger`, `ok`), sem cor nova.
- Fluxo do `CLAUDE.md`: TDD (RED visto), um commit por tarefa, `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Postgres de teste (`pg-test`) de pé antes de qualquer teste.

## Review Focus

1. **Dump falha** (saída ≠ 0, arquivo vazio, `pg_restore --list` falha): nada sobe, nada é podado, `last_error` gravado, admins avisados. (Task 4)
2. **Nome forjado no download** (`../`, nome fora da lista, nome de outro prefixo): 404, nunca lê fora do disco `backups`. (Task 6)
3. **Duas execuções ao mesmo tempo** (manual durante o diário): a segunda sai sem erro e sem criar arquivo. (Task 4)
4. **Senha no erro**: o texto gravado em `last_error` e mostrado na tela nunca contém a senha nem a linha de comando. (Task 4)
5. **Poucos backups / manual**: com menos de 7 diários nada é apagado; manual não empurra diário para fora; arquivo estranho no bucket é ignorado. (Task 2)

---

### Task 1: Cliente PostgreSQL 18 nas imagens worker e scheduler

**Files:**
- Modify: `docker/Dockerfile` (novo estágio entre `reverb` e `scheduler`; `worker` e `scheduler` passam a derivar dele)

**Interfaces:**
- Produces: `pg_dump`, `pg_restore` versão 18 no PATH dos containers worker e scheduler.

- [ ] **Step 1: Estágio `dbtools`.** Criar `FROM runtime AS dbtools` (USER root; adicionar o repositório PGDG do Debian bookworm com a chave oficial em `/usr/share/keyrings`; `apt-get install -y --no-install-recommends postgresql-client-18`; limpar `/var/lib/apt/lists`; `USER www-data`). Trocar `FROM runtime AS worker` e `FROM runtime AS scheduler` por `FROM dbtools AS ...`. Comentário no estágio: sobe junto com o servidor (Postgres 19 exige `postgresql-client-19`); `reverb` e `app` não ganham o cliente.
- [ ] **Step 2: Construir e conferir.** Run: `docker build --target scheduler -t dimetal-scheduler-test -f docker/Dockerfile .` e `MSYS_NO_PATHCONV=1 docker run --rm --entrypoint pg_dump dimetal-scheduler-test --version`. Expected: `pg_dump (PostgreSQL) 18.x`; o mesmo com `--entrypoint pg_restore`. Em seguida `docker build --target worker ...` e o mesmo `pg_dump --version`. Remover as imagens de teste.
- [ ] **Step 3: Commit** `feat: cliente PostgreSQL 18 nas imagens worker e scheduler`.

### Task 2: Config, disco `backups` e catálogo (nomes, listagem, rotação)

**Files:**
- Create: `config/backup.php`, `app/Services/Backup/BackupKind.php`, `app/Services/Backup/BackupFile.php`, `app/Services/Backup/BackupCatalog.php`
- Modify: `config/filesystems.php` (disco `backups`), `.env.example` (`BACKUP_BUCKET=`), `app/Console/Commands/PrepareMediaCommand.php` (prepara também o bucket do disco `backups`; mesmo comando, para o pós-deploy do Coolify não mudar), `docs/deploy.md:163` (citar o bucket de backup)
- Test: `tests/Feature/Backup/BackupCatalogTest.php`, `tests/Feature/Console/PrepareMediaCommandTest.php`

**Interfaces:**
- Produces:
  - `config('backup')`: `disk` (`'backups'`), `keep` (`['diario'=>7,'semanal'=>2,'manual'=>3]`), `compress` (`'zstd:3'`), `hour` (`'02:15'`), `stale_hours` (26).
  - `enum BackupKind: string { Diario='diario'; Semanal='semanal'; Manual='manual' }`.
  - `final readonly class BackupFile(string $name, BackupKind $kind, CarbonImmutable $createdAt, int $size)`.
  - `BackupCatalog`: `disk(): Filesystem`, `name(BackupKind $kind, CarbonImmutable $at): string`, `all(): list<BackupFile>` (mais novo primeiro; ignora o que não casa com o padrão do nome), `find(string $name): ?BackupFile`, `totalBytes(): int`, `prune(): int` (apaga além do `keep` por tipo; devolve quantos apagou).

- [ ] **Step 1: Testes (RED)** com `Storage::fake('backups')`: `test_nome_usa_o_fuso_de_exibicao` (`2026-11-13 05:15 UTC` vira `dimetal-2026-11-13-0215-diario.dump` com display `America/Sao_Paulo`); `test_lista_do_mais_novo_ao_mais_velho_e_ignora_arquivo_estranho` (arquivo `lixo.txt` e `dimetal-x.dump` fora); `test_find_so_acha_nome_da_lista` (`../x` e nome inexistente → `null`); `test_prune_mantem_7_diarios_2_semanais_3_manuais` (cria 9/4/5, sobram 7/2/3 e os mais novos); `test_prune_com_poucos_nao_apaga_nada` (3 diários, 0 apagados); `test_manual_nao_conta_nos_diarios`. No `PrepareMediaCommandTest` (ver como ele já fakeia o cliente S3): `test_cria_tambem_o_bucket_de_backup_quando_falta`, `test_bucket_de_backup_que_ja_existe_nao_e_recriado`, `test_sem_bucket_de_backup_configurado_o_disco_e_local_e_nada_a_preparar` (o comando continua com sucesso e a mensagem dos testes atuais de mídia segue valendo).
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Backup/BackupCatalogTest.php tests/Feature/Console/PrepareMediaCommandTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar** os arquivos da lista. O tipo e a data saem do nome (regex `^dimetal-(\d{4}-\d{2}-\d{2})-(\d{4})-(diario|semanal|manual)\.dump$`, data no fuso de exibição); o tamanho vem de `size()`. Disco `backups` em `config/filesystems.php`: com `BACKUP_BUCKET` definido, driver `s3` com as chaves `AWS_*` e esse bucket (`throw => false`, `report => false`, como o `s3`); sem ele, driver `local` com raiz `storage_path('app/backups')`. `PrepareMediaCommand` percorre os dois discos (mídia e `backups`) com a mesma lógica de hoje, extraída para um método privado `prepare(Filesystem $disk, string $label): bool`; disco não S3 não faz nada.
- [ ] **Step 4: Rodar e ver passar.** Run: mesmo comando. Expected: PASS.
- [ ] **Step 5: Commit** `feat: catálogo de backups (nomes, listagem e rotação 7+2+3)`.

### Task 3: Estado do último resultado e alertas (admins)

**Files:**
- Create: `app/Settings/BackupStatus.php`, `app/Notifications/BackupFailed.php`, `app/Notifications/BackupStale.php`
- Modify: `app/Services/Alerts/AlertRecipients.php` (método `admins()`), `app/Livewire/Alerts/Bell.php` (`match` ganha `'backup'`), `resources/js/alerts-rules.js` só se o `kind` desconhecido tocar som
- Test: `tests/Feature/Backup/BackupAlertsTest.php`, ajuste em `tests/Feature/Alertas/AlertsPayloadTest.php` se ele enumerar notificações

**Interfaces:**
- Produces:
  - `BackupStatus` (estático, sobre `Setting`): `lastSuccessAt(): ?CarbonImmutable`, `lastError(): ?string`, `recordSuccess(): void` (grava `backup.last_success_at`, zera `backup.last_error`), `recordFailure(string $message): void`, `staleAlertedAt(): ?CarbonImmutable`, `markStaleAlerted(): void`.
  - `AlertRecipients::admins(): Collection<int, User>` (admins ativos).
  - `BackupFailed` e `BackupStale` (`AlertNotification`): `toArray` com `kind => 'backup'`, `state => 'failed'|'stale'`, `level => 'critico'`; **sem texto livre** (só `kind`, `state`, `level`).
  - Bell: `kind 'backup'` → ícone `circle-stack`, tom `danger`, título `Backup do banco falhou` / `Backup do banco atrasado`, detalhe `Veja Configuração > Backup`.

- [ ] **Step 1: Testes (RED):** `test_admins_so_admin_ativo` (supervisor, atendente e admin inativo fora); `test_status_grava_e_zera_o_erro`; `test_payload_so_tem_kind_state_e_level`; `test_bell_mostra_backup_falhou_e_atrasado`; `test_alerta_de_backup_nao_toca_som` (teste JS em `tests/js` se o módulo de som mapear `kind`).
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Backup/BackupAlertsTest.php` e, se tocou o JS, `node --test tests/js`.
- [ ] **Step 3: Implementar** conforme as interfaces; `AlertRecipients::admins()` reutiliza o `active()` privado.
- [ ] **Step 4: Rodar e ver passar**, mais `php artisan test tests/Feature/Alertas`.
- [ ] **Step 5: Commit** `feat: estado do backup e alertas para admins`.

### Task 4: Dumper, `RunBackup` e comando `app:backup-database`

**Files:**
- Create: `app/Services/Backup/DatabaseDumper.php`, `app/Services/Backup/DumpFailed.php` (exceção, `RuntimeException`), `app/Services/Backup/RunBackup.php`, `app/Console/Commands/BackupDatabaseCommand.php`
- Test: `tests/Feature/Backup/DatabaseDumperTest.php`, `tests/Feature/Backup/RunBackupTest.php`, `tests/Feature/Console/BackupDatabaseCommandTest.php`

**Interfaces:**
- Consumes: `BackupCatalog`, `BackupKind`, `BackupStatus`, `AlertRecipients::admins()`, `BackupFailed` (notificação).
- Produces:
  - `DatabaseDumper::dump(): string` (caminho do temporário em `storage/app/tmp`; lê a conexão por `DB::connection()->getConfig()` para valer também com `DB_URL`; `Process` com `pg_dump -Fc --compress=zstd:3 -h -p -U -d -f <tmp>`, env `PGPASSWORD` e `PGSSLMODE`; depois `pg_restore --list <tmp>`; lança `DumpFailed` com mensagem **sem senha e sem a linha de comando**).
  - `RunBackup::__invoke(BackupKind $kind): BackupFile`: lock `Cache::lock('backup-database', 3600)` (sem lock, devolve sem fazer nada); dump; envio com `writeStream` para `catalog->name($kind, now())`; se `$kind === Diario` e hoje é domingo (fuso de exibição), `copy` para o nome `semanal`; `prune()`; `BackupStatus::recordSuccess()`; apaga o temporário sempre (`finally`). Em `DumpFailed` ou erro de disco: `recordFailure($mensagem)`, `Notification::send(admins, new BackupFailed)`, relança.
  - Comando `app:backup-database {--manual}`: `RunBackup(Manual|Diario)`; imprime o nome e o tamanho; `FAILURE` com a mensagem em caso de erro; lock ocupado → `SUCCESS` com aviso "já em andamento".

- [ ] **Step 1: Testes do dumper (RED)** com `Process::fake` (o fake cria o arquivo no caminho de `-f`): `test_monta_o_comando_sem_a_senha` (contém `-Fc`, `--compress=zstd:3`, `-h`, `-U`, `-d`; senha só em `PGPASSWORD`); `test_falha_se_pg_dump_sai_com_erro`; `test_falha_se_o_arquivo_fica_vazio`; `test_falha_se_pg_restore_list_falha`; `test_mensagem_de_erro_nao_tem_a_senha`.
- [ ] **Step 2: Testes de `RunBackup` e comando (RED)** com `Storage::fake('backups')` e `Notification::fake()`: `test_diario_grava_com_o_nome_certo_e_registra_sucesso`; `test_domingo_cria_tambem_o_semanal` (`travel` para um domingo, simulando fuso de São Paulo); `test_dia_comum_nao_cria_semanal`; `test_poda_depois_do_sucesso`; `test_falha_nao_poda_e_nao_apaga_backup_bom_e_avisa_admins` (Review Focus 1); `test_segunda_execucao_com_lock_nao_cria_arquivo` (Review Focus 3); `test_manual_grava_com_sufixo_manual`; `test_temporario_e_apagado_mesmo_com_falha`; `test_comando_falha_com_codigo_1_e_mensagem_sem_senha` (Review Focus 4).
- [ ] **Step 3: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Backup tests/Feature/Console/BackupDatabaseCommandTest.php`. Expected: FAIL.
- [ ] **Step 4: Implementar** conforme as interfaces (envio por stream, nunca `file_get_contents` do dump inteiro).
- [ ] **Step 5: Rodar e ver passar**, e `vendor/bin/phpstan analyse --no-progress` + `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml` nos arquivos novos.
- [ ] **Step 6: Commit** `feat: comando app:backup-database (pg_dump zstd, semanal, rotação e alerta de falha)`.

### Task 5: Agenda e checagem de atraso

**Files:**
- Create: `app/Console/Commands/CheckBackupHealthCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/CheckBackupHealthCommandTest.php`, ajuste em `tests/Feature/Console/ScheduleTest.php`

**Interfaces:**
- Consumes: `BackupStatus`, `AlertRecipients::admins()`, `BackupStale`, `config('backup.stale_hours')`.
- Produces: comando `app:check-backup-health`: se `lastSuccessAt()` é nulo ou mais antigo que 26 h **e** `staleAlertedAt()` é nulo ou de mais de 24 h, avisa os admins (`BackupStale`) e `markStaleAlerted()`; senão não faz nada.

- [ ] **Step 1: Testes (RED):** `test_avisa_quando_passa_de_26h`; `test_nao_avisa_com_backup_recente`; `test_nunca_rodou_conta_como_atrasado`; `test_nao_repete_o_aviso_em_24h`; no `ScheduleTest`: `test_backup_diario_as_0215_sem_sobreposicao` (`app:backup-database` às 02:15 com `withoutOverlapping(60)`) e `test_checagem_de_backup_de_hora_em_hora`.
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Console`.
- [ ] **Step 3: Implementar** o comando e as duas linhas em `routes/console.php` (`dailyAt(config('backup.hour'))` e `hourly()`, `withoutOverlapping(10)` na checagem).
- [ ] **Step 4: Rodar e ver passar.** Mesmo comando.
- [ ] **Step 5: Commit** `feat: agenda do backup às 02:15 e checagem horária de atraso`.

### Task 6: Tela de Backup (lista, fazer agora) e download

**Files:**
- Create: `app/Livewire/Settings/Backups.php`, `resources/views/livewire/settings/backups.blade.php`, `app/Http/Controllers/BackupDownloadController.php`
- Modify: `routes/web.php` (grupo `can:admin`: `configuracoes/backups` → `configuracoes.backups`; `configuracoes/backups/{name}` → `configuracoes.backups.baixar`, `where('name', '[A-Za-z0-9._-]+')`), `app/Support/Navigation.php` (item `Backup do banco`, rota `configuracoes.backups`, gate `admin`, seção Configuração, grupo `Empresa`, ícone `circle-stack`)
- Test: `tests/Feature/Livewire/BackupsPageTest.php`, `tests/Feature/Backup/BackupDownloadTest.php`, ajuste em `tests/Feature/Layout/NavigationTest.php`

**Interfaces:**
- Consumes: `BackupCatalog`, `BackupStatus`, `config('backup')`, comando `app:backup-database --manual`.
- Produces: `Backups` (Livewire, `#[Title('Backup do banco')]`): `mount()` e `runNow()` com `authorize('admin')`; `runNow()` faz `Artisan::queue('app:backup-database', ['--manual' => true])` e mostra `result` "Backup iniciado. Recarregue em instantes."; `render()` entrega lista (`BackupCatalog::all()`), total, último sucesso, último erro, tipo e retenção. View no molde de `retention.blade.php` (`x-settings-page`), tabela de nome/tipo/data/tamanho com botão **Baixar** (link para a rota de download) e **Fazer backup agora** (`wire:click="runNow"`); último erro em `warn`/`danger`; estado vazio com `x-empty-state` (ver como o projeto o usa).
  `BackupDownloadController::__invoke(string $name)`: `abort_unless($catalog->find($name))` (404), `Log::notice` com `auth()->id()` e o nome, `response()->streamDownload` do `readStream` com `Content-Type: application/octet-stream`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`.

- [ ] **Step 1: Testes (RED):** página: `test_so_admin_entra` (supervisor e atendente 403, visitante redireciona); `test_lista_mostra_nome_tipo_e_tamanho_e_total`; `test_estado_vazio_sem_backups`; `test_mostra_ultimo_erro_sem_senha`; `test_fazer_agora_enfileira_o_comando_manual` (sem rodar no request); `test_quem_nao_e_admin_nao_executa`. Download: `test_admin_baixa_o_arquivo_com_o_conteudo_e_o_nome`; `test_nome_forjado_da_404` (`../.env`, `dimetal-2026-01-01-0000-diario.dump` inexistente, nome fora do padrão) (Review Focus 2); `test_supervisor_403`; `test_download_vai_para_o_log`. Navigation: o item aparece só para admin, no grupo Empresa, e as asserções de `test_settings_groups_por_papel` e da lista de rotas acompanham.
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Livewire/BackupsPageTest.php tests/Feature/Backup/BackupDownloadTest.php tests/Feature/Layout`.
- [ ] **Step 3: Implementar** conforme as interfaces; texto e layout seguem `retention.blade.php` e os tokens existentes.
- [ ] **Step 4: Rodar e ver passar**, mais `php artisan test tests/Feature/Layout tests/Unit/ThemeTokensTest.php tests/Unit/AlpineCspSubsetTest.php`.
- [ ] **Step 5: Commit** `feat: tela de backup do banco (lista, fazer agora e download)`.

### Task 7: Roteiro de restauração e prova real

**Files:**
- Create: `docs/operacao/backup-e-restauracao.md` (se `docs/operacao/` não existir, usar a pasta de operação que já houver em `docs/`), `docs/superpowers/reports/2026-11-12-backup-banco.md`

**Interfaces:**
- Consumes: Tasks 1 a 6 prontos.

- [ ] **Step 1: Prova real do dump e da restauração, local.** Com o `pg-test` de pé e o banco `dimetal_test` migrado, rodar com o cliente 18 do contêiner `postgres:18-alpine`: `pg_dump -Fc --compress=zstd:3` do banco, `createdb` descartável, `pg_restore --no-owner -d <descartável>` e comparar a contagem de linhas de 3 tabelas (ex.: `users`, `tickets`, `messages`). Anotar tamanho do arquivo e tempo de cada etapa. Remover o banco descartável e o arquivo.
- [ ] **Step 2: Escrever o roteiro** (`docs/operacao/backup-e-restauracao.md`), passo a passo para o **dono executar no beta**: o que existe (horários, retenção, nomes); que o bucket é criado sozinho no deploy; como rodar o primeiro "Fazer agora" e baixar o arquivo; como restaurar num banco descartável (cliente 18 e `pg_restore` 16+, `createdb`, `--no-owner`, conferência das contagens de `users`, `tickets`, `messages`, e como apagar o descartável) **sem tocar no banco do beta**; e o aviso de que restaurar traz de volta dado apagado depois do backup (LGPD: reaplicar exclusões). Incluir os números medidos no passo 1.
- [ ] **Step 3: Relatório** `docs/superpowers/reports/2026-11-12-backup-banco.md` com a prova local, os números e o que fica com o dono no beta (primeiro backup, download, restauração real, medir tamanho; avaliar `--exclude-table-data` de `waha_events` e o nível do zstd).
- [ ] **Step 4: Variáveis no beta (eu faço, pela API do Coolify, só no beta).** Gravar `BACKUP_BUCKET=dimetal-beta-backups` no app, no worker e no scheduler do beta e ler de volta para conferir (token lido em Python, nunca impresso; ver `coolify-beta-referencia`). Só depois de a branch estar pronta; o bucket nasce no primeiro deploy, quando o dono mergear. Sem restart agora.
- [ ] **Step 4: Commit** `docs: roteiro de restauração do banco e relatório da prova`.

---

## Auto-revisão (feita ao escrever)

- **Cobertura do spec:** cliente 18 (T1); disco, retenção 7+2+3, nomes (T2); alertas e estado (T3); dump zstd, semanal, rotação, lock, manual (T4); 02:15 e atraso 26 h (T5); tela, download, auditoria em log, só admin (T6); roteiro testado (T7). Sem restaurar pela UI, sem criptografia, sem agendar pela UI: nenhuma tarefa os toca.
- **Consistência:** `BackupCatalog`, `BackupKind`, `BackupFile`, `BackupStatus`, `DumpFailed` (exceção) e `BackupFailed`/`BackupStale` (notificações) têm um nome só; `RunBackup` é chamado só pelo comando.
- **Beta:** `BACKUP_BUCKET` eu gravo pelo Coolify (Task 7, passo 4); o bucket nasce sozinho no primeiro deploy depois do merge do dono; primeiro backup, download e restauração real ficam com o dono, pelo roteiro. Nada na produção.
