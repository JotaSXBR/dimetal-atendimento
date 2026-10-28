# Criar os recursos no Coolify pela API

Reproduz o que existe hoje no painel. Use só quando precisar recriar um projeto do zero — o dia a
dia é deploy disparado pelo GitHub Actions.

Credenciais em `.env.coolify.local` (ignorado pelo git):

```
COOLIFY_URL=https://panel.fluxie.com.br
COOLIFY_API=<token>
```

Todas as chamadas usam `Authorization: Bearer $COOLIFY_API`. Base: `$COOLIFY_URL/api/v1`.
Servidor: `zto0f9qx9d6opovqflr26doe`. Projetos: `kh6g9vki7tpji1nfagbw0utu` (main),
`damvuwfyrphdc9we3lkvcqhm` (beta).

## 1. Databases

```bash
curl -s -X POST "$COOLIFY_URL/api/v1/databases/postgresql" \
  -H "Authorization: Bearer $COOLIFY_API" -H "Content-Type: application/json" \
  -d '{"server_uuid":"...","project_uuid":"...","environment_name":"production",
       "name":"pg-beta","image":"postgres:18-alpine","postgres_user":"dimetal",
       "postgres_db":"dimetal","postgres_password":"<senha>","is_public":false}'

curl -s -X POST "$COOLIFY_URL/api/v1/databases/redis" \
  -H "Authorization: Bearer $COOLIFY_API" -H "Content-Type: application/json" \
  -d '{"server_uuid":"...","project_uuid":"...","environment_name":"production",
       "name":"redis-beta","image":"redis:8-alpine","is_public":false}'
```

O Redis **gera uma senha sozinho**. Leia `internal_db_url` no `GET /databases/{uuid}`: o host é o
nome do container e a senha sai URL-encoded (`%2B` é `+`).

## 2. Applications

Três do Git (app, worker, scheduler) e uma de imagem (waha).

```bash
curl -s -X POST "$COOLIFY_URL/api/v1/applications/public" \
  -H "Authorization: Bearer $COOLIFY_API" -H "Content-Type: application/json" \
  -d '{
    "project_uuid":"...","server_uuid":"...","environment_name":"production",
    "name":"app-beta","git_repository":"https://github.com/JotaSXBR/dimetal-atendimento",
    "git_branch":"beta","build_pack":"dockerfile",
    "dockerfile_location":"/docker/Dockerfile","base_directory":"/",
    "ports_exposes":"8080","domains":"https://dimetal-beta.fluxie.com.br",
    "autogenerate_domain":false,"is_force_https_enabled":true,
    "is_auto_deploy_enabled":false,
    "connect_to_docker_network":true,"custom_network_aliases":"app-beta",
    "health_check_enabled":true,"health_check_path":"/up","health_check_port":8080,
    "health_check_scheme":"http","health_check_interval":15,"health_check_timeout":5,
    "health_check_retries":5,"health_check_start_period":60,
    "instant_deploy":false
  }'
```

`worker-beta` e `scheduler-beta` são iguais ao app, sem `domains`, e usam `dockerfile_target_build`
`worker` e `scheduler` (estágios do `docker/Dockerfile`; o `app` usa `runtime`). **Não** use
`start_command`: em build por Dockerfile o Coolify o ignora e o container sobe o servidor web. O
healthcheck deles é do tipo `cmd` (`healthcheck-queue` e `healthcheck-schedule`).

WAHA é `POST /applications/dockerimage` com `docker_registry_image_name: devlikeapro/waha`,
`docker_registry_image_tag: gows-2026.10.1`, `ports_exposes: 3000`,
`custom_network_aliases: waha-beta`, `connect_to_docker_network: true`, sem domínio e healthcheck
`health_check_type: http`, `health_check_path: /ping`, `health_check_port: 3000`,
`health_check_host: localhost`, `health_check_return_code: 200` — a imagem não tem `curl` nem `wget`,
então o tipo `cmd` com `curl` reprova. Worker e scheduler usam `health_check_type: cmd` com
`healthcheck-queue` e `healthcheck-schedule`.

`custom_network_aliases` não aparece no OpenAPI, mas o controller aceita no create e no `PATCH
/applications/{uuid}`.

## 3. Variáveis

`PATCH /applications/{uuid}/envs/bulk` é o caminho que grava valores:

```bash
curl -s -X PATCH "$COOLIFY_URL/api/v1/applications/<uuid>/envs/bulk" \
  -H "Authorization: Bearer $COOLIFY_API" -H "Content-Type: application/json" \
  -d '{"data":[
    {"key":"APP_KEY","value":"base64:..."},
    {"key":"APP_URL","value":"https://dimetal-beta.fluxie.com.br"},
    {"key":"DB_HOST","value":"<host interno do pg>"},
    {"key":"DB_PASSWORD","value":"<senha>"},
    {"key":"REDIS_HOST","value":"<host interno do redis>"},
    {"key":"REDIS_PASSWORD","value":"<senha>"},
    {"key":"AUTORUN_ENABLED","value":"true"}
  ]}'
```

`POST /envs` cria a chave com valor vazio e `PATCH /envs/{env_uuid}` responde 404 em algumas versões
— use o bulk. O Coolify cria um segundo conjunto `is_preview` com as mesmas chaves; é esperado.

## 4. Storages

`POST /applications/{uuid}/storages` exige `name` para volume persistente:

```bash
-d '{"type":"persistent","name":"app-storage","mount_path":"/var/www/html/storage/app"}'
-d '{"type":"persistent","name":"waha-sessions","mount_path":"/app/.sessions"}'
-d '{"type":"persistent","name":"waha-media","mount_path":"/app/.media"}'
```

## 5. Deploy e acompanhamento

```bash
curl -s -X POST "$COOLIFY_URL/api/v1/deploy" \
  -H "Authorization: Bearer $COOLIFY_API" -H "Content-Type: application/json" \
  -d '{"uuid":"<uuid>","force":false}'
# → {"deployments":[{"deployment_uuid":"..."}]}

curl -s -H "Authorization: Bearer $COOLIFY_API" "$COOLIFY_URL/api/v1/deployments/<uuid>" | jq .status
```

Ordem: Databases → `waha` → `app` → `worker` e `scheduler`. Os três Applications constroem do mesmo
Dockerfile; a partir do segundo o cache deixa o build curto.

## 6. Conferir

```bash
curl -sI https://dimetal-beta.fluxie.com.br/up        # 200, TLS válido
curl -sI http://dimetal-beta.fluxie.com.br/            # 302 → https
curl -s -H "Authorization: Bearer $COOLIFY_API" \
  "$COOLIFY_URL/api/v1/applications/<uuid>/logs?lines=40"
```

Para rodar um comando dentro do container sem terminal, crie uma scheduled task descartável e
execute:

```bash
POST /applications/{uuid}/scheduled-tasks   {"name":"probe","command":"php -r '...'","frequency":"* * * * *"}
POST /applications/{uuid}/scheduled-tasks/{task_uuid}/execute
GET  /applications/{uuid}/scheduled-tasks/{task_uuid}/executions   # message traz o stdout
DELETE /applications/{uuid}/scheduled-tasks/{task_uuid}
```
