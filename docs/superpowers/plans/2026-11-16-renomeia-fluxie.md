# Renomeação para Fluxie Inbox: plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: superpowers:subagent-driven-development (este projeto) para executar tarefa por tarefa. Passos com checkbox (`- [ ]`).

**Objetivo:** o projeto deixa de ser "da Dimetal" e passa a ser **Fluxie Inbox** em código, configuração, CI, imagens, README, LICENSE e docs vivos, aceitando os backups antigos `dimetal-*`.

**Arquitetura:** troca mecânica de nomes, mais uma mudança de comportamento: o `BackupCatalog` grava com o prefixo novo e lê os dois. A imagem base passa para `ghcr.io/fluxie-tech/fluxie-php-base`. Ela precisa existir **e estar pública** antes do merge (ver "Antes do merge").

**Stack:** PHP 8.5, Laravel 13, PHPUnit 12, GitHub Actions, Docker.

**Spec:** `docs/superpowers/specs/2026-11-16-modelo-distribuidora-e-renomeacao-design.md` (seção "Projeto 1: design").

## Restrições globais

- Produto **Fluxie Inbox**; organização `fluxie-tech`; repositório `fluxie-inbox`; copyright `Copyright (c) 2026 Fluxie`.
- Nomes técnicos:
  - banco `fluxie` e teste `fluxie_test`, usuário `fluxie`, senha de dev `secret`;
  - composer `fluxie/inbox`;
  - imagem base `ghcr.io/fluxie-tech/fluxie-php-base`;
  - prefixo de backup `fluxie-`;
  - chaves do navegador `fluxie-*`.
- **Exceções** em que "dimetal" continua:
  - `docs/superpowers/**` e `docs/ux-audit/**` (histórico);
  - `tests/Fixtures/restore/toc-esquema-atual.txt` (captura real de um dump; a lista de permissão do `DumpInspector` ignora o dono e o `dbname`);
  - o padrão de **leitura** do `BackupCatalog` (`dimetal|fluxie`) e os testes que provam a leitura do prefixo antigo;
  - o alvo do ZAP em `security-scan.yml` (`dimetal-beta.fluxie.com.br`), até o projeto 2.
- Texto de produto neutro onde hoje cita a Dimetal: nada de "Dimetal" em tela.
- Testes afetados no ciclo: `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`; suíte inteira (`php artisan test --parallel`) antes de cada commit.

## Foco da revisão

1. **Backup antigo na lista e na restauração:** um arquivo `dimetal-2026-11-10-0300-diario.dump` aparece na lista, na ordem certa, e pode ser baixado e restaurado (`find` o acha). Tarefa 1.
2. **Listas mistas:** arquivos `dimetal-*` e `fluxie-*` juntos são ordenados por data, não por prefixo, e a rotação (`prune`) conta os dois como do mesmo tipo. Tarefa 1.
3. **Nome forjado:** `fluxie-../x.dump`, `fluxiex-2026-...dump` e `dimetal-2026-11-10-0300-outro.dump` continuam fora do catálogo. Tarefa 1.
4. **Chaves do navegador:** quem já tinha o som ligado perde a preferência uma vez (efeito aceito no spec). Nenhum JS pode ler as chaves velhas e as novas misturadas. Tarefa 2 confere que não sobra `dimetal-` em `resources/js`.
5. **Suíte local depois do merge:** o Postgres de teste com `fluxie`/`fluxie_test` sobe pelo comando novo do `CLAUDE.md` e a suíte passa. Tarefa 2 prova isso ao rodar a suíte nele.

---

### Task 1: backup grava `fluxie-` e lê os dois prefixos

**Arquivos:**
- Modificar: `app/Services/Backup/BackupCatalog.php` (`PATTERN`, `name()`, docblock)
- Testes: `tests/Feature/Backup/BackupCatalogTest.php`, e os que afirmam nomes `dimetal-...dump` gerados (`RunBackupTest`, `BackupDatabaseCommandTest`, `BackupsPageTest`, `BackupsRestoreTest`, `BackupDownloadTest`, `ManualBackupStateTest` e outros que o `codegraph affected` apontar)

**Interfaces:**
- Produz: `BackupCatalog::name(BackupKind, CarbonImmutable): string` → `fluxie-AAAA-MM-DD-HHMM-<tipo>.dump`; `parse` aceita `^(?:dimetal|fluxie)-(\d{4}-\d{2}-\d{2})-(\d{4})-(diario|semanal|manual|antes)\.dump$`.

- [ ] **Passo 1: testes (RED)** em `BackupCatalogTest`:
  - `test_nome_novo_usa_o_prefixo_fluxie`: `name(BackupKind::Manual, CarbonImmutable::parse('2026-11-16 15:30', display tz))` === `'fluxie-2026-11-16-1530-manual.dump'`.
  - `test_lista_backups_antigos_dimetal_junto_com_os_novos_por_data`: no disco fake, `dimetal-2026-11-10-0300-diario.dump` e `fluxie-2026-11-16-0300-diario.dump` → `all()` devolve os dois, o `fluxie` primeiro.
  - `test_find_acha_backup_antigo_dimetal`: `find('dimetal-2026-11-10-0300-diario.dump')` não é nulo.
  - `test_prune_conta_os_dois_prefixos_no_mesmo_tipo`: `backup.keep.diario = 1` com um arquivo de cada prefixo → apaga o mais velho, o `dimetal`.
  - `test_nomes_fora_do_padrao_continuam_fora`: `fluxiex-2026-11-16-0300-diario.dump`, `dimetal-2026-11-10-0300-outro.dump` e `fluxie-2026-11-16-0300-diario.dump.tmp` não entram em `all()`.
  - Os testes existentes que esperam um nome **gerado** `dimetal-...` passam a esperar `fluxie-...`. Os que só semeiam arquivos de exemplo podem manter `dimetal-` (eles provam a leitura antiga), a critério do builder, desde que cada prefixo continue coberto.
- [ ] **Passo 2: rodar e ver falhar:** `php artisan test --filter=BackupCatalogTest`. Esperado: FAIL no nome novo e na lista mista.
- [ ] **Passo 3: implementar** o `PATTERN` com `(?:dimetal|fluxie)` (comentário de uma linha: o prefixo antigo é só leitura) e o `name()` com `fluxie-`.
- [ ] **Passo 4: rodar e ver passar:** o filtro `--filter=Backup` e depois a suíte inteira.
- [ ] **Passo 5: commit**: `feat: backup grava fluxie-*.dump e continua lendo os dimetal-*`.

### Task 2: nomes técnicos e textos (banco, composer, configs, JS, telas, testes)

**Arquivos:**
- Modificar:
  - `.env.example` (`APP_NAME="Fluxie Inbox"`, `DB_DATABASE=fluxie`, `DB_USERNAME=fluxie`);
  - `compose.yaml` (`POSTGRES_DB: fluxie`, `POSTGRES_USER: fluxie`);
  - `docker/postgres/init.sql` (`CREATE DATABASE fluxie_test;`);
  - `phpunit.xml` (`fluxie_test`, `fluxie`);
  - `.github/workflows/tests.yml` (`POSTGRES_DB: fluxie_test`, `POSTGRES_USER: fluxie`, `pg_isready -U fluxie -d fluxie_test`, `DB_DATABASE`, `DB_USERNAME`);
  - `composer.json` (`"name": "fluxie/inbox"`) e `composer.lock` (`composer.bat update --lock`, só o hash);
  - `package.json` (`name`, se existir);
  - `phpmd.xml` (`ruleset name="Fluxie"`);
  - `resources/js/alerts.js` e `resources/js/alert-dismiss.js` (todas as chaves e tags `dimetal-` → `fluxie-`);
  - `resources/views/livewire/contacts/index.blade.php` (subtítulo "Quem fala com a Dimetal." → "Quem fala com a empresa.");
  - `app/Services/Inbox/Signature.php` e `config/services.php` (comentários: exemplo `*Jessé @ Empresa:*`, "A de teste e a da empresa");
  - `CLAUDE.md` (os comandos do Postgres de teste com `fluxie`/`fluxie_test`, os comandos `migrate` e `DB_*` correspondentes; "Fluxie Inbox" onde o arquivo nomeia o produto).
- Testes: todos os arquivos em `tests/` com "dimetal", **exceto** a fixture do restore e os casos de prefixo antigo da Task 1.
  - E-mails e domínios de exemplo (`@dimetal.test`, `dimetal.com.br`, `dimetal@gmail.com`) passam para `@fluxie.test` / `fluxie.com.br` / `fluxie@gmail.com`.
  - "Dimetal" como nome de empresa de exemplo passa para "Fluxie".
  - `DIMETAL.docx` passa para `FLUXIE.docx`.
  - Buckets de exemplo `dimetal-beta-*` passam para `fluxie-*`.
  - Prefixos de arquivos temporários de teste também mudam.
  - Nomes derivados do banco de teste (`dimetal_antes_`, `dimetal_restaurando_`) passam a sair do `fluxie_test`: conferir se o teste monta o nome a partir da configuração e ajustar o literal.

**Interfaces:** nenhuma nova.

- [ ] **Passo 1: preparar o banco de teste novo** no container `pg-test` que já está no ar (usuário atual `dimetal`):
  - `docker exec pg-test psql -U dimetal -d postgres -c "CREATE ROLE fluxie LOGIN SUPERUSER PASSWORD 'secret'"`;
  - `docker exec pg-test psql -U dimetal -d postgres -c "CREATE DATABASE fluxie_test OWNER fluxie"`;
  - migrar com o comando do `CLAUDE.md` já trocado para `fluxie`/`fluxie_test`.
- [ ] **Passo 2: trocar** os arquivos listados. Edição com a ferramenta `Edit`. Python só para a mesma troca em muitos arquivos, com string raw e `assert` na contagem (regra do `CLAUDE.md`).
- [ ] **Passo 3: conferir resíduos:**
  - `git grep -n -i dimetal -- . ':!docs/superpowers' ':!docs/ux-audit' ':!tests/Fixtures/restore' ':!composer.lock' ':!package-lock.json'`;
  - a saída só pode trazer o `PATTERN` do `BackupCatalog` e seu comentário, os testes de prefixo antigo da Task 1, os arquivos das Tasks 3 e 4 (Dockerfiles, `base-image.yml`, `security-scan.yml`, README, LICENSE, `docs/*.md`) e nada mais.
- [ ] **Passo 4: rodar** `php artisan test --parallel` (no `fluxie_test`), `node --test tests/js`, `vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress` e `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`. Esperado: tudo verde.
- [ ] **Passo 5: commit**: `chore: nomes técnicos e textos passam de dimetal para fluxie`.

### Task 3: imagens e CI (`fluxie-php-base`)

**Arquivos:**
- Modificar:
  - `docker/Dockerfile` (`FROM ghcr.io/fluxie-tech/fluxie-php-base:8.5 AS base`);
  - `docker/base/Dockerfile` (comentário e `LABEL org.opencontainers.image.source="https://github.com/fluxie-tech/fluxie-inbox"`);
  - `.github/workflows/base-image.yml` (tags `ghcr.io/fluxie-tech/fluxie-php-base:<php>` e `:sha-<sha>`);
  - `.github/workflows/security-scan.yml` (tags locais `fluxie-base:ci` e `fluxie-scan:ci` e as referências a elas; o alvo do ZAP **fica**).

**Interfaces:** produz a imagem `ghcr.io/fluxie-tech/fluxie-php-base:8.5`, que o `docker/Dockerfile` consome.

- [ ] **Passo 1: trocar** os quatro arquivos.
- [ ] **Passo 2: conferir** que não sobra `jotasxbr` nem `dimetal` neles (fora o alvo do ZAP): `git grep -n -i "jotasxbr\|dimetal" -- docker .github`.
- [ ] **Passo 3: validar a sintaxe dos workflows**: `python -c "import yaml,sys;[yaml.safe_load(open(f)) for f in sys.argv[1:]]" .github/workflows/base-image.yml .github/workflows/security-scan.yml`. Esperado: sem erro.
- [ ] **Passo 4: commit**: `ci: imagem base passa a ghcr.io/fluxie-tech/fluxie-php-base`.

### Task 4: README, LICENSE e docs vivos

**Arquivos:**
- Modificar:
  - `LICENSE` (linha 3: `Copyright (c) 2026 Fluxie`);
  - `README.md`, reescrito como produto público "Fluxie Inbox", **em português**, curto. Seções:
    - o que é (central de atendimento por WhatsApp: fila por setor, tickets, automação sem código, SLA, auditoria);
    - recursos principais;
    - stack (tabela atual);
    - rodando localmente (o roteiro atual, com `fluxie`);
    - instalação no Coolify: "em preparação" (projeto 2), apontando para `docs/deploy.md`;
    - testes e qualidade (como hoje);
    - licença MIT.

    Sai "Projeto interno da Dimetal Distribuidora. Sem cadastro público" e a menção ao Cam Krolik.
  - `docs/deploy.md`, `docs/deploy-coolify-api.md` e `docs/backup-e-restauracao.md`: imagem, banco, prefixo do backup (dizer que os antigos `dimetal-*` continuam aceitos) e o repositório `fluxie-tech/fluxie-inbox`. UUIDs e domínio do beta ficam, com uma linha no topo do `deploy.md`: "O ambiente beta descrito aqui será substituído no projeto 2 (instalação por compose)".

- [ ] **Passo 1: editar** os arquivos.
- [ ] **Passo 2: conferir** que `git grep -n -i dimetal -- README.md LICENSE docs/*.md` só traz as menções históricas que o texto explica (beta, prefixo antigo).
- [ ] **Passo 3: commit**: `docs: README público do Fluxie Inbox, LICENSE da Fluxie e docs com os nomes novos`.

### Antes do merge (controlador, com o dono)

1. Disparar o `base-image` no branch: `gh workflow run base-image -R fluxie-tech/fluxie-inbox --ref feat/renomeia-fluxie`. Esperar ficar verde e conferir que o pacote `fluxie-php-base` existe na organização.
2. **Dono:** tornar o pacote público (Organização → Packages → `fluxie-php-base` → Package settings → Change visibility → Public).
3. Conferir de fora, sem login: `docker pull ghcr.io/fluxie-tech/fluxie-php-base:8.5`.
4. Portão 7 completo e revisão final. O PR e o merge seguem as Regras do dono.
