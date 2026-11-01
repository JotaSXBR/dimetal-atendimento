# Deploy rápido Implementation Plan

> **Status: concluído** (PR #51, mergeado, **um PR só**: a imagem base foi publicada no GHCR antes do merge e a troca do `FROM` entrou junto; as menções a "PR A/PR B" abaixo são o desenho original). Acrescentados a pedido: Node 26 e `node --test tests/js` no CI. Relatório em `docs/superpowers/reports/2026-10-31-ci-deploy-relatorio.md`.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline). Steps use checkbox (`- [x]`).

**Goal:** Cortar ~2 min do deploy do beta e eliminar o warning de build-time sem expor segredos.

**Architecture:** Imagem base pré-compilada no GHCR, Dockerfile enxuto, variáveis do Coolify só de runtime.

**Tech Stack:** Docker/BuildKit, GitHub Actions, Coolify API.

**Spec:** `docs/superpowers/specs/2026-10-31-deploy-rapido-design.md`

## Global Constraints
- Actions fixados por SHA (login v4.7.0, buildx v4.4.1, build-push v7.4.0); commits em português com prefixo; nunca na `main`.
- Só o beta; nunca imprimir token ou valores de variáveis.

## Review Focus
- Imagem base com a mesma lista de extensões do Dockerfile atual (`pdo_pgsql intl zip gd bcmath redis`).
- `docker build --target runtime` continua funcionando (security-scan) e o `runtime` sai com `storage` e `bootstrap/cache` gravável pelo `www-data`.
- Flags do Coolify revertíveis: snapshot salvo antes de alterar.

### Task 1: Dockerfile enxuto
**Files:** Modify `docker/Dockerfile`
- [x] Remover `&& chown -R www-data:www-data /var/www/html` do `RUN php artisan package:discover ...`; adicionar `ENV NPM_CONFIG_UPDATE_NOTIFIER=false` no estágio `assets`.
- [x] Verificar: `docker build --target runtime -f docker/Dockerfile -t dimetal-scan:local .` conclui e `docker run --rm --entrypoint sh dimetal-scan:local -c 'test -w storage && test -w bootstrap/cache && echo ok'` imprime `ok`.
- [x] Commit `perf: tira o chown recursivo redundante do Dockerfile`.

### Task 2: Imagem base e workflow
**Files:** Create `docker/base/Dockerfile`, `.github/workflows/base-image.yml`
- [x] `docker/base/Dockerfile`: `FROM serversideup/php:8.5.12-fpm-nginx-bookworm`, `USER root`, `RUN install-php-extensions pdo_pgsql intl zip gd bcmath redis`, `USER www-data`.
- [x] Workflow: `on: push (main, paths docker/base/**, o próprio workflow), schedule semanal, workflow_dispatch`; `permissions: contents: read, packages: write`; login no `ghcr.io` com `GITHUB_TOKEN`; buildx; `build-push-action` com `context: docker/base`, tags `ghcr.io/jotasxbr/dimetal-php-base:8.5` e `:sha-${{ github.sha }}`, `cache-from/to: type=gha`; em `pull_request` só constrói (`push: false`).
- [x] Verificar: build local `docker build -t base-test docker/base` e `docker run --rm base-test php -m | grep -E "intl|gd|bcmath|pdo_pgsql|redis|zip"` lista as seis; `actionlint` limpo.
- [x] Commit `feat: imagem base do PHP pré-compilada no GHCR`.

### Task 3: Dependabot
**Files:** Modify `.github/dependabot.yml`
- [x] Adicionar `package-ecosystem: docker` para `/docker` e `/docker/base`, semanal, com o mesmo `cooldown`.
- [x] Commit `chore: dependabot cobre as imagens Docker`.

### Task 4: Coolify (fora do git)
- [x] Salvar snapshot dos flags de cada app (app, worker, scheduler, reverb) em `$TEMP`, sem valores.
- [x] `PATCH /applications/{uuid}/envs` por variável com `is_buildtime=false`, lendo o valor do próprio `GET` e sem imprimi-lo; conferir pelo `GET` que `is_buildtime` ficou `false` em todas.

### Task 5 (depois do merge do PR A e do pacote público): trocar o FROM
**Files:** Modify `docker/Dockerfile`
- [x] `FROM ghcr.io/jotasxbr/dimetal-php-base:8.5 AS base`; remover a linha `install-php-extensions` e `USER root` redundante só se o base já deixa root (manter `USER root` antes do `WORKDIR`).
- [x] Verificar o build do `runtime` e o deploy do beta com tempo menor no log.

## Atualização do escopo (um PR só)
- Task 5 passa a fazer parte deste PR: publicar `dimetal-php-base:8.5` no GHCR antes do merge (`docker login ghcr.io` com token
  `write:packages`, `docker push`), dono torna o pacote público, `docker logout` + `docker pull` anônimo prova a visibilidade, e então a troca do `FROM`.
- Task 6: Node 26 em `tests.yml` e `docker/Dockerfile`. Task 7: passo `node --test tests/js` no job `tests`. Task 8: `docs/deploy.md`.
