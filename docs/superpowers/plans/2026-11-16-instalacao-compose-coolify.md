# Instalação por compose no Coolify: plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: superpowers:subagent-driven-development para executar tarefa por tarefa. Passos com checkbox (`- [ ]`).

**Objetivo:** uma imagem pública `ghcr.io/fluxie-tech/fluxie-inbox`, um `docker-compose.yml` na raiz que sobe tudo no Coolify sem variável
manual além do domínio, e workflows que publicam a imagem e atualizam staging (merge na `main`) e production (Release).

**Arquitetura:** a imagem é o estágio `runtime` do `docker/Dockerfile`. O compose escolhe o comando de cada serviço. O nginx do app
repassa `/app` e `/apps` ao Reverb, e um script de entrada cria os buckets. Os segredos são variáveis mágicas do Coolify
(`SERVICE_*`). Um teste de fumaça (`docker/smoke/compose-smoke.sh`) sobe o compose com valores fixos, no lugar das variáveis
mágicas, e é a prova local e do CI.

**Stack:** Docker (serversideup php-fpm-nginx), Docker Compose, GitHub Actions, API do Coolify v4.

**Spec:** `docs/superpowers/specs/2026-11-16-instalacao-compose-coolify-design.md` (vale a seção "Decisões minhas depois da revisão do design").

## Restrições globais

- Imagem: `ghcr.io/fluxie-tech/fluxie-inbox`. Tags `edge` e `sha-<sha completo>` a cada push verde na `main`; `X.Y.Z` e `X.Y` a cada Release `vX.Y.Z`.
- No compose, a imagem do app e dos irmãos é `ghcr.io/fluxie-tech/fluxie-inbox:${FLUXIE_VERSION:-0.1.0}`.
- Imagens de terceiros com versão fixa, nunca `latest` nem tag móvel: `postgres:18-alpine`, `redis:8-alpine`, WAHA `devlikeapro/waha:gows-<versão>` (a
  mais nova estável ≥ 2026.10.1) e RustFS `rustfs/rustfs:<versão>` (a que o beta usa ou mais nova estável).
- Só o `app` usa `AUTORUN_ENABLED=true`; `worker`, `scheduler` e `reverb` usam `false`. O worker continua com **um** processo em `outbound` e em `google`, sem réplicas.
- Segredos só por variável mágica: `APP_KEY=base64:${SERVICE_REALBASE64_32_APPKEY}`, `DB_PASSWORD=${SERVICE_PASSWORD_POSTGRES}`,
  `REDIS_PASSWORD=${SERVICE_PASSWORD_REDIS}`, `AWS_ACCESS_KEY_ID=${SERVICE_USER_RUSTFS}`, `AWS_SECRET_ACCESS_KEY=${SERVICE_PASSWORD_RUSTFS}`,
  `WAHA_API_KEY=${SERVICE_PASSWORD_WAHA}` (o mesmo valor, em texto puro, no WAHA e no app), e Reverb com `SERVICE_HEX_*`/`SERVICE_PASSWORD_*`.
- Endereços internos: `DB_HOST=postgres`, `REDIS_HOST=redis`, `WAHA_URL=http://waha:3000`, `WAHA_WEBHOOK_URL=http://app:8080/webhooks/waha`,
  `AWS_ENDPOINT=http://rustfs:9000`, `AWS_USE_PATH_STYLE_ENDPOINT=true`, `REVERB_HOST=reverb`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`.
- Fixos: `APP_NAME="Fluxie Inbox"`, `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=stderr`, `LOG_LEVEL=${LOG_LEVEL:-warning}`,
  `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `REDIS_CLIENT=phpredis`, `BROADCAST_CONNECTION=reverb`,
  `MEDIA_DISK=s3`, `AWS_BUCKET=media`, `BACKUP_BUCKET=backups`, `AWS_DEFAULT_REGION=us-east-1`, `APP_MAINTENANCE_DRIVER=cache`,
  `APP_MAINTENANCE_STORE=redis`, `APP_TIMEZONE=UTC`, `APP_DISPLAY_TIMEZONE=America/Sao_Paulo`, `APP_LOCALE=pt_BR`, `CSP_MODE=enforce`,
  `LIVEWIRE_CSP_SAFE=true`, `DB_CONNECTION=pgsql`, `DB_DATABASE=fluxie`, `DB_USERNAME=fluxie`.
- Do domínio: `APP_URL=${SERVICE_URL_APP}` e `GOOGLE_REDIRECT_URI=${SERVICE_URL_APP}/configuracoes/google/callback`. `GOOGLE_CLIENT_ID` e
  `GOOGLE_CLIENT_SECRET` ficam com o padrão vazio (`${GOOGLE_CLIENT_ID:-}`): o projeto 3 os leva para a tela. `REVERB_PUBLIC_URL` não vai no
  compose, porque o `echo.js` e a CSP caem no próprio domínio.
- WAHA: os valores da coluna `main` de `docs/superpowers/specs/waha-config.md` §2 (sem `WAHA_WORKER_ID`), com `WAHA_DASHBOARD_ENABLED=false`
  e `WHATSAPP_SWAGGER_ENABLED=false`. O WAHA fica sem domínio.
- Credenciais do Coolify só em `.env.coolify.local` e nos segredos do Actions; nunca imprimir o token.
- Nenhum recurso do Coolify é criado, parado ou apagado nas tarefas: isso é a "Troca", depois do merge.

## Foco da revisão

1. **Deploy antes de existir staging/production:** o merge acontece antes dos recursos novos existirem, então o workflow sem
   `COOLIFY_STAGING_UUID` ou `COOLIFY_PRODUCTION_UUID` publica a imagem e pula o deploy com um `::notice::`, sem falhar. Prova: Task 3, passo 4.
2. **Reverb ainda não subiu quando o nginx sobe:** o nginx não pode falhar no boot. Prova: Task 1, passo 4 (container sozinho, sem rede do compose).
3. **RustFS lento:** o app espera os buckets em vez de subir sem eles, e sai com erro se o RustFS não responder. Prova: Task 2 (fumaça).
4. **Variável `FLUXIE_VERSION` inexistente no recurso:** o workflow cria (POST) quando o PATCH responde 404. Prova: Task 3, passo 4 (função testada com respostas simuladas).
5. **Release com tag fora do padrão** (`v1.2`, `1.2.3`, `v1.2.3-rc1`): o workflow falha antes de publicar qualquer tag. Prova: Task 3, passo 4.

---

### Task 1: imagem `runtime` pronta para o compose

**Arquivos:**
- Modificar: `docker/Dockerfile` (estágio `runtime`)
- Criar: `docker/nginx/reverb-proxy.conf`, `docker/entrypoint/60-prepare-media.sh`
- Criar: `docker/smoke/image-check.sh`

**Interfaces:**
- Produz: `/usr/local/bin/worker.sh` no `runtime`; `location` `/app` e `/apps` no nginx com proxy para `http://reverb:8080`; script de entrada
  que roda `php artisan app:prepare-media` quando `AUTORUN_ENABLED=true`; build-arg `VERSION` gravado no label `org.opencontainers.image.version`.

Decisões:
- O host do Reverb fica fixo em `reverb` (é o nome do serviço no compose), em vez de vir de variável como o spec sugeria: o nginx da imagem não
  interpola ambiente sem template, e ninguém precisa trocar esse nome. Usar `resolver 127.0.0.11 valid=30s ipv6=off;` e o upstream numa variável
  (`set $reverb http://reverb:8080; proxy_pass $reverb;`), com `proxy_http_version 1.1`, `Upgrade`/`Connection "upgrade"`, `Host`,
  `X-Forwarded-For`/`-Proto` e `proxy_read_timeout 3600s`. O arquivo vai em `/etc/nginx/server-opts.d/` (o mesmo diretório que o `performance.conf`
  usa). Conferir que um `location` de regex dele não captura `/app/...` antes (testar no passo 4).
- O script de entrada vai no diretório de scripts de entrada da imagem base, numerado depois do script de automações do Laravel. Conferir o diretório
  e o nome com `docker run --rm --entrypoint ls <imagem> /etc/entrypoint.d`. O script tenta até 20 vezes, a cada 3 s; se não conseguir, sai com
  código ≠ 0 (o container cai e o Coolify reinicia). Com `AUTORUN_ENABLED` diferente de `true`, não faz nada.
- Os estágios `worker`, `reverb` e `scheduler` **ficam** no Dockerfile até a troca, porque o beta ainda faz build deles. O `worker` passa a usar
  o `worker.sh` já copiado no `runtime` (sem o `COPY` duplicado).

- [ ] **Passo 1: escrever `docker/smoke/image-check.sh <imagem>`** (bash, `set -euo pipefail`). Ele faz quatro verificações:
  1. `worker.sh` existe e é executável na imagem;
  2. `nginx -t` passa;
  3. o container sobe sozinho com `AUTORUN_ENABLED=false` e responde `200` em `GET /up` (com `APP_KEY` de teste e `DB_CONNECTION=sqlite`, se o `/up` precisar);
  4. `GET /app/x` responde `502` ou `504` (proxy tentou o Reverb), e não `404` do Laravel.

  O script imprime `OK` no fim.
- [ ] **Passo 2: rodar na imagem atual e ver falhar:**
  `docker build --target runtime -f docker/Dockerfile -t fluxie-inbox:check . && bash docker/smoke/image-check.sh fluxie-inbox:check`.
  Esperado: falha na verificação 1 ou 4.
- [ ] **Passo 3: implementar**, com os dois arquivos novos, o `COPY` do `worker.sh`, do `reverb-proxy.conf` e do script de entrada no `runtime`,
  o `ARG VERSION` e o label.
- [ ] **Passo 4: rodar de novo.** Mesmo comando. Esperado: `OK`. O container sobe sem o Reverb existir, o que cobre o item 2 do foco.
- [ ] **Passo 5: commit** `feat: imagem runtime com worker.sh, proxy do Reverb em /app e buckets na entrada`

### Task 2: `docker-compose.yml` e teste de fumaça

**Arquivos:**
- Criar: `docker-compose.yml` (raiz), `docker/smoke/compose-smoke.sh`

**Interfaces:**
- Consome: a imagem da Task 1.
- Produz: `bash docker/smoke/compose-smoke.sh <imagem-local>`. Ele marca a imagem como `ghcr.io/fluxie-tech/fluxie-inbox:smoke`, sobe o compose com
  `--env-file` de um env temporário (gerado pelo script) e `FLUXIE_VERSION=smoke` e `--pull never` para a imagem do app, e derruba tudo com `down -v` no fim, mesmo em
  falha (`trap`). A Task 3 chama esse script.

O compose segue a tabela do spec (§2) e as restrições globais. Mais:
- Metadados do preset em comentário no topo: `# documentation:`, `# slogan:`, `# category:`, `# tags:`, `# logo:`, `# port: 8080`.
- Variáveis repetidas entre `app`, `worker`, `scheduler` e `reverb` num bloco âncora (`x-app-env: &app-env`), se o Coolify aceitar. Conferir no
  recurso descartável da troca; se não aceitar, repetir.
- `app` com `SERVICE_URL_APP_8080`. `app`, `worker`, `scheduler` e `reverb` dependem de postgres e redis saudáveis; o `app` também do rustfs saudável.
- O script gera um env temporário (`mktemp`, apagado no `trap`) com valores aleatórios de cada variável `SERVICE_*` usada, com `SERVICE_URL_APP=http://localhost:8080`, e nada mais.

- [ ] **Passo 1: escrever o `compose-smoke.sh`**, que falha na primeira verificação que não passar:
  1. `docker compose -f docker-compose.yml --env-file <env temporário> config -q`;
  2. `up -d app worker scheduler reverb` (sem o WAHA, que é pesado e não é dependência) e espera até 180 s pelos quatro `healthy`;
  3. dentro do `app`, `GET /up` → `200` e `GET /login` → `200`;
  4. dentro do `app`, o handshake de websocket em `http://localhost:8080/app/<REVERB_APP_KEY do env temporário>?protocol=7` → `101`;
  5. `php artisan app:prepare-media` no `app` imprime `já existe` para os dois buckets, prova de que o script de entrada os criou;
  6. `php artisan migrate:status` no `worker` sem pendentes, e nenhum `Running migrations` nos logs do `worker`, `scheduler` e `reverb`;
  7. `docker compose config` contém `image: devlikeapro/waha:` e `image: rustfs/rustfs:` sem `latest`.

  O script imprime `OK` no fim.
- [ ] **Passo 2: rodar sem o compose e ver falhar:** `bash docker/smoke/compose-smoke.sh fluxie-inbox:check`. Esperado: falha na verificação 1.
- [ ] **Passo 3: escrever o `docker-compose.yml` e o env temporário do script.**
- [ ] **Passo 4: rodar e ver passar.** Mesmo comando. Esperado: `OK`.
- [ ] **Passo 5: conferir a lentidão do RustFS** (item 3 do foco): no script, depois do passo 5, rodar `docker compose stop rustfs` e
  `docker compose restart app`, e ver o `app` sair de `healthy` e registrar nos logs a falha do script de entrada. Depois, `start rustfs` e ver o
  `app` voltar a `healthy`. Isso entra no script como verificação 8.
- [ ] **Passo 6: commit** `feat: docker-compose.yml para o Coolify e teste de fumaça`

### Task 3: workflows de imagem, staging e production

**Arquivos:**
- Criar: `.github/workflows/release-image.yml`, `.github/scripts/coolify.sh`, `tests/scripts/coolify-test.sh`
- Criar: `.github/workflows/compose-smoke.yml`
- Modificar: `.github/workflows/security-scan.yml` (alvo do ZAP para `https://staging.inbox.fluxie.com.br` e nome do passo)

**Interfaces:**
- Consome: `docker/smoke/compose-smoke.sh <imagem>` (Task 2).
- Produz: funções bash em `.github/scripts/coolify.sh`, que leem `COOLIFY_URL` e `COOLIFY_API_TOKEN`:
  - `coolify_set_env <applications|services> <uuid> <key> <value>`: faz `PATCH /api/v1/<tipo>/<uuid>/envs` e, se a resposta for 404, faz `POST`
    no mesmo caminho;
  - `coolify_deploy_wait <uuid>`: é a função `deploy` + `wait_finished` de hoje no `deploy-beta.yml`, com os mesmos códigos e avisos;
  - `coolify_restart_service <uuid>`: faz `POST /api/v1/services/<uuid>/restart?latest=true` e depois consulta o Service até ficar saudável ou
    passar 10 min. Os campos de status saem do `openapi.json` do Coolify.
  - `release_tags <tag>`: imprime `X.Y.Z X.Y` para `vX.Y.Z` e sai com 1 em qualquer outro formato.

Comportamento:
- **`compose-smoke.yml`**: roda em PR e push na `main` que mudem `docker/**`, `docker-compose.yml` ou o próprio workflow. Constrói o `runtime`
  (no PR, a base vem do GHCR) e roda o `compose-smoke.sh`.
- **`release-image.yml`**, em duas situações:
  - **`workflow_run` do `tests` concluído com sucesso em push na `main`:** faz checkout do `head_sha`, build do `runtime` com
    `VERSION=sha-<sha>`, roda `compose-smoke.sh` e, depois, push de `edge` e `sha-<sha>`. Em seguida, se `COOLIFY_STAGING_UUID` existir:
    `coolify_set_env applications <uuid> FLUXIE_VERSION sha-<sha>` e `coolify_deploy_wait <uuid>`. Se não existir, um `::notice::` e fim.
  - **`release: published`:**
    1. `release_tags` valida a tag;
    2. `docker buildx imagetools create` gera `X.Y.Z` e `X.Y` a partir de `sha-<sha do commit da tag>`, e falha com mensagem clara se a imagem não
       existir;
    3. gera o compose da Release trocando `${FLUXIE_VERSION:-<qualquer>}` por `${FLUXIE_VERSION:-X.Y.Z}`, conferindo que houve ao menos uma troca;
    4. `gh release upload <tag> docker-compose.yml --clobber`;
    5. se `COOLIFY_PRODUCTION_UUID` existir: `coolify_set_env services <uuid> FLUXIE_VERSION X.Y.Z` e `coolify_restart_service <uuid>`.
       Se não existir, um `::notice::`.
  - Permissões mínimas por job: `packages: write` só no push de imagem; `contents: write` só no upload da Release. Ações fixadas por SHA, como nos
    outros workflows. `concurrency` por evento, sem cancelar em andamento.
- O `deploy-beta.yml` **não** muda aqui: ele sai na troca.

- [ ] **Passo 1: escrever `tests/scripts/coolify-test.sh`** (bash puro, sem rede). Ele carrega `.github/scripts/coolify.sh` com `curl` trocado por
  uma função falsa que devolve códigos programados, e afirma:
  - `release_tags v1.2.3` → `1.2.3 1.2`;
  - `release_tags` de `v1.2`, `1.2.3` e `v1.2.3-rc1` → saída 1 (item 5 do foco);
  - `coolify_set_env` com PATCH 404 → faz POST; com PATCH 200 → não faz POST (item 4 do foco);
  - `coolify_set_env` com PATCH 500 → sai ≠ 0.
- [ ] **Passo 2: rodar e ver falhar:** `bash tests/scripts/coolify-test.sh`. Esperado: falha porque `.github/scripts/coolify.sh` não existe.
- [ ] **Passo 3: implementar** `coolify.sh`, os dois workflows e a troca do alvo do ZAP. No `release-image.yml`, cada passo de deploy começa com
  `[ -n "$UUID" ] || { echo "::notice::..."; exit 0; }` (item 1 do foco).
- [ ] **Passo 4: verificar:**
  - `bash tests/scripts/coolify-test.sh` → `OK`;
  - `actionlint` nos três workflows (via `docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:<versão fixa>`) → sem erros;
  - `grep -n 'dimetal-beta' .github/workflows/security-scan.yml` → vazio.
- [ ] **Passo 5: incluir o teste no CI**: um passo `bash tests/scripts/coolify-test.sh` no `compose-smoke.yml`, com `.github/scripts/**` e
  `tests/scripts/**` nos `paths`.
- [ ] **Passo 6: commit** `feat: workflows de imagem versionada, deploy do staging e atualização da production`

### Task 4: `docs/deploy.md` como roteiro de instalação no Coolify

**Arquivos:**
- Modificar: `docs/deploy.md` (reescrita), `README.md` (o trecho de instalação aponta para o roteiro)

O roteiro tem quatro partes:
1. **Requisitos:** Coolify v4 e um domínio.
2. **Instalar:**
   1. criar um Service "Docker Compose Empty";
   2. colar o `docker-compose.yml` da Release mais recente (link da página de Releases, nunca o arquivo da `main`);
   3. informar o domínio no serviço `app`;
   4. fazer o deploy;
   5. criar o admin com `php artisan app:create-user` no terminal do `app`;
   6. conectar o WhatsApp pela tela Canais.
3. **Atualizar:** trocar `FLUXIE_VERSION` e usar "Pull Latest Images & Restart". Fazer backup antes.
4. **Para nós:** staging (Application lendo o compose da `main`), production (Service colado), os segredos `COOLIFY_STAGING_UUID` e
   `COOLIFY_PRODUCTION_UUID`, como soltar uma Release com o `gh`, e o aviso de não trocar `APP_NAME` em instalação existente.

O texto atual que descreve os 5 apps do beta sai, porque isso vira histórico do git.

- [ ] **Passo 1: reescrever.** Conferir cada comando citado contra o código: `app:create-user` existe (`codegraph_explore`); `FLUXIE_VERSION`
  existe no compose.
- [ ] **Passo 2: commit** `docs: roteiro de instalação no Coolify (deploy.md)`

### Depois das tarefas

- Portão 7 (comandos do `CLAUDE.md`) mais `bash docker/smoke/compose-smoke.sh` e `bash tests/scripts/coolify-test.sh`; revisão final do branch (`revisor-final`).
- PR e merge só quando o dono pedir.

### Troca (depois do merge, cada passo com o OK do dono onde marcado)

1. Conferir no Actions que `edge` e `sha-<sha>` foram publicadas e que o pacote é público (manifesto anônimo → 200).
2. Conferir o token: PATCH de variável e restart de Service. Se faltar permissão, pedir ao dono um token novo.
3. **Recurso descartável** (Service com o compose da `main`, sem domínio público, `FLUXIE_VERSION` apontando para a `sha-<sha>` publicada),
   **antes** de criar a Release. Conferir:
   - os nomes internos resolvem (`postgres`, `redis`, `waha`, `rustfs`, `reverb`);
   - `FLUXIE_VERSION` interpola na imagem;
   - cada variável mágica foi gerada e não está vazia (`APP_KEY`, `REVERB_APP_ID`/`KEY`/`SECRET`, senhas e usuário do RustFS);
   - a âncora `<<: *app-env` chega aos quatro serviços;
   - `SERVICE_URL_APP_8080` gera a URL e `${SERVICE_URL_APP}/...` monta a URL do Google;
   - `restart?latest=true` puxa a imagem nova: trocar `FLUXIE_VERSION` para outra `sha-<sha>`, chamar a API e conferir o digest do container;
   - os nomes `app` e `reverb` resolvem também numa Application de build pack Compose.

   Depois, apagar o recurso. Ele foi criado por mim, mas apagar continua pedindo **"sim"** do dono.
   **OK do dono:** criar a Release `v0.1.0` com o `gh`, com o changelog dos PRs.
4. Dono cria o DNS. Eu crio o projeto `fluxie-inbox` com os ambientes `staging` e `production`.
5. **Staging:** Application (build pack Docker Compose) da `main` com o domínio `https://staging.inbox.fluxie.com.br`. Gravar o
   `COOLIFY_STAGING_UUID` com `gh secret set`, deploy, admin pelo terminal, QR do número de teste, mensagem ida e volta, backup manual. No mesmo PR que remove o `deploy-beta` (passo 8), ou antes dele, trocar o alvo do ZAP em `security-scan.yml` para `https://staging.inbox.fluxie.com.br` (o DNS do staging precisa existir; em 16/11 o ZAP falhou no PR #137 por isso e o alvo voltou ao beta).
6. **Production:** Service colado com o domínio `https://inbox.fluxie.com.br`. Gravar o `COOLIFY_PRODUCTION_UUID`, deploy e admin.
7. **"sim" do dono e horário combinado:**
   1. parar o WAHA do `dimetal-beta`;
   2. ler o QR do número do beta na production;
   3. mensagem ida e volta e backup manual.
8. **"sim" do dono para cada recurso:**
   1. parar e apagar `dimetal-beta`, depois `dimetal-main`;
   2. remover o `deploy-beta.yml`, o branch `beta` (e o `beta` do `tests.yml`) e os estágios `worker`, `reverb` e `scheduler` do Dockerfile, num
      PR pequeno;
   3. conferir que não sobrou rede nem volume órfão.
9. Atualizar a memória e o `.env.coolify.local` com os UUIDs novos.
