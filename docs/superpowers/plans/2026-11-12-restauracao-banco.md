# Restauração do banco pela UI: plano de implementação

> **Para quem executar:** execução inline com `superpowers:executing-plans` (decisão do dono); a revisão final é por um subagente Sonnet 5.5 medium. Os passos usam `- [ ]`.

**Goal:** restaurar o banco pela tela de Backup (arquivo da lista ou enviado), com troca por renomeação, backup de segurança, desfazer e recuperação das mensagens; e a tela passa a atualizar sozinha durante um backup manual.

**Architecture:** `DumpInspector` confere o arquivo (lista de permissão da TOC); o job `RestoreDatabaseJob` (fila `history`) chama o orquestrador `RestoreDatabase`, que usa `SideDatabase` (criar/restaurar/verificar um banco novo), `AdminDatabase` (conexão ao banco `postgres`: criar, apagar, trocar por renomeação, listar guardados), `Maintenance` (modo de manutenção por cache no Redis, `queue:restart`) e `RestoreProgress` (estado no Redis). A página `/restauracao` fica fora da manutenção e consulta `/restauracao/estado`.

**Tech Stack:** Laravel 13, Livewire 4 (upload com `WithFileUploads`), Flux 2, PostgreSQL 18 (`pg_restore` 18 em todas as imagens), Redis, RustFS.

**Spec:** `docs/superpowers/specs/2026-11-12-restauracao-banco-design.md` (e o do backup, `2026-11-12-backup-banco-design.md`).

## Restrições globais

- Só o beta; nunca tocar a `main`/produção do Coolify; PR só com pedido (o dono já pediu PR e merge desta frente).
- Nomes de banco são gerados pelo app: `<db>_restaurando_AAAAMMDDHHMM` e `<db>_antes_AAAAMMDDHHMM`, validados por `^[a-z][a-z0-9_]{0,62}$`; nada vindo da tela vira `DROP`/`RENAME` sem passar pela lista de `_antes_` existentes.
- O `pg_restore` roda como superusuário e executa SQL do arquivo: só passa dump cuja TOC tenha **apenas** `TABLE`, `TABLE DATA`, `SEQUENCE`, `SEQUENCE SET`, `SEQUENCE OWNED BY`, `DEFAULT`, `CONSTRAINT`, `FK CONSTRAINT`, `INDEX`, `COMMENT` e `EXTENSION - plpgsql`.
- Senha só em `PGPASSWORD`; nunca em linha de comando, log, estado do Redis ou mensagem de erro.
- Confirmação digitada `RESTAURAR` (config `backup.restore.confirm_word`); só admin; `Log::notice` de cada restauração/desfazer (usuário, arquivo, resultado).
- Retenção: bancos `_antes_` por 7 dias; backups `antes` (segurança) 2 últimos. Upload até 100 MB. Timeouts: `pg_restore` 600 s, job 840 s (cabem nos 900 s do worker `history`).
- Nunca terminar o job em manutenção; nunca deixar o sistema sem banco ativo.
- Fluxo do `CLAUDE.md`: TDD (RED visto), um commit por tarefa, `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. `pg-test` (Postgres 18, usuário `dimetal` superusuário) de pé antes de qualquer teste.

## Review Focus

1. **Dump estranho/malicioso** (função, gatilho, extensão que não seja `plpgsql`, visão): recusado antes de qualquer restauração, igual para arquivo da lista e enviado. (Task 3)
2. **Falha em qualquer passo**, antes ou durante a troca: o sistema sai da manutenção, continua com o banco antigo ativo e o banco novo é apagado; falha depois da troca (migração) desfaz a troca. (Task 6)
3. **Backup de versão mais nova que o código** ou **sem admin ativo**: recusado na verificação, ainda com o sistema no ar. (Task 5)
4. **Nome de banco forjado** no "Voltar para este": só nomes da lista `_antes_` existentes valem. (Tasks 4 e 8)
5. **Duas restaurações ao mesmo tempo** ou restauração com backup/restauração em andamento: a segunda é recusada. (Tasks 6 e 8)

---

### Task 1: Cliente 18 em todos os estágios, manutenção compartilhada e exceção da página de andamento

**Files:**
- Modify: `docker/Dockerfile` (instalar `postgresql-client-18` no estágio `runtime`; remover o estágio `dbtools`; `worker` e `scheduler` voltam a `FROM runtime`), `bootstrap/app.php`, `.env.example`, `docs/deploy.md`
- Test: `tests/Feature/Restore/MaintenanceExceptionTest.php`

**Interfaces:**
- Produces: rotas `restauracao` e `restauracao/estado` nunca recebem o 503 do modo de manutenção; `pg_dump`/`pg_restore` 18 também no estágio do app.

- [ ] **Step 1: Teste (RED).** `test_a_pagina_de_andamento_responde_em_manutencao_e_o_resto_nao`: com `$this->artisan('down')`, `GET /restauracao/estado` não é 503 (a rota ainda não existe, então vira 404: o teste exige `assertStatus(200)` e falha por isso) e `GET /login` é 503; no fim `artisan('up')`. `test_manutencao_de_cache_e_o_padrao_do_exemplo_de_ambiente` não é necessário.
- [ ] **Step 2: Implementar** `$middleware->preventRequestsDuringMaintenance(except: ['restauracao', 'restauracao/estado'])` em `bootstrap/app.php` (a rota do `GET /restauracao/estado` nasce na Task 7: aqui crie as duas rotas mínimas em `routes/web.php`, fora de qualquer grupo `auth`, apontando para `RestoreStatusController` e `RestorePageController` vazios que a Task 7 completa; o teste passa com o JSON `{}`/200). Dockerfile: mover o `RUN` do PGDG de `dbtools` para o fim do estágio `runtime` (como `root`, voltando a `www-data`). `.env.example`: comentar `APP_MAINTENANCE_DRIVER=cache` e `APP_MAINTENANCE_STORE=redis` (obrigatórios no beta/produção). `docs/deploy.md`: parágrafo curto.
- [ ] **Step 3: Rodar** `php artisan test tests/Feature/Restore/MaintenanceExceptionTest.php` (PASS) e `docker build --target runtime ...` + `docker run --entrypoint pg_dump <img> --version` (Expected: `pg_dump (PostgreSQL) 18.x`), e o mesmo com `--target worker` (cache).
- [ ] **Step 4: Commit** `feat: cliente PostgreSQL 18 em todas as imagens e página de andamento fora da manutenção`.

### Task 2: Tipo `antes` e config da restauração

**Files:**
- Modify: `app/Services/Backup/BackupKind.php`, `app/Services/Backup/BackupCatalog.php` (padrão do nome aceita `antes`), `config/backup.php`
- Test: `tests/Feature/Backup/BackupCatalogTest.php` (acrescentar)

**Interfaces:**
- Produces: `BackupKind::Antes = 'antes'` com `label()` `Antes de restaurar`; `config('backup.keep.antes') = 2`; `config('backup.restore')`: `confirm_word` (`RESTAURAR`), `upload_max_mb` (100), `keep_old_days` (7), `recover_hours` (72), `staging_timeout` (600).

- [ ] **Step 1: Testes (RED):** `test_nome_do_tipo_antes` (`dimetal-2026-11-13-0215-antes.dump`), `test_prune_guarda_os_2_ultimos_antes_sem_mexer_nos_outros_tipos`, `test_lista_reconhece_o_tipo_antes`.
- [ ] **Step 2: Rodar e ver falhar.** `php artisan test tests/Feature/Backup/BackupCatalogTest.php`.
- [ ] **Step 3: Implementar** o caso `Antes` no enum, `(antes)` no `PATTERN`, as chaves de config.
- [ ] **Step 4: Rodar e ver passar** (arquivo todo + `tests/Feature/Livewire/BackupsPageTest.php`).
- [ ] **Step 5: Commit** `feat: tipo de backup "antes de restaurar" e config da restauração`.

### Task 3: `DumpInspector` (conferência e lista de permissão)

**Files:**
- Create: `app/Services/Backup/Restore/DumpInspector.php`, `DumpInfo.php`, `InvalidDump.php`, `tests/Fixtures/restore/toc-esquema-atual.txt` (saída real de `pg_restore --list` de um dump do esquema atual, gerada do `pg-test`)
- Test: `tests/Feature/Backup/Restore/DumpInspectorTest.php`

**Interfaces:**
- Produces: `final readonly class DumpInfo(string $name, CarbonImmutable $createdAt, string $database, string $serverVersion, string $dumpVersion, string $compression, int $entries, int $size)`; `final class InvalidDump extends RuntimeException` (mensagem segura); `DumpInspector::inspect(string $path, string $name): DumpInfo` (roda `pg_restore --list`, timeout 120 s, lê o cabeçalho `; Archive created at`, `dbname`, `Dumped from database version`, `Dumped by pg_dump version`, `Compression`, `TOC Entries`; aplica a lista de permissão a cada linha de entrada); `DumpInspector::assertSafe(string $toc): void` (puro, testável com texto).

- [ ] **Step 1: Gerar o fixture** com `docker exec` no `pg-test` (`pg_dump -Fc` do `dimetal_test`, `pg_restore --list`) e gravar em `tests/Fixtures/restore/toc-esquema-atual.txt`.
- [ ] **Step 2: Testes (RED)** com `Process::fake` devolvendo o fixture: `test_le_o_cabecalho_do_dump` (data, banco, versões, compressão, entradas); `test_o_dump_do_esquema_atual_passa_na_lista_de_permissao` (guarda: se o esquema ganhar visão/gatilho, este teste avisa); `test_recusa_funcao`, `test_recusa_gatilho`, `test_recusa_extensao_que_nao_e_plpgsql`, `test_recusa_visao`, `test_recusa_linha_desconhecida` (acrescentam uma linha ao fixture); `test_pg_restore_que_falha_vira_invalid_dump_sem_a_linha_de_comando`; `test_arquivo_vazio_e_recusado`.
- [ ] **Step 3: Rodar e ver falhar.** `php artisan test tests/Feature/Backup/Restore/DumpInspectorTest.php`.
- [ ] **Step 4: Implementar.** Linha de entrada: `^\d+; \d+ \d+ (?<rest>.+)$`; a lista de tipos permitidos vira `str_starts_with($rest, "<TIPO> ")`; `EXTENSION` só com `- plpgsql `; `COMMENT` só `COMMENT - ` (de objeto já permitido). Qualquer linha de entrada fora disso → `InvalidDump('Este arquivo não é um backup deste sistema (contém objetos que a restauração não aceita).')`.
- [ ] **Step 5: Rodar e ver passar; commit** `feat: conferência do arquivo de backup com lista de permissão da TOC`.

### Task 4: `AdminDatabase` (criar, apagar, trocar e listar bancos, com Postgres de verdade)

**Files:**
- Create: `app/Services/Backup/Restore/AdminDatabase.php`, `StoredDatabase.php`, `SwapFailed.php`
- Modify: `config/database.php` (conexão `pgsql_admin`: igual à `pgsql` mas `database => 'postgres'`, lendo as mesmas variáveis `DB_*`)
- Test: `tests/Feature/Backup/Restore/AdminDatabaseTest.php` (usa bancos descartáveis `dimetal_t_<aleatório>`, sem tocar no banco de teste ativo)

**Interfaces:**
- Produces: `final readonly class StoredDatabase(string $name, CarbonImmutable $createdAt, int $bytes)`; `final class SwapFailed extends RuntimeException`; `AdminDatabase`: `active(): string`, `canCreate(): bool`, `exists(string $name): bool`, `create(string $name): void`, `drop(string $name): void`, `swap(string $active, string $incoming, string $archive): void` (derruba conexões, renomeia, e se falhar renomeia de volta e lança `SwapFailed`), `stored(): array` (list de `StoredDatabase` dos `<active>_antes_AAAAMMDDHHMM`, mais novo primeiro), `static assertName(string $name): void`, `static archiveName(string $active, CarbonImmutable $at): string`, `static stagingName(string $active, CarbonImmutable $at): string`.

- [ ] **Step 1: Testes (RED):** `test_nomes_validos_e_invalidos` (`a; drop`, maiúsculas, vazio, mais de 63); `test_nome_de_arquivo_e_de_staging_usam_o_fuso_de_exibicao`; `test_cria_e_apaga_um_banco`; `test_drop_recusa_o_banco_ativo_e_nomes_fora_do_padrao`; `test_swap_troca_os_bancos_pelo_oid_e_guarda_o_antigo` (cria A e B, abre uma conexão PDO em A, `swap(A, B, A_antes_…)`; depois `oid(A)` é o antigo `oid(B)` e `oid(A_antes_…)` é o antigo `oid(A)`; `ALLOW_CONNECTIONS` volta a `true`); `test_swap_que_falha_no_meio_volta_ao_estado_anterior` (nome de arquivo já existente força erro: A continua A); `test_stored_lista_so_os_antes_do_banco_ativo_com_data_e_tamanho`; `test_can_create_em_postgres_de_teste`.
- [ ] **Step 2: Rodar e ver falhar.** `php artisan test tests/Feature/Backup/Restore/AdminDatabaseTest.php` (Postgres de pé).
- [ ] **Step 3: Implementar.** A conexão `pgsql_admin` é fixada com `config()` a partir da conexão ativa (host, porta, usuário, senha, sslmode, `DB_URL` já resolvido) e `DB::purge('pgsql_admin')` antes de usar; identificadores sempre validados por `assertName` e citados com aspas duplas; `swap`: `ALTER DATABASE ... WITH ALLOW_CONNECTIONS false`, `pg_terminate_backend`, `RENAME` ativo→arquivo, `RENAME` novo→ativo, `ALLOW_CONNECTIONS true`; qualquer erro desfaz o que já fez.
- [ ] **Step 4: Rodar e ver passar; commit** `feat: troca de bancos por renomeação (AdminDatabase)`.

### Task 5: `SideDatabase` (restaurar e verificar o banco novo)

**Files:**
- Create: `app/Services/Backup/Restore/SideDatabase.php`, `RestoreRejected.php`
- Test: `tests/Feature/Backup/Restore/SideDatabaseTest.php`

**Interfaces:**
- Consumes: `AdminDatabase::assertName`, `config('backup.restore.staging_timeout')`.
- Produces: `final class RestoreRejected extends RuntimeException` (mensagem segura); `SideDatabase::restore(string $database, string $dumpPath): void` (`pg_restore --no-owner --exit-on-error -h -p -U -d <database>`, `PGPASSWORD` no ambiente, timeout 600 s; falha vira `RestoreRejected` sem senha nem linha de comando); `SideDatabase::verify(string $database): void` (conexão dinâmica `pgsql_side`; exige as tabelas `users`, `tickets`, `messages`, `settings`, `migrations`; exige ao menos um `users` com `role = 'admin'` e `active = true`; exige que toda linha de `migrations` exista em `database_path('migrations')`, senão "o backup é de uma versão mais nova do sistema").

- [ ] **Step 1: Testes (RED):** com `Process::fake` para o `pg_restore` e bancos reais descartáveis para o `verify`: `test_restore_monta_o_comando_sem_a_senha`; `test_restore_com_erro_vira_rejected_sem_a_senha`; `test_restore_com_timeout_vira_rejected`; `test_verify_passa_com_esquema_e_admin_ativo`; `test_verify_recusa_sem_admin_ativo`; `test_verify_recusa_migracao_desconhecida`; `test_verify_recusa_tabela_faltando`.
- [ ] **Step 2: Rodar e ver falhar.** `php artisan test tests/Feature/Backup/Restore/SideDatabaseTest.php`.
- [ ] **Step 3: Implementar**; o banco descartável do teste nasce de `CREATE DATABASE ... TEMPLATE template0` e recebe o esquema com `Artisan::call('migrate', ['--database' => 'pgsql_side', '--force' => true])` (confira como o projeto isola isso) ou por SQL mínimo das cinco tabelas, o que for mais simples.
- [ ] **Step 4: Rodar e ver passar; commit** `feat: restauração em banco ao lado e verificação (SideDatabase)`.

### Task 6: Progresso, manutenção, avisos e o orquestrador `RestoreDatabase`

**Files:**
- Create: `app/Services/Backup/Restore/RestoreProgress.php`, `Maintenance.php`, `RestoreRequest.php`, `RestoreSource.php`, `RestoreDatabase.php`, `app/Jobs/RestoreDatabaseJob.php`, `app/Notifications/BackupRestored.php`
- Modify: `app/Settings/BackupStatus.php` (`recordRestore(string $file, string $actor): void`, `lastRestore(): ?array`), `app/Livewire/Alerts/Bell.php` (`state === 'restored'` → `Banco restaurado`)
- Test: `tests/Feature/Backup/Restore/RestoreProgressTest.php`, `RestoreDatabaseTest.php`, `tests/Feature/Alertas/BackupRestoredBellTest.php`

**Interfaces:**
- Consumes: `DumpInspector`, `AdminDatabase`, `SideDatabase`, `RunBackup(BackupKind::Antes)`, `BackupCatalog`, `RecoverMessagesJob`, `AlertRecipients::admins()`.
- Produces:
  - `RestoreProgress` (cache do Redis, chave `restore:state`): `const STEPS` (`inspect` "Conferindo o arquivo", `safety` "Backup de segurança", `restore` "Restaurando em um banco novo", `verify` "Verificando o banco restaurado", `swap` "Trocando o banco", `migrate` "Atualizando o esquema", `resume` "Voltando ao ar", `recover` "Recuperando mensagens do WhatsApp"); `begin(string $title, array $keys): void`, `running(string $key): void`, `done(string $key): void`, `finish(string $message): void`, `fail(string $message): void`, `isRunning(): bool`, `get(): array{status: 'idle'|'running'|'done'|'failed', title: string, steps: list<array{key: string, label: string, state: 'pending'|'running'|'done'|'failed'}>, message: string}` (TTL 1 dia; sem dado sensível).
  - `Maintenance`: `ready(): bool` (driver de manutenção é `cache`), `enter(): void`, `leave(): void`, `restartWorkers(): void` (`down --retry=30`, `up`, `queue:restart` pela `Artisan`).
  - `RestoreRequest(string $mode /* 'restore'|'switch' */, string $source /* 'bucket:<nome>'|'upload:<ulid>' para restore; nome do banco guardado para switch */, string $actor /* "Nome <email>" */)`.
  - `RestoreSource::fetch(string $source): array{path: string, name: string}` (baixa do bucket para `backup.tmp_path`; só aceita nome que o catálogo conhece ou `upload:` com ulid de 26 caracteres) e `RestoreSource::discard(string $source): void` (apaga o arquivo enviado do bucket e o temporário).
  - `RestoreDatabase::__invoke(RestoreRequest): void`: trava `Cache::lock('restore-database', 1800)` (ocupada: lança `RestoreRejected('Já há uma restauração em andamento.')`); passos conforme o spec: `restore` = inspect → safety → restore → verify → (manutenção) swap → migrate → resume → recover → `BackupStatus::recordRestore` + `BackupRestored` + `Log::notice`; `switch` = swap → migrate → resume → recover (sem criar nem restaurar; o banco ativo vira o novo `_antes_`). Falha antes do `swap`: apaga o banco novo, descarta o arquivo, `RestoreProgress::fail`, sem manutenção. Falha no `swap`/`migrate`: desfaz a troca, sai da manutenção. `finally` sempre `Maintenance::leave()` se entrou e libera o lock. Recuperação: um `RecoverMessagesJob(channelId, from, now, notifyAlways: true)` por canal `active` e conectado, com `from = max(data do backup ou do banco guardado, agora − recover_hours)`.
  - `RestoreDatabaseJob(RestoreRequest $request)`: fila `history`, `$timeout = 840`, `$tries = 1`; chama o orquestrador; exceção vira `RestoreProgress::fail` com a mensagem segura.

- [ ] **Step 1: Testes (RED).** Progresso: `test_comeca_ordena_e_termina_os_passos`, `test_falha_marca_o_passo_e_guarda_a_mensagem`, `test_get_sem_estado_devolve_idle`. Orquestrador, com `AdminDatabase`/`SideDatabase`/`Maintenance`/`RunBackup`/`DumpInspector` como mocks do container e `Queue::fake()`: `test_restaura_na_ordem_do_spec` (ordem das chamadas e dos passos), `test_arquivo_recusado_nao_cria_banco_nem_entra_em_manutencao`, `test_falha_no_backup_de_seguranca_aborta`, `test_falha_na_restauracao_apaga_o_banco_novo`, `test_falha_na_verificacao_apaga_o_banco_novo_e_nao_troca`, `test_falha_na_troca_sai_da_manutencao_e_mantem_o_banco_antigo`, `test_falha_na_migracao_desfaz_a_troca`, `test_sempre_sai_da_manutencao_mesmo_com_erro_inesperado`, `test_segunda_restauracao_com_o_lock_ocupado_e_recusada`, `test_recupera_mensagens_so_dos_canais_ativos_e_conectados_desde_o_backup_ate_72h`, `test_switch_nao_restaura_nem_faz_backup_de_seguranca`, `test_log_e_aviso_aos_admins_no_fim`, `test_mensagens_de_erro_sem_senha`. Sino: `test_bell_mostra_banco_restaurado`.
- [ ] **Step 2: Rodar e ver falhar.** `php artisan test tests/Feature/Backup/Restore tests/Feature/Alertas`.
- [ ] **Step 3: Implementar** conforme as interfaces. `Maintenance::ready()` lê `config('app.maintenance.driver') === 'cache'`.
- [ ] **Step 4: Rodar e ver passar** (as suítes acima) e Pint/PHPStan/PHPMD nos arquivos novos.
- [ ] **Step 5: Commit** `feat: orquestrador da restauração (RestoreDatabase), progresso, manutenção e aviso`.

### Task 7: Página de andamento, JSON de estado e página 503

**Files:**
- Modify: `app/Http/Controllers/RestoreStatusController.php`, `RestorePageController.php` (criados vazios na Task 1), `routes/web.php`
- Create: `resources/views/restore/progress.blade.php`, `resources/views/errors/503.blade.php`
- Test: `tests/Feature/Restore/RestoreStatusTest.php`

**Interfaces:**
- Consumes: `RestoreProgress::get()`.
- Produces: `GET /restauracao/estado` → JSON do `RestoreProgress::get()` (sem usuário, arquivo ou segredo), `Cache-Control: no-store`; `GET /restauracao` → página que consulta o JSON a cada 2 s com um componente `Alpine.data` (build CSP: sem expressões inline, ver `AlpineCspSubsetTest`) e volta para `configuracoes.backups` quando o estado vira `done`/`idle`, mostrando a mensagem de falha se `failed`. `errors/503` em português, com recarga automática a cada 10 s e o texto "Restauração do banco em andamento. Volta em instantes." quando `RestoreProgress::isRunning()`.

- [ ] **Step 1: Testes (RED):** `test_estado_devolve_os_passos_e_nenhum_dado_sensivel`, `test_estado_idle_sem_restauracao`, `test_pagina_de_andamento_abre_sem_login_e_em_manutencao`, `test_503_de_manutencao_fala_da_restauracao_e_recarrega` (com `down` real e driver de arquivo no teste), `test_json_nao_e_cacheado`.
- [ ] **Step 2: Rodar e ver falhar.** `php artisan test tests/Feature/Restore tests/Unit/AlpineCspSubsetTest.php`.
- [ ] **Step 3: Implementar** controllers, views e o JS (`resources/js`), registrando o componente no bundle como os demais.
- [ ] **Step 4: Rodar e ver passar, `npm run build` e `node --test tests/js`.**
- [ ] **Step 5: Commit** `feat: página de andamento da restauração e página 503 da manutenção`.

### Task 8: Tela de Backup: restaurar, enviar arquivo, bancos guardados e desfazer

**Files:**
- Modify: `app/Livewire/Settings/Backups.php`, `resources/views/livewire/settings/backups.blade.php`, `config/livewire.php` (`temporary_file_upload.rules` para `max:102400`)
- Test: `tests/Feature/Livewire/BackupsRestoreTest.php`

**Interfaces:**
- Consumes: `DumpInspector`, `RestoreSource`, `RestoreProgress`, `RestoreDatabaseJob`, `RestoreRequest`, `AdminDatabase::stored()/canCreate()`, `Maintenance::ready()`.
- Produces no `Backups`: `use WithFileUploads`; `public $upload`; `public ?string $source`; `public array $info`; `public array $problems`; `public string $confirm`; `public ?string $target` (banco guardado a que se volta); `inspect(string $source): void`, `updatedUpload(): void` (valida `file|max:102400`, nome termina em `.dump`, envia ao bucket `restauracao/<ulid>.dump` e chama `inspect`), `cancelRestore(): void`, `restore(): void`, `chooseStored(string $name): void`, `switchBack(): void`. `restore()`/`switchBack()`: `authorize('admin')`; recusam se `$confirm !== config('backup.restore.confirm_word')`, se `RestoreProgress::isRunning()`, se `! Maintenance::ready()` ou `! AdminDatabase::canCreate()` (mensagem diz o que falta); chamam `RestoreProgress::begin(...)`, despacham `RestoreDatabaseJob` e redirecionam para `route('restauracao')`. `switchBack()` só aceita nome que está em `AdminDatabase::stored()`. A view ganha: botão **Restaurar** por linha, **Enviar arquivo…**, painel de conferência (nome, data, banco de origem, versões, tamanho, avisos), campo de confirmação, lista **Bancos guardados** (nome, data, tamanho, **Voltar para este**) e "Última restauração" (`BackupStatus::lastRestore()`).

- [ ] **Step 1: Testes (RED):** `test_restaurar_abre_a_conferencia_com_os_dados_do_arquivo`; `test_arquivo_estranho_mostra_o_erro_e_nao_habilita`; `test_sem_a_palavra_certa_nao_despacha` e `test_com_a_palavra_despacha_o_job_na_fila_history_e_vai_para_a_pagina_de_andamento`; `test_recusa_com_restauracao_em_andamento`; `test_recusa_sem_manutencao_compartilhada_e_diz_o_que_falta`; `test_so_admin` (supervisor 403 em `inspect`/`restore`); `test_envio_de_arquivo_vai_para_o_bucket_em_restauracao_e_nao_aparece_na_lista`; `test_envio_recusa_arquivo_sem_extensao_dump_e_acima_de_100mb`; `test_nome_de_banco_guardado_forjado_e_recusado` e `test_voltar_para_este_despacha_o_job_switch`; `test_lista_de_bancos_guardados_com_data_e_tamanho` (com `AdminDatabase` mockado).
- [ ] **Step 2: Rodar e ver falhar.** `php artisan test tests/Feature/Livewire/BackupsRestoreTest.php tests/Feature/Livewire/BackupsPageTest.php`.
- [ ] **Step 3: Implementar.** Upload: Livewire `WithFileUploads`; o arquivo temporário é gravado no bucket por stream (nunca `file_get_contents`). Conferir os limites de PHP/nginx da imagem serversideup (`PHP_UPLOAD_MAX_FILE_SIZE`, `PHP_POST_MAX_SIZE`, `client_max_body_size`) e fixá-los em 100 MB por `ENV` no `docker/Dockerfile` se o padrão for menor. Alpine só por `Alpine.data` (build CSP).
- [ ] **Step 4: Rodar e ver passar** (os dois arquivos + `tests/Unit/ThemeTokensTest.php` + `AlpineCspSubsetTest`).
- [ ] **Step 5: Commit** `feat: restauração pela tela de Backup (lista, envio de arquivo, bancos guardados e desfazer)`.

### Task 9: Backup manual que atualiza sozinho

**Files:**
- Create: `app/Services/Backup/ManualBackupState.php`
- Modify: `app/Console/Commands/BackupDatabaseCommand.php`, `app/Livewire/Settings/Backups.php`, `resources/views/livewire/settings/backups.blade.php`
- Test: `tests/Feature/Backup/ManualBackupStateTest.php`, acréscimos em `BackupsPageTest.php` e `BackupDatabaseCommandTest.php`

**Interfaces:**
- Produces: `ManualBackupState` (Redis, chave `backup:manual`, TTL 20 min): `markStarted(): void`, `markFinished(?string $file, ?string $error): void`, `get(): array{status: 'idle'|'running'|'done'|'failed', file: ?string, error: ?string}`. `runNow()` chama `markStarted()`; o comando com `--manual` chama `markFinished(...)` em `finally` (também com falha ou `null` por lock). A view usa `wire:poll.3s` **só** enquanto `status === 'running'` e mostra "Backup em andamento…", "Backup concluído: <nome>" ou o erro.

- [ ] **Step 1: Testes (RED):** `test_estado_do_backup_manual_ciclo_completo`; `test_expira_sozinho` (`travel` 21 min); `test_fazer_agora_marca_em_andamento_e_a_tela_faz_polling`; `test_tela_sem_backup_em_andamento_nao_faz_polling`; `test_comando_manual_marca_concluido_com_o_nome_e_marca_falha_com_a_mensagem`; `test_comando_diario_nao_mexe_no_estado_manual`.
- [ ] **Step 2: Rodar e ver falhar.** `php artisan test tests/Feature/Backup/ManualBackupStateTest.php tests/Feature/Livewire/BackupsPageTest.php tests/Feature/Console/BackupDatabaseCommandTest.php`.
- [ ] **Step 3: Implementar.**
- [ ] **Step 4: Rodar e ver passar; commit** `feat: tela de backup atualiza sozinha enquanto um backup manual roda`.

### Task 10: Limpeza dos bancos guardados

**Files:**
- Create: `app/Console/Commands/PurgeOldDatabasesCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/PurgeOldDatabasesCommandTest.php`, acréscimo em `ScheduleTest.php`

**Interfaces:**
- Produces: `app:purge-old-databases`: apaga (`AdminDatabase::drop`) os `_antes_` com mais de `config('backup.restore.keep_old_days')` dias; nunca o ativo; agendado todo dia às 03:00 do horário de exibição, `withoutOverlapping(10)`.

- [ ] **Step 1: Testes (RED):** com Postgres de verdade e bancos descartáveis: `test_apaga_so_os_antes_com_mais_de_7_dias`, `test_nunca_apaga_o_banco_ativo_nem_banco_de_nome_diferente`, `test_sem_nada_a_apagar_sai_com_sucesso`; `ScheduleTest`: `test_limpeza_de_bancos_guardados_diaria`.
- [ ] **Step 2: Rodar e ver falhar, implementar, rodar e ver passar.**
- [ ] **Step 3: Commit** `feat: limpeza diária dos bancos guardados (7 dias)`.

### Task 11: Roteiro, prova real e ambiente do beta

**Files:**
- Modify: `docs/backup-e-restauracao.md`
- Create: `docs/superpowers/reports/2026-11-12-restauracao-banco.md`

**Interfaces:**
- Consumes: Tasks 1 a 10.

- [ ] **Step 1: Prova real em Docker** (imagem construída do branch, Postgres 18 e Redis de teste, `APP_MAINTENANCE_DRIVER=cache`): com um banco populado, gerar um backup, alterar dados, **restaurar pela imagem** (rodar o orquestrador com `php artisan tinker`/comando de teste) e conferir contagens e que o banco antigo ficou como `_antes_`; **voltar para este** e conferir; arquivo estranho (um dump com função) recusado. Medir os tempos. Limpar tudo.
- [ ] **Step 2: Roteiro:** reescrever "Restaurar de verdade" para o fluxo pela tela (passo a passo, o que a tela faz, o que esperar, como desfazer, limites) mantendo o roteiro manual como plano B; relatório com a prova.
- [ ] **Step 3: Variáveis do beta (eu faço pelo Coolify, só no beta):** `APP_MAINTENANCE_DRIVER=cache` e `APP_MAINTENANCE_STORE=redis` no app, worker e scheduler; ler de volta; sem restart antes do merge (o deploy do merge as aplica).
- [ ] **Step 4: Commit** `docs: roteiro da restauração pela tela e relatório da prova`.

---

## Auto-revisão (feita ao escrever)

- **Cobertura do spec:** upload e lista (T8); conferência e lista de permissão (T3); pré-requisitos e confirmação (T8); passos, troca, desfazer em caso de falha, recuperação (T4–T6); andamento e 503 (T1, T7); desfazer e limpeza de 7 dias (T8, T10); backup manual que atualiza (T9); infra (T1, T11); prova real (T11).
- **Desvios do spec, para a decisão do construtor:** o envio vai pelo Livewire (`WithFileUploads` com limite global subido para 100 MB) em vez de rota própria: mais simples e compatível com a CSP; custo: o limite de 100 MB vale para todo upload temporário do Livewire (cada tela segue validando o seu próprio limite).
- **Consistência:** `RestoreProgress`, `RestoreRequest`, `AdminDatabase`, `SideDatabase`, `DumpInspector`, `RestoreSource`, `Maintenance`, `ManualBackupState` têm um nome só em todas as tarefas.
