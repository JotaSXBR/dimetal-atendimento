# Deploy no Coolify — `main` e `beta`

Dois projetos no mesmo VPS, cada um completo e independente: aplicação, worker, scheduler, banco,
cache e WAHA. Um não enxerga o outro. Só a aplicação tem domínio público.

## 1. Topologia

Projeto `dimetal-main` (produção) e projeto `dimetal-beta` (teste), cada um com:

| Serviço | Imagem | Domínio | Volume |
|---|---|---|---|
| `app` | build do repositório | **sim**, HTTPS | — |
| `queue-worker` | mesma build, `php artisan queue:work` | não | — |
| `scheduler` | mesma build, `php artisan schedule:work` | não | — |
| `postgres` | `postgres:18-alpine` | não | `postgres-data` → `/var/lib/postgresql` |
| `redis` | `redis:8-alpine` | não | opcional |
| `waha` | `devlikeapro/waha:gows` | não | `waha-sessions` → `/app/.sessions`, `waha-media` → `/app/.media` |

O `app` chama o WAHA pelo DNS interno do Coolify: `http://<service-name>:3000`. O WAHA chama o app
pelo hostname interno, não pelo domínio público.

No PostgreSQL 18 o volume é montado em `/var/lib/postgresql` — montar em
`/var/lib/postgresql/data` faz o container recusar subir.

## 2. Variáveis da aplicação

Só o que é bootstrap e infraestrutura:

```
APP_KEY=base64:...
APP_ENV=production
APP_DEBUG=false
APP_URL=https://atendimento.dimetal.com.br
APP_TIMEZONE=America/Sao_Paulo

DB_CONNECTION=pgsql
DB_HOST=<service-name-do-postgres>
DB_PORT=5432
DB_DATABASE=dimetal
DB_USERNAME=dimetal
DB_PASSWORD=<senha>

REDIS_CLIENT=phpredis
REDIS_HOST=<service-name-do-redis>
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
```

Não existe `WAHA_*` no `.env` da aplicação: URL, API key, segredo do webhook e nome da sessão moram
na tabela `settings`, editáveis na tela **Configurações → WhatsApp**, com o segredo cifrado.

Opcional, apenas para semear um administrador inicial (`php artisan db:seed`): `ADMIN_NAME`,
`ADMIN_EMAIL`, `ADMIN_PASSWORD`. Depois do primeiro acesso, crie os demais usuários com
`php artisan app:create-user`.

## 3. Variáveis do WAHA

Use a matriz da seção 2 de [`docs/superpowers/specs/waha-config.md`](superpowers/specs/waha-config.md),
trocando `main`/`beta`, a API key, o segredo HMAC e o hostname do app:

```
WAHA_WORKER_ID=waha-main            # waha-beta no projeto beta
WHATSAPP_DEFAULT_ENGINE=GOWS
TZ=America/Sao_Paulo
WAHA_API_KEY=sha512:<hash>
WAHA_DASHBOARD_ENABLED=false
WHATSAPP_SWAGGER_ENABLED=false
WAHA_WORKER_RESTART_SESSIONS=true
WAHA_PRESENCE_AUTO_ONLINE=true
WAHA_PRESENCE_AUTO_ONLINE_DURATION_SECONDS=25
WHATSAPP_HOOK_URL=http://<app>/webhooks/waha
WHATSAPP_HOOK_HMAC_KEY=<segredo>
WHATSAPP_HOOK_EVENTS=session.status,message,message.ack,message.reaction,message.revoked,presence.update
WHATSAPP_HOOK_RETRIES_POLICY=exponential
WHATSAPP_HOOK_RETRIES_DELAY_SECONDS=3
WHATSAPP_HOOK_RETRIES_ATTEMPTS=8
WHATSAPP_FILES_FOLDER=/app/.media
WHATSAPP_FILES_LIFETIME=0
WAHA_EVENTS_DOWNLOAD_MEDIA=true
```

O mesmo valor de `WAHA_API_KEY` e de `WHATSAPP_HOOK_HMAC_KEY` é digitado na tela **Configurações →
WhatsApp**. A tela testa a URL e a chave com um `GET /api/sessions` antes de gravar.

Cada projeto usa **um número e uma sessão**, nunca compartilhados: reconectar o mesmo número em
outro cliente derruba o anterior. `main` usa o número real, `beta` o chip de teste.

## 4. Persistência da sessão

O volume `waha-sessions` em `/app/.sessions` é o que faz o QR sobreviver a redeploy. Sem ele, todo
deploy pede leitura de QR de novo. O `waha-media` em `/app/.media` guarda a mídia temporária do
WAHA; o app baixa e guarda a própria cópia, então esse volume não é backup.

## 5. Fluxo de branches

- `main` → produção. Recebe merge apenas por PR com o check `tests` verde (branch protegida, sem
  force push).
- `beta` → ambiente de teste. `git push origin beta` já reconstrói.
- `beta` é sincronizada a partir de `main` (`git checkout beta && git merge --ff-only main`), não o
  contrário.

## 6. Primeiro deploy

1. Suba os serviços e configure o domínio apenas no `app`.
2. No `app`: `php artisan migrate --force` e `php artisan db:seed --force` (cria os cinco setores).
3. Crie o administrador: `php artisan app:create-user email@dimetal.com.br "Nome" --role=admin`.
4. Entre em **Configurações → WhatsApp**, preencha URL interna do WAHA, API key e segredo HMAC, e
   salve — a tela confirma a conexão.
5. Abra **Canais**, decida sobre "Importar histórico" (desmarcado por padrão) e clique em
   **Conectar**.
6. Autentique por **QR** ou por **código de pareamento** (WhatsApp → Aparelhos conectados →
   Conectar aparelho → Conectar com número de telefone). O QR fica na tela como alternativa.
7. Confirme o status **Conectado** e envie o texto de teste.

## 7. Atualização e rollback

Push em `main` → Coolify reconstrói → `php artisan migrate --force` roda no deploy. O volume
preserva a sessão, então o WhatsApp volta sozinho quando o container sobe
(`WAHA_WORKER_RESTART_SESSIONS=true`).

Rollback: reimplante o commit anterior pelo Coolify. Migrações destrutivas exigem migração de volta
escrita à mão — evite drop de coluna no mesmo deploy que remove o código que a usa.

## 8. Diagnóstico

- **Status estranho na tela Canais** → `GET /api/sessions` pelo container do app, ou logs do
  container `waha`.
- **Webhook não chega** → o app responde `401` quando o HMAC não confere (segredo divergente entre
  WAHA e tela) e nada é gravado; `WAHA_DASHBOARD_ENABLED=false` não é a causa.
- **Sessão perdida a cada deploy** → volume `waha-sessions` não está montado.
- **Contato não recebe notificação no celular** → presença ficou `online`; o envio pelo painel
  termina mandando `offline`, e `WAHA_PRESENCE_AUTO_ONLINE_DURATION_SECONDS=25` limita o resto.
- **Fila parada** → o serviço `queue-worker` precisa estar rodando com o mesmo `QUEUE_CONNECTION` do
  app.

## 9. Checklist de prova no beta

1. Projeto `beta` no ar com o chip de teste.
2. Configurações → WhatsApp salva e testa.
3. Canais → Conectar → QR aparece → escanear.
4. Repetir a autenticação usando **código de pareamento**, com o QR visível durante o processo.
5. Status vira **Conectado**.
6. `channels.backfill_days` ficou `0` com a importação desmarcada.
7. Mensagem do celular para o número gera evento `message` em "Últimos eventos".
8. Texto enviado pelo formulário chega no celular; depois de ~25s o contato vê "visto por último".
9. Reiniciar o container WAHA: volta **Conectado** sem pedir QR.
10. Repetir no `main` com o número real.
