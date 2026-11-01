# Dependabot: versões e grupos — Plano

> **Status: em andamento** (PR #54). Acrescentado após a revisão: a tag da imagem base no `base-image.yml` segue a versão do PHP do Dockerfile, em vez de `:8.5` fixa.

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline).

**Goal:** Dependabot enxerga PHP/Node/serviços do CI e não mistura major com patch/minor.
**Spec:** `docs/superpowers/specs/2026-11-01-dependabot-versoes-design.md`

## Global Constraints
Actions por SHA; commits em português; nunca na `main`; só beta.

## Review Focus
- Ação `versions` falha alto se o `FROM` mudar de formato.
- Guarda detecta Postgres/Redis divergentes (RED provado trocando a versão).
- `fontaine` major ignorado; major dos demais pacotes continua chegando em PR próprio.

### Task 1: `.github/actions/versions/action.yml` + `tests.yml` (php/node por saída da ação) — verificação: `actionlint` limpo e CI verde.
### Task 2: guarda `compose.yaml` x `tests.yml` no job `static` — RED: trocar `redis:8-alpine` por `9` no `tests.yml` e ver a divergência.
### Task 3: `dependabot.yml` (grupos minor-patch, major à parte, ignore fontaine major, docker-compose) — verificação: run "Dependabot Updates" sem erro de config.
### Task 4: pins (`deploy-beta` checkout SHA, `actions/cache` v6.1.0) e docs (`docs/deploy.md`, relatório final).
### Task 5: PR, CI, merge, deploy do beta observado; fechar #52 e #53 (substituídos).
