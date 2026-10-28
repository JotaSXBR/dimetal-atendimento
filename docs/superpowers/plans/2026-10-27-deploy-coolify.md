# Deploy Coolify — Implementation Plan

> **Superado em 28/10/2026.** As tarefas 1 e 4 continuam válidas (imagem e documentação). A
> topologia de compose único foi substituída por seis recursos separados no Coolify — ver
> `docs/superpowers/notes/2026-10-28-topologia-coolify.md` e `docs/deploy.md`. `compose.coolify.yaml`
> não existe mais.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publicar o sistema em `dimetal.fluxie.com.br` (main) e `dimetal-beta.fluxie.com.br` (beta) com app, worker, scheduler, Postgres, Redis e WAHA em um `docker-compose` versionado.

**Architecture:** Uma imagem de produção (`serversideup/php:8.5-fpm-nginx`, multi-stage com Node 24 e Composer) usada por três serviços: `app` (nginx + php-fpm), `worker` (`queue:work`) e `scheduler` (`schedule:work`). Ao lado deles, Postgres 18, Redis 8 e WAHA/GOWS. Apenas `app` recebe domínio; o resto vive na rede interna do projeto Coolify. Main e beta rodam o mesmo compose, mudando só env e domínio.

**Tech Stack:** Docker, Docker Compose, Coolify 4.5.0 API v1, serversideup/php 8.5, PostgreSQL 18, Redis 8, WAHA `gows-2026.9.2`.

**Spec:** `docs/deploy.md` (§2 topologia, §6 primeira subida, §9 checklist) e `docs/superpowers/specs/waha-config.md` (matriz de env do WAHA).

## Global Constraints

- PHP **8.5.12**; Node **24**; Composer **2**.
- Imagens fixadas: `serversideup/php:8.5.12-fpm-nginx-bookworm`, `node:24-alpine`, `composer:2`, `postgres:18-alpine`, `redis:8-alpine`, `devlikeapro/waha:gows-2026.9.2`.
- Timezone `America/Sao_Paulo` em todos os containers.
- **Só o serviço `app` recebe domínio público.** Postgres, Redis e WAHA nunca publicam porta no host.
- `WAHA_PRESENCE_AUTO_ONLINE=False`.
- Webhook do WAHA aponta para a rede interna: `http://app/webhooks/waha`.
- `.env.coolify.local` é segredo local e está no `.gitignore` — nunca commitar, nunca imprimir o token em log.

## Review Focus

- **Migração rodando três vezes** (`app`, `worker` e `scheduler` com `AUTORUN_ENABLED=true` disputando o schema) → automations habilitadas **só** no `app`.
- **Volume de storage sombreando o build**: montar `storage/app` e nunca `storage/` inteiro, senão o `vendor`/`bootstrap/cache` do build some.
- **Mídia escrita pelo worker e lida pelo app**: os três serviços precisam do **mesmo volume** em `/var/www/html/storage/app`.
- **`APP_KEY` ausente** em produção → toda sessão quebra com 500 sem log claro.
- **Domínio vazando entre ambientes**: o hostname nunca pode estar escrito no compose; vem de env por projeto.
- **WAHA com `WHATSAPP_HOOK_URL` público** → loop por proxy e HMAC falhando.

---

### Task 1: Imagem de produção

**Files:**
- Create: `docker/Dockerfile`
- Create: `.dockerignore`

**Interfaces:**
- Produces: imagem final com o app em `/var/www/html`, servida por nginx+php-fpm na porta `8080`; `php /var/www/html/artisan` disponível para os serviços `worker` e `scheduler`.

- [ ] **Step 1: Escrever `.dockerignore`**

Excluir `node_modules`, `vendor`, `public/build`, `.git`, `.env*` (exceto `.env.example`), `storage/logs/*`, `storage/framework/cache/*`, `*.md` fora de `vendor`.

- [ ] **Step 2: Escrever `docker/Dockerfile`**

Multi-stage, quatro estágios:
1. `node:24-alpine` — `npm ci` + `npm run build`, com `VITE_APP_NAME` definido.
2. `composer:2` — `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader`, com `--ignore-platform-reqs` desnecessário (a imagem base do estágio 4 não está em uso aqui); copiar `composer.json`/`composer.lock` antes do código para aproveitar cache.
3. `serversideup/php:8.5.12-fpm-nginx-bookworm` — instalar as extensões `PHP_EXTENSION_PDO_PGSQL=true`, `PHP_EXTENSION_INTL=true`, `PHP_EXTENSION_ZIP=true`, `PHP_EXTENSION_GD=true`, `PHP_EXTENSION_BCMATH=true`; copiar código, `vendor` e `public/build`; `chown -R www-data:www-data storage bootstrap/cache`; `php artisan config:cache` **não** roda no build (depende de env de runtime).

- [ ] **Step 3: Verificar que a imagem constrói**

Run: `docker build -f docker/Dockerfile -t dimetal-app:test .`
Expected: build conclui sem erro; `docker run --rm dimetal-app:test php -v` mostra `PHP 8.5.12`.

- [ ] **Step 4: Verificar extensões e artefatos**

Run: `docker run --rm dimetal-app:test php -m` → precisa listar `pdo_pgsql`, `intl`, `zip`, `gd`.
Run: `docker run --rm dimetal-app:test ls -la /var/www/html/public/build` → precisa conter `manifest.json`.

- [ ] **Step 5: Commit**

```bash
git add docker/Dockerfile .dockerignore
git commit -m "build: imagem de producao com php 8.5, node 24 e composer"
```

---

### Task 2: Compose do Coolify

**Files:**
- Create: `compose.coolify.yaml`

**Interfaces:**
- Consumes: imagem da Task 1 (build local `docker/Dockerfile`).
- Produces: seis serviços com os nomes `app`, `worker`, `scheduler`, `postgres`, `redis`, `waha`.

- [ ] **Step 1: Declarar os serviços**

| Serviço | Imagem / build | Comando | Healthcheck |
|---|---|---|---|
| `app` | build `docker/Dockerfile` | padrão da imagem | `curl -fsS http://localhost:8080/up` |
| `worker` | mesma imagem | `php /var/www/html/artisan queue:work --tries=3 --timeout=120` | `healthcheck-queue` |
| `scheduler` | mesma imagem | `php /var/www/html/artisan schedule:work` | `healthcheck-schedule` |
| `postgres` | `postgres:18-alpine` | — | `pg_isready -U $POSTGRES_USER -d $POSTGRES_DB` |
| `redis` | `redis:8-alpine` | `redis-server --appendonly yes` | `redis-cli ping` |
| `waha` | `devlikeapro/waha:gows-2026.9.2` | — | `wget -qO- http://localhost:3000/health` |

`worker` e `scheduler` usam `stop_signal: SIGTERM`. `app`, `worker` e `scheduler` usam a mesma `image:` nomeada (ex.: `dimetal-app:latest`) e apenas `app` declara `build:`.

- [ ] **Step 2: Declarar volumes e dependências**

- `pgdata` → `/var/lib/postgresql/data`
- `redisdata` → `/data`
- `waha-sessions` → `/app/.sessions`
- `waha-media` → `/app/.media`
- `app-storage` → `/var/www/html/storage/app` nos três serviços PHP
- `depends_on` com `condition: service_healthy` para postgres, redis e waha.

- [ ] **Step 3: Declarar o env obrigatório**

`app`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `APP_KEY`, `APP_TIMEZONE=America/Sao_Paulo`, `APP_LOCALE=pt_BR`, `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_PORT=5432`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `REDIS_CLIENT=phpredis`, `REDIS_HOST=redis`, `REDIS_PORT=6379`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `LOG_CHANNEL=stack`, `LOG_LEVEL=warning`, `AUTORUN_ENABLED=true`.
`worker` e `scheduler`: os mesmos, com `AUTORUN_ENABLED=false`.
`waha`: `WHATSAPP_DEFAULT_ENGINE=GOWS`, `WAHA_WORKER_RESTART_SESSIONS=true`, `WAHA_PRESENCE_AUTO_ONLINE=False`, `WAHA_DASHBOARD_ENABLED=false`, `WHATSAPP_SWAGGER_ENABLED=false`, `WAHA_API_KEY`, `WHATSAPP_HOOK_URL=http://app/webhooks/waha`, `WHATSAPP_HOOK_HMAC_KEY`, `WHATSAPP_HOOK_EVENTS` (lista de `waha-config.md`), `WHATSAPP_FILES_FOLDER=/app/.media`, `WHATSAPP_FILES_LIFETIME=0`, `WAHA_EVENTS_DOWNLOAD_MEDIA=true`, `TZ=America/Sao_Paulo`.

Nenhum hostname no arquivo: o domínio entra depois como `SERVICE_FQDN_APP`.

- [ ] **Step 4: Validar o compose**

Run: `docker compose -f compose.coolify.yaml config --quiet`
Expected: sem erro, sem warnings de variável ausente.

- [ ] **Step 5: Commit**

```bash
git add compose.coolify.yaml
git commit -m "build: compose de producao para coolify"
```

---

### Task 3: Criar os serviços no Coolify

**Files:**
- Create: `docs/deploy-coolify-api.md` (roteiro reproduzível, sem segredos)

**Interfaces:**
- Consumes: `POST /api/v1/services` com `docker_compose_raw` em base64, `server_uuid` `zto0f9qx9d6opovqflr26doe`, `project_uuid` `kh6g9vki7tpji1nfagbw0utu` (main) e `damvuwfyrphdc9we3lkvcqhm` (beta).

- [ ] **Step 1: Criar o serviço em `dimetal-main`**

Body: `{type: "docker-compose", name: "dimetal-main", project_uuid, environment_name: "production", server_uuid, docker_compose_raw: base64(compose), instant_deploy: false}`.

- [ ] **Step 2: Criar o serviço em `dimetal-beta`**

Mesmo body com `name: "dimetal-beta"`, `environment_name: "production"`, `project_uuid` do beta.

- [ ] **Step 3: Aplicar env de cada ambiente via `POST /api/v1/services/{uuid}/envs`**

Main: `SERVICE_FQDN_APP=dimetal.fluxie.com.br`, `APP_URL=https://dimetal.fluxie.com.br`, `APP_KEY` novo, `DB_PASSWORD` novo, `WAHA_API_KEY` novo (hash sha512), `WHATSAPP_HOOK_HMAC_KEY` novo.
Beta: os mesmos com `dimetal-beta.fluxie.com.br` e segredos diferentes.

- [ ] **Step 4: Deploy do beta e verificação**

Run: `curl -sI https://dimetal-beta.fluxie.com.br/up` → `HTTP/2 200`.
Se DNS ainda não aponta, validar por `docker compose ps` dentro da VPS (via SSH/Tailscale) e registrar o bloqueio.

- [ ] **Step 5: Commit do roteiro**

```bash
git add docs/deploy-coolify-api.md
git commit -m "docs: roteiro de criacao dos servicos no coolify"
```

---

### Task 4: Atualizar `docs/deploy.md`

**Files:**
- Modify: `docs/deploy.md` (§2 topologia, §6 primeira subida, §9 checklist)

- [ ] **Step 1: Trocar placeholders pelos valores reais**

Domínios `dimetal.fluxie.com.br` / `dimetal-beta.fluxie.com.br`, UUIDs dos dois projetos, servidor `localhost` (`zto0f9qx9d6opovqflr26doe`), nomes de arquivo `docker/Dockerfile` e `compose.coolify.yaml`.

- [ ] **Step 2: Cobrir o que o compose resolveu**

Migração só no `app`, volume compartilhado de `storage/app`, WAHA na rede interna, `SERVICE_FQDN_APP` como única fonte de domínio.

- [ ] **Step 3: Commit**

```bash
git add docs/deploy.md
git commit -m "docs: deploy aponta para os arquivos e dominios reais"
```

---

### Task 5: Prova no beta

- [ ] **Step 1: Subir o stack**

Run (na VPS): `docker compose -f compose.coolify.yaml ps` → seis serviços `running`/`healthy`.

- [ ] **Step 2: Rodar os itens 1–12 de `docs/deploy.md` §9**

Cobrir QR, código de pareamento, presença offline, prova do eco (#2299), `message.any` e anti-duplicata.

- [ ] **Step 3: Registrar o resultado**

Anotar no `.superpowers/sdd/` o que passou, o que falhou e a versão pinada do WAHA caso seja preciso voltar para `gows-2026.7.1`.
