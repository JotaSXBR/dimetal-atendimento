# Deploy no Coolify — `main` e `beta`

Dois projetos no mesmo VPS, cada um completo e independente: seis recursos separados, cada um com
start/stop, logs, terminal e limites próprios.

## 1. Topologia

Projeto `dimetal-main` (produção) e projeto `dimetal-beta` (teste), cada um com:

| Recurso | Tipo no Coolify | Domínio | Volume |
|---|---|---|---|
| `app` | Application (Git, `/docker/Dockerfile`) | **sim**, HTTPS | `/var/www/html/storage/app` |
| `worker` | Application (mesmo Dockerfile, `queue:work`) | não | — |
| `scheduler` | Application (mesmo Dockerfile, `schedule:work`) | não | — |
| `pg` | Database PostgreSQL 18 | não | gerenciado pelo Coolify |
| `redis` | Database Redis 8 | não | gerenciado pelo Coolify |
| `waha` | Application (imagem `devlikeapro/waha:gows-2026.9.2`) | não | `/app/.sessions`, `/app/.media` |

Não há `compose.coolify.yaml`. Um Service ou uma aplicação docker-compose apareceria como **um**
recurso com vários containers pendurados — exatamente o que não queremos.

Recursos separados só se falam pela rede Docker `coolify`. Por isso:

- os quatro Applications têm **Connect to predefined network** ligado;
- cada um tem **Network Aliases** único por projeto: `app-beta`, `worker-beta`, `scheduler-beta`,
  `waha-beta` (no main, `-main`). Main e beta dividem a rede, então nome sem sufixo resolveria para
  o projeto errado;
- `pg` e `redis` não aceitam alias na API: o host é o nome interno que o Coolify gera e mostra na
  URL interna (estável entre deploys).

## 2. Variáveis

Tudo é configurado por recurso, na aba Environment. O que muda por projeto:

```
APP_KEY=base64:...
APP_URL=https://dimetal-beta.fluxie.com.br
DB_HOST=<host da URL interna do pg>
DB_PASSWORD=<senha do pg>
REDIS_HOST=<host da URL interna do redis>
REDIS_PASSWORD=<senha do redis>
AUTORUN_ENABLED=true        # só no app; worker e scheduler usam false
```

O restante é fixo e igual nos três serviços PHP: `APP_ENV=production`, `APP_DEBUG=false`,
`APP_TIMEZONE=America/Sao_Paulo`, `LOG_CHANNEL=stderr`, `DB_CONNECTION=pgsql`, `DB_PORT=5432`,
`DB_DATABASE=dimetal`, `DB_USERNAME=dimetal`, `REDIS_CLIENT=phpredis`, `REDIS_PORT=6379`,
`SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`.

`AUTORUN_ENABLED=true` roda migrate/caches/storage link no start do `app`. Ligar isso em worker ou
scheduler faz três migrações concorrentes.

No `waha`, a matriz completa está em
[`docs/superpowers/specs/waha-config.md`](superpowers/specs/waha-config.md). O essencial:

```
WAHA_WORKER_ID=waha-beta
WHATSAPP_DEFAULT_ENGINE=GOWS
WAHA_API_KEY=<chave>
WAHA_PRESENCE_AUTO_ONLINE=False
WHATSAPP_HOOK_URL=http://app-beta/webhooks/waha
WHATSAPP_HOOK_EVENTS=session.status,message,message.any,message.ack,message.reaction,message.revoked,presence.update
WHATSAPP_HOOK_HMAC_KEY=<segredo>
WAHA_EVENTS_DOWNLOAD_MEDIA=true
```

`message.any` é obrigatório: `message` não entrega o que foi enviado por outro aparelho da conta, e
sem ele não existe registro `fora_do_painel`. O webhook usa o alias interno, não o domínio público.

`WAHA_API_KEY` e `WHATSAPP_HOOK_HMAC_KEY` são lidos pelo container do WAHA e precisam ser digitados
de novo, iguais, em **Configurações → WhatsApp** (a tela testa com `GET /api/sessions`).

## 3. Domínio e HTTPS

Só o `app` recebe domínio. O Coolify cuida do certificado; `bootstrap/app.php` confia no proxy
(`trustProxies(at: '*')`), sem o que o Laravel geraria URLs `http://` e cookies sem `Secure`.

## 4. Persistência da sessão

O volume `/app/.sessions` do `waha` é o que faz o QR sobreviver a redeploy. `/app/.media` é mídia
temporária do WAHA — o app guarda a própria cópia, então não é backup.

`app` guarda mídia do painel em `/var/www/html/storage/app`. Worker e scheduler **não** montam esse
volume: nada em Fase 0 escreve ali. Quando a mídia entrar, decidir entre montar o mesmo volume nos
três ou usar storage externo.

## 5. Fluxo de branches

- `main` → produção. Recebe merge apenas por PR com o check `tests` verde.
- `beta` → ambiente de teste.
- `beta` é sincronizada a partir de `main` (`git push origin main:beta`), não o contrário.

O deploy **não** é automático no push: `is_auto_deploy_enabled=false` nos quatro Applications. A
atualização será disparada por GitHub Actions com uma API key própria, e só depois de verificada —
a versão antiga não cai antes da nova subir.

## 6. Primeiro deploy

1. Crie os Databases (`pg`, `redis`) e os quatro Applications. Como fazer pela API:
   [`docs/deploy-coolify-api.md`](deploy-coolify-api.md).
2. Preencha as variáveis da seção 2 em cada recurso.
3. Deploy nos Databases, depois `waha`, depois `app`. `worker` e `scheduler` por último.
4. No terminal do `app`: `php artisan db:seed --force` (cinco setores) e
   `php artisan app:create-user email@dimetal.com.br "Nome" --role=admin`.
5. **Configurações → WhatsApp**: URL `http://waha-beta:3000`, API key e segredo HMAC; salve.
6. **Canais** → decidir sobre "Importar histórico" (desmarcado) → **Conectar** → QR ou código de
   pareamento.
7. Confirmar **Conectado** e enviar o texto de teste.

Recursos do beta hoje:

| Recurso | UUID |
|---|---|
| `app-beta` | `ipbknnticmaubtzdonxjqgy6` |
| `worker-beta` | `jme7xgh4ugrrf3qbrxaclwlj` |
| `scheduler-beta` | `3thwz32zdtnmr2sme70jrpdf` |
| `waha-beta` | `gxpbtauw9ylqyd8nni3keznx` |
| `pg-beta` | `m3kcov2jn01utd97jjho2lox` |
| `redis-beta` | `vp3u0qyz2tsmyveqrb6ml2zi` |

## 7. Atualização e rollback

Push em `main` → Coolify reconstrói → `migrate --force` roda no start do `app`. O volume preserva a
sessão, então o WhatsApp volta sozinho (`WAHA_WORKER_RESTART_SESSIONS=true`).

Rollback: reimplante o commit anterior. Migrações destrutivas exigem migração de volta escrita à mão
— evite drop de coluna no mesmo deploy que remove o código que a usa.

## 8. Diagnóstico

- **Status estranho na tela Canais** → logs do `waha`; ele loga o engine GOWS e a sessão.
- **Webhook não chega** → o app responde `401` quando o HMAC não confere e nada é gravado.
- **Sessão perdida a cada deploy** → volume `/app/.sessions` não está montado.
- **Contato não recebe notificação** → presença ficou `online`; `WAHA_PRESENCE_AUTO_ONLINE=False`
  evita isso.
- **Fila parada** → `worker` precisa estar rodando com o mesmo `QUEUE_CONNECTION` do `app`.
- **`worker`/`scheduler` sem status `healthy`** → os três Applications têm healthcheck do Coolify
  desligado; o da imagem (`healthcheck-queue`) não é usado.
- **`waha` unhealthy** → a imagem não tem `curl` nem `wget`; healthcheck HTTP do Coolify reprova.
  Deixe desligado.
- **404 do Traefik ou certificado inválido** → o `domains` do recurso não é o domínio real, ou o
  deploy não rodou depois de gravá-lo.

## 9. Checklist de prova no beta

1. Projeto `beta` no ar com o chip de teste.
2. Configurações → WhatsApp salva e testa.
3. Canais → Conectar → QR aparece → escanear.
4. Repetir a autenticação usando **código de pareamento**, com o QR visível durante o processo.
5. Status vira **Conectado**.
6. `channels.backfill_days` ficou `0` com a importação desmarcada.
7. Mensagem do celular para o número gera evento `message` em "Últimos eventos".
8. Texto enviado pelo formulário chega no celular; o contato vê **"visto por último"**, nunca
   "online".
9. **Prova do eco (bug #2299 do GOWS)**: envie pelo **celular** para um contato e confirme em qual
   conversa o evento `fromMe` cai. Se cair na conversa errada, fixar
   `devlikeapro/waha:gows-2026.7.1` e reavaliar quando a issue fechar.
10. Confirmar que `message.any` chega em "Últimos eventos" para o envio feito pelo celular.
11. Enviar pelo painel e confirmar que **não** aparece uma segunda mensagem marcada como enviada
    fora do painel.
12. Reiniciar o container `waha`: volta **Conectado** sem pedir QR.
13. Repetir no `main` com o número real.
