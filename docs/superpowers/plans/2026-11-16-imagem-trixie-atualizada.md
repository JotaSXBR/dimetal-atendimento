# Imagem base em Debian 13 e sempre atualizada: plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** a imagem do app sai sem vulnerabilidade HIGH/CRITICAL com correção publicada, em Debian 13, e continua assim toda semana.

**Architecture:** atualização dos pacotes do sistema na imagem base (camada própria, cache quebrado por execução); scan do CI constrói a
base do branch. Sem código de aplicação.

**Tech Stack:** Docker/BuildKit, GitHub Actions, Trivy 0.70.0.

**Spec:** `docs/superpowers/specs/2026-11-16-imagem-trixie-atualizada-design.md`

## Global Constraints

- Base: `serversideup/php:8.5.12-fpm-nginx-trixie` (a leitura de versão em `.github/actions/versions` usa `^FROM serversideup/php:X.Y`).
- Extensões continuam `pdo_pgsql intl zip gd bcmath redis`; cliente `postgresql-client-18`.
- Critério do scan: Trivy `--severity CRITICAL,HIGH --ignore-unfixed` sem achados.
- Comentários em português, no estilo dos Dockerfiles atuais.

## Review Focus

- Base antiga (bookworm) publicada ainda em uso durante o merge: o Dockerfile da app tem de montar com ela também.
- Cache do `gha`: a camada de atualização tem de refazer a cada execução, e as extensões não.
- O sed do nginx (`static-security-headers`) tem de seguir achando 2 pontos na imagem trixie.

---

### Task 1: base trixie atualizada, app sem remendo, scan com a base do branch

**Files:**
- Modify: `docker/base/Dockerfile`, `docker/Dockerfile:59-62` e `:87`, `.github/workflows/base-image.yml` (passo "Build and push"),
  `.github/workflows/security-scan.yml` (passo "Build the runtime image")

- [ ] **Step 1 (RED):** construir a app atual e escanear: `docker build --target runtime -f docker/Dockerfile -t dimetal-scan:antes .` e
  Trivy com os critérios do CI → falha com `linux-libc-dev` CVE-2026-99083.
- [ ] **Step 2:** `docker/base/Dockerfile`: trixie; depois das extensões, `ARG SECURITY_REFRESH` + `apt-get update && apt-get upgrade -y`
  (`DEBIAN_FRONTEND=noninteractive`) + limpar listas, como root; comentário com o porquê (imagem de cima publicada antes das correções;
  o ARG quebra o cache só desta camada).
- [ ] **Step 3:** `base-image.yml`: `build-args: SECURITY_REFRESH=${{ github.run_id }}`.
- [ ] **Step 4:** `docker/Dockerfile`: tirar o bloco do `--only-upgrade apt`; PGDG com `$(. /etc/os-release && echo "$VERSION_CODENAME")-pgdg`;
  ajustar o comentário ("O Debian da base traz o cliente 15" deixa de valer: dizer só que o do Debian é antigo para o servidor 18).
- [ ] **Step 5:** `security-scan.yml`: antes do build da app, `docker build -t dimetal-base:ci docker/base`; o build da app passa
  `--build-context <imagem do FROM da base>=docker-image://dimetal-base:ci`, com a referência lida do `docker/Dockerfile` (sem fixar `8.5`).
  Comentário explicando que o PR prova a base do branch.
- [ ] **Step 6 (GREEN):** localmente, os mesmos dois comandos do Step 5 + Trivy → 0 achados; `php -m` com as 6 extensões;
  `pg_dump --version` = 18; build também com a base publicada atual (bookworm) para provar a transição; alvos `worker`, `scheduler`,
  `reverb` montam.
- [ ] **Step 7:** suíte `php artisan test --parallel` (não muda, mas o portão pede) e commit `fix: imagem base em Debian 13 e atualizada toda semana`.
