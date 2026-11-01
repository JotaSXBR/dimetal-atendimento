# CI paralelo e hook de push Implementation Plan

> **Status: concluído** (PR #50, mergeado). Desvio: a Task 4 (proteção da `main`) foi aplicada **antes** do merge, por POST/PATCH com snapshot, depois que o job `static` já tinha reportado no PR. Resultado e números em `docs/superpowers/reports/2026-10-31-ci-deploy-relatorio.md`.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, regra do dono). Steps use checkbox (`- [x]`) syntax.

**Goal:** Cortar o caminho crítico do CI de ~2 min 15 s para ~1 min 30 s e pegar erro de estilo/tipo antes do push.

**Architecture:** O job `tests` do `tests.yml` perde as checagens estáticas, que ganham o job `static` (sem serviços), em paralelo. Scripts Composer separam as duas metades; `ci:check` continua completo. Um `pre-push` versionado roda a metade barata. A proteção da `main` passa a exigir `static` e PR.

**Tech Stack:** GitHub Actions, Composer scripts, Pint, Larastan, PHPMD, `gh api`.

**Spec:** `docs/superpowers/specs/2026-10-31-ci-paralelo-design.md`

## Global Constraints

- Actions fixados por SHA completo com comentário de versão, como os demais (reusar os SHAs já presentes em `tests.yml`).
- Nome do workflow continua `tests` (o `deploy-beta` depende dele); nome do job novo: `static`.
- Cobertura mínima de 80% inalterada; nenhuma verificação afrouxada.
- Commits em português com prefixo; nunca na `main`; não abrir PR sem pedido.

## Review Focus

- Cache do Larastan velho/corrompido não pode esconder erro: o job continua reprovando com cache frio (testar apagando `.phpstan.cache`).
- `static` e `tests` ambos reprovam por motivos diferentes: cada um aparece vermelho separado.
- Hook em máquina sem `vendor/` (clone novo): falha com mensagem clara, não silenciosa.
- Push de branch sem mudança em PHP: hook não pode travar o push à toa (roda rápido).
- Proteção exigindo `static` antes do check existir na `main` travaria todo PR: aplicar só depois do merge.

---

### Task 1: Scripts Composer e cache de ferramentas

**Files:**
- Modify: `composer.json` (scripts), `phpstan.neon`, `.gitignore`

**Interfaces:**
- Produces: `composer ci:static` (lint:check, types:check, complexity) e `composer ci:tests` (= coverage); `ci:check` segue chamando as quatro etapas. Tasks 2 e 3 usam esses nomes.

- [x] **Step 1:** Em `composer.json`, adicionar `ci:static` = `["@lint:check", "@types:check", "@complexity"]` e `ci:tests` = `["@coverage"]`; reescrever `ci:check` como `["Composer\\Config::disableProcessTimeout", "@ci:static", "@ci:tests"]`.
- [x] **Step 2:** Em `phpstan.neon`, adicionar `tmpDir: .phpstan.cache` em `parameters`; em `.gitignore`, ignorar `.phpstan.cache/` e `.pint.cache`; em `pint.json` (criar se não existir) ou nos scripts, apontar o cache do Pint para `.pint.cache` (`pint --parallel --test --cache-file=.pint.cache` em `lint` e `lint:check`).
- [x] **Step 3:** Rodar `composer ci:static`. Esperado: passa (~45 s frio; repetir e ver o Larastan mais rápido com cache quente).
- [x] **Step 4 (RED do cache):** `rm -rf .phpstan.cache` e rodar `composer types:check` de novo: tem de passar igual (cache frio não muda o veredito).
- [x] **Step 5:** Commit `feat: scripts ci:static e ci:tests e cache do Larastan e do Pint`.

### Task 2: Workflow com job `static` em paralelo

**Files:**
- Modify: `.github/workflows/tests.yml`

**Interfaces:**
- Consumes: `composer ci:static`, `composer ci:tests` (Task 1).
- Produces: checks de nome `static` e `tests` (usados pela proteção na Task 4).

- [x] **Step 1:** Adicionar o job `static`: `runs-on: ubuntu-latest`, `timeout-minutes: 10`, sem `services`; mesmos passos de checkout/PHP (`coverage: none`)/Composer cache/Node dos demais jobs, instalar com `composer install --no-interaction --prefer-dist`, `actions/cache` (SHA já usado) para `.phpstan.cache` e `.pint.cache` com chave `static-${{ runner.os }}-${{ hashFiles('composer.lock', 'phpstan.neon') }}` e `restore-keys` pelo prefixo, e rodar `composer ci:static`. Dispensa `npm`: se o Larastan ou o Pint precisarem de `vite`/assets, rodar `composer setup`-equivalente mínimo.
- [x] **Step 2:** No job `tests`, trocar o passo "Run CI Checks" por `composer ci:tests`.
- [x] **Step 3:** Validar a sintaxe: `docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:latest` (esperado: sem erros). Se o Docker faltar, `gh workflow` não valida localmente; seguir para o push.
- [x] **Step 4:** Commit `feat: job static em paralelo com os testes no CI`; `git push -u origin ci/paralelo` (push de branch, sem PR) e ver em `gh run list --branch ci/paralelo` os dois jobs verdes e o tempo total menor que o do run anterior.

### Task 3: Hook pre-push versionado

**Files:**
- Create: `.githooks/pre-push`
- Modify: `composer.json` (script `hooks:install`)

**Interfaces:**
- Consumes: `composer lint:check`, `composer types:check`.

- [x] **Step 1:** Criar `.githooks/pre-push` (shell POSIX, executável via `git update-index --chmod=+x`): sai com mensagem clara e código 1 se `vendor/autoload.php` não existir; roda `composer lint:check && composer types:check`; em falha, imprime "rode composer lint para corrigir o estilo" e sai 1. Não roda a suíte (precisa de Postgres).
- [x] **Step 2:** Script `hooks:install` = `["@php -r \"passthru('git config core.hooksPath .githooks');\""]`; rodar `composer hooks:install` e conferir `git config core.hooksPath`.
- [x] **Step 3 (RED/GREEN):** Introduzir um erro de estilo num arquivo, tentar `git push --dry-run`... o hook roda só em push real: verificar com `sh .githooks/pre-push` direto (esperado: sai 1 com o erro); reverter o erro e repetir (esperado: sai 0).
- [x] **Step 4:** Commit `feat: hook pre-push com Pint e Larastan`.

### Task 4: Proteção da `main` (depois do merge)

**Files:** nenhum; configuração via `gh api`.

- [x] **Step 1:** Só depois de o dono mandar mergear e `static` ter rodado na `main`: `gh api -X PUT repos/JotaSXBR/dimetal-atendimento/branches/main/protection` com `required_status_checks {strict:true, contexts:["tests","static"]}`, `enforce_admins:true`, `required_pull_request_reviews {required_approving_review_count:0}`, `restrictions:null`, `allow_force_pushes:false`. Guardar antes a proteção atual (`gh api .../protection > scratchpad`) para reverter.
- [x] **Step 2:** Conferir com `gh api .../protection --jq '{c:.required_status_checks.contexts, pr:.required_pull_request_reviews}'` (esperado: dois checks e `pr` não nulo).
- [x] **Step 3:** Atualizar `docs/deploy.md` ou o README do CI com uma linha sobre `composer hooks:install` e os dois checks exigidos; commit `docs: hook pre-push e checks exigidos`.
