# Deploy no Coolify — `main` e `beta`

Dois projetos no mesmo VPS, cada um completo e independente: seis recursos separados, cada um com
start/stop, logs, terminal e limites próprios.

## 1. Topologia

Projeto `dimetal-main` (produção) e projeto `dimetal-beta` (teste), cada um com:

| Recurso | Tipo no Coolify | Domínio | Volume |
|---|---|---|---|
| `app` | Application (Git, `/docker/Dockerfile`) | **sim**, HTTPS | `/var/www/html/storage/app` |
| `worker` | Application (mesmo Dockerfile, `queue:work`) | não | **o mesmo** `/var/www/html/storage/app` do `app` |
| `scheduler` | Application (mesmo Dockerfile, `schedule:work`) | não | **o mesmo** `/var/www/html/storage/app` do `app` |
| `pg` | Database PostgreSQL 18 | não | gerenciado pelo Coolify |
| `redis` | Database Redis 8 | não | gerenciado pelo Coolify |
| `waha` | Application (imagem `devlikeapro/waha:gows-2026.10.1`) | não | `/app/.sessions`, `/app/.media` |

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

Tudo é configurado por recurso, na aba Environment. **Toda variável é só de runtime** (desmarque "Available at Buildtime"
em cada uma, inclusive as novas): o `docker/Dockerfile` não declara nenhum `ARG`, então o build não precisa delas, e marcadas
como buildtime elas viram `--build-arg` no log do deploy (segredos inclusive) e disparam o aviso de `APP_ENV`. O que muda por projeto:

```
APP_KEY=base64:...
APP_URL=https://dimetal-beta.fluxie.com.br
DB_HOST=<host da URL interna do pg>
DB_PASSWORD=<senha do pg>
REDIS_HOST=<host da URL interna do redis>
REDIS_PASSWORD=<senha do redis>
AUTORUN_ENABLED=true        # só no app; worker e scheduler usam false
WAHA_URL=http://waha-beta:3000   # app e worker (o worker envia mensagens)
WAHA_API_KEY=<mesma chave do container waha>
```

O restante é fixo e igual nos três serviços PHP: `APP_ENV=production`, `APP_DEBUG=false`,
`APP_TIMEZONE=UTC` (as telas exibem em `APP_DISPLAY_TIMEZONE`, padrão `America/Sao_Paulo`), `LOG_CHANNEL=stderr`, `DB_CONNECTION=pgsql`, `DB_PORT=5432`,
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
WAHA_EVENTS_DOWNLOAD_MEDIA=true
```

`message.any` é obrigatório: `message` não entrega o que foi enviado por outro aparelho da conta, e
sem ele não existe registro `fora_do_painel`. O webhook usa o alias interno, não o domínio público.

O webhook, os eventos e o HMAC não são mais variáveis do WAHA: o app os registra na sessão ao clicar
**Conectar** (segredo derivado da `APP_KEY`). Para o webhook ir pela rede interna, defina
`WAHA_WEBHOOK_URL=http://app-beta:8080/webhooks/waha` no app e no worker (a imagem escuta na 8080; sem a porta o WAHA leva ECONNREFUSED); sem a variável usa `APP_URL`. Depois de mudá-la, reaplique a sessão (Conectar no cartão do canal) para o WAHA gravar o novo endereço.

Healthchecks do Coolify (a troca de container só acontece quando o novo está saudável):

| Serviço | Tipo | Verificação |
|---|---|---|
| `app` | http | `GET /up` na porta 8080, início de 15 s, 5 tentativas |
| `waha` | http | `GET /ping` na porta 3000, host `localhost`, retorno 200 (não exige API key) |
| `worker` | cmd | `healthcheck-queue` (script da imagem; confirma o `queue:work`) |
| `scheduler` | cmd | `healthcheck-schedule` (script da imagem; confirma o `schedule:work`) |

A imagem do WAHA não tem `curl` nem `wget`, então healthcheck `cmd` com `curl` reprova; o tipo `http`
funciona porque a requisição parte do Coolify.

## 3. Domínio e HTTPS

Só o `app` recebe domínio. O Coolify cuida do certificado; `bootstrap/app.php` confia no proxy
(`trustProxies(at: '*')`), sem o que o Laravel geraria URLs `http://` e cookies sem `Secure`.

## 4. Persistência da sessão

O volume `/app/.sessions` do `waha` é o que faz o QR sobreviver a redeploy. `/app/.media` é mídia
temporária do WAHA — o app guarda a própria cópia, então não é backup.

`app` guarda em `/var/www/html/storage/app` a cópia local dos anexos enviados pelo painel (pasta
`private/outbound`). O upload acontece no `app`, mas quem envia ao WAHA é o `worker` (fila `outbound`) e
quem apaga as cópias com mais de 90 dias é o `scheduler` (`app:purge-media`): **os três precisam montar o
mesmo volume nomeado em `/var/www/html/storage/app`**. Sem isso o anexo sobe, mas o worker não acha o
arquivo e a mensagem falha com "O arquivo deste anexo não está mais disponível no servidor".

Upload de anexo: cada arquivo (até 16 MB) sobe numa requisição própria. A imagem base já aceita 100 MB
(`NGINX_CLIENT_MAX_BODY_SIZE`, `PHP_UPLOAD_MAX_FILE_SIZE` e `PHP_POST_MAX_SIZE` valem 100M), então nada a
ajustar; se algum dia essas variáveis forem reduzidas, mantenha ao menos 20 MB. O worker usa o
`PHP_MEMORY_LIMIT=256M` da imagem: um anexo de 16 MB em base64 cabe com folga.

## 5. Fluxo de branches

- `main` → produção. Recebe merge apenas por PR com os checks `tests` e `static` verdes.
- `beta` → ambiente de teste.
- Antes de empurrar: `composer hooks:install` (uma vez por clone) liga o `.githooks/pre-push`, que roda Pint e Larastan em segundos; a suíte fica no CI.
- `beta` é sincronizada a partir de `main` (`git push origin main:beta`), não o contrário.

### Imagem base do PHP

O `docker/Dockerfile` parte de `ghcr.io/jotasxbr/dimetal-php-base:8.5`, com `intl`, `gd`, `bcmath` e as demais extensões já
compiladas (`docker/base/Dockerfile`). Compilá-las a cada deploy custava ~95 s, porque o Coolify descarta o cache de build.
O workflow `base-image` reconstrói e publica a imagem quando `docker/base/**` muda, toda segunda-feira (correções do
sistema) e sob demanda. **Uma vez só:** o pacote `dimetal-php-base` precisa estar público (GitHub → Packages → Package settings
→ Change visibility), senão o Coolify não consegue baixá-lo; em "Manage Actions access" o repositório deve ter permissão de escrita.
Os assets são gerados com Node 26 (`node:26-alpine`), o mesmo do CI. **Versões:** o PHP do CI vem do `FROM serversideup/php`
de `docker/base/Dockerfile` e o Node do `FROM node` de `docker/Dockerfile` (ação `.github/actions/versions`); o Dependabot atualiza esses
dois `FROM`, e o CI acompanha. Postgres e Redis do CI devem ser iguais aos do `compose.yaml` (o job `static` confere).

O deploy **não** é automático no push do Coolify: `is_auto_deploy_enabled=false` nos Applications. Quem
dispara é o workflow `.github/workflows/deploy-beta.yml` (seção 7). Com healthcheck ligado, a versão
antiga só cai depois que a nova fica saudável.

## 6. Primeiro deploy

1. Crie os Databases (`pg`, `redis`) e os quatro Applications. Como fazer pela API:
   [`docs/deploy-coolify-api.md`](deploy-coolify-api.md).
2. Preencha as variáveis da seção 2 em cada recurso.
3. Deploy nos Databases, depois `waha`, depois `app`. `worker` e `scheduler` por último.
4. No terminal do `app`: `php artisan db:seed --force` (cinco setores) e
   `php artisan app:create-user email@dimetal.com.br "Nome" --role=admin`.
5. **Canais** → decidir sobre "Importar histórico" (desmarcado) → **Conectar** → QR ou código de
   pareamento.
6. Confirmar **Conectado** e enviar o texto de teste.

Recursos do beta hoje:

| Recurso | UUID |
|---|---|
| `app-beta` | `ipbknnticmaubtzdonxjqgy6` |
| `worker-beta` | `jme7xgh4ugrrf3qbrxaclwlj` |
| `scheduler-beta` | `3thwz32zdtnmr2sme70jrpdf` |
| `reverb-beta` | `oncydt57liptigusmj64ac3o` |
| `waha-beta` | `gxpbtauw9ylqyd8nni3keznx` |
| `pg-beta` | `m3kcov2jn01utd97jjho2lox` |
| `redis-beta` | `vp3u0qyz2tsmyveqrb6ml2zi` |

### Filas (dentro do `worker-beta`)

O estágio `worker` roda `docker/worker.sh`, que inicia **quatro** processos de fila no mesmo container, um por tipo de
trabalho, para um lento não segurar os outros:

| Fila | Processo | O que carrega |
|---|---|---|
| `outbound` | `--tries=1`, **um único** | envio ao cliente (`SendTicketMessageJob`, `DrainOutboxJob`, `GoOfflineJob`) |
| `inbound` | `--tries=3` | ingestão do webhook do WAHA (`HandleWahaEvent`) e download de mídia (`ResolveMessageMediaJob`) |
| `realtime`, `default` | `--tries=3` | avisos de tela (Reverb) e, por rede de segurança, o que cair em `default` |
| `google` | `--tries=1`, **um único** | contatos para o Google (`SyncContactToGoogleJob`) |

O envio humanizado (visto, digitando, pausa, envio) leva segundos, e um só consumidor em `outbound` garante que as
mensagens de cada conversa saiam na ordem. O People API do Google pede escrita sequencial, por isso `google` também tem
um processo só, e uma lentidão ou limite (429) dele atrasa apenas a sincronização de contatos. Se um dos processos
morrer, o script encerra os outros e o container sai com erro, para o Coolify reiniciar todos juntos. O `retry_after` da
fila (`REDIS_QUEUE_RETRY_AFTER`, padrão 180) fica acima do timeout do job (120). **Não escale réplicas** do `worker-beta`:
com mais de um processo em `outbound` (ou `google`), a ordem se perde. Cada processo é um `php` residente, então o
container pede uns 300 MB. Ao trocar o formato de um job (como o `SendTicketMessageJob` na fatia de mídia), esvazie a
fila antiga antes do deploy: um job serializado pelo código velho falha no worker novo. Jobs que já estavam em `default`
na hora do deploy continuam sendo processados (o processo `realtime` também lê `default`). Um teste
(`QueueRoutingTest`) garante que toda fila usada pelo código tem consumidor no `worker.sh`.

### Google Contatos (variáveis)

`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` e `GOOGLE_REDIRECT_URI` (`{APP_URL}/configuracoes/google/callback`, igual ao
cadastrado no console do Google) entram no `app-beta`. O `worker-beta` e o `scheduler-beta` precisam das mesmas três
variáveis (o worker renova o token e fala com o People API). A conta se conecta em *Configurações → Google Contatos*
(só admin). A varredura `app:sync-google-contacts` roda a cada 10 minutos no `scheduler`.

### Mídia no WAHA

O WAHA guarda os arquivos (entrada e saída) no volume `/app/.media`; o Laravel guarda só a referência e serve o
arquivo por `GET /midia/{mensagem}` (autorizado pelo ticket). Retenção de 90 dias:
`WHATSAPP_FILES_FOLDER=/app/.media`, `WHATSAPP_FILES_LIFETIME=7776000` (a limpeza usa temporizador interno do WAHA;
o teto antigo de ~24 dias foi corrigido na 2026.4.1). O volume não tem backup. Conferir depois de reiniciar o WAHA
que arquivos antigos ainda expiram; se não, plano B: `find /app/.media -mtime +90 -delete` por cron no host.

### Tarefas agendadas

O `scheduler-beta` roda `schedule:work`. Hoje há uma tarefa: `app:close-inactive-tickets`, a cada 10 minutos
(encerra ticket parado há mais de 24 h cuja última mensagem é nossa). Nova tarefa em `routes/console.php` entra
sozinha no próximo deploy do scheduler; a opção "pode transferir" de um usuário se muda por
`php artisan app:user-can-transfer {email} {on|off}` (terminal do `app-beta` no Coolify).

### Reverb (tempo real da Inbox)

Origens aceitas no websocket: `config/reverb.php` lê `REVERB_ALLOWED_ORIGINS` (hosts ou URLs separados por
vírgula, aceita curinga) e, sem ela, usa o host do `APP_URL`. O Reverb compara só o host da origem. Não use
`*` em produção.

`reverb-beta` é um Application do mesmo Dockerfile, estágio `reverb` (`reverb:start` na porta 8080),
alias `reverb-beta`, healthcheck `http` em `/up`, sem migração (`AUTORUN_ENABLED=false`).

- **Navegador → Reverb**: não há DNS curinga, então o websocket sai pelo mesmo domínio do app, no
  caminho `/app` (`domains: https://dimetal-beta.fluxie.com.br/app` no `reverb-beta`). O front lê o
  endereço de `window.location`; `REVERB_PUBLIC_URL` só é preenchida em desenvolvimento
  (`http://localhost:8080`).
- O Coolify liga `is_stripprefix_enabled` por padrão e remove `/app` antes de repassar: o Reverb recebia
  `/<chave>` e respondia 404. No `reverb-beta` o campo fica **desligado** (`PATCH` com
  `{"is_stripprefix_enabled":false}` e novo deploy).
- **App/worker → Reverb**: rede interna, `REVERB_HOST=reverb-beta`, `REVERB_PORT=8080`,
  `REVERB_SCHEME=http`.
- Variáveis comuns a `app-beta`, `worker-beta` e `reverb-beta`: `BROADCAST_CONNECTION=reverb`,
  `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` (os mesmos valores nos três). O `reverb-beta`
  também leva as variáveis base do worker (`APP_KEY`, banco, Redis), pois sobe o framework.

## 7. Atualização e rollback

Merge em `main` → workflow `tests` → se passar, `deploy-beta` sincroniza a branch `beta` e faz o deploy **em
ordem**, esperando cada etapa terminar com sucesso (status `finished` no Coolify, que só troca o container
depois do healthcheck): primeiro `app-beta`, que roda o `migrate --force` no start; depois `worker-beta`,
`scheduler-beta` e `reverb-beta` (o `waha-beta` fica de fora: tag fixa e sessão única do WhatsApp). Se uma
etapa falhar, o workflow para e não troca o resto. Para esperar, o token em `COOLIFY_API_TOKEN` precisa das permissões `deploy` **e `read`**
(o Coolify responde 403 ao consultar o status com token só de deploy). Sem `read`, o workflow emite um
aviso e segue sem esperar: os deploys sobem em fila, sem garantia de ordem. O Coolify limita builds simultâneas (padrão 2), por isso
o workflow não manda tudo de uma vez. Segredos do repositório:
`COOLIFY_URL` e `COOLIFY_API_TOKEN`. Branches de PR são apagadas ao mergear. O volume preserva a
sessão, então o WhatsApp volta sozinho (`WAHA_WORKER_RESTART_SESSIONS=true`).

**Regra das migrações (expand/contract).** Entre o app novo subir e o worker, o scheduler e o reverb trocarem,
há código das duas versões rodando contra o mesmo banco. Toda migração precisa funcionar com o código da
versão anterior: adicionar coluna nullable ou tabela nova é seguro; remover ou renomear coluna, mudar tipo
ou reescrever dados que o código velho ainda grava vai em dois deploys (primeiro o código novo que não usa
mais o campo, depois a migração que o remove).

Rollback: reimplante o commit anterior. Migrações destrutivas exigem migração de volta escrita à mão
— evite drop de coluna no mesmo deploy que remove o código que a usa.

## 8. Diagnóstico

- **Status estranho na tela Canais** → logs do `waha`; ele loga o engine GOWS e a sessão.
- **Webhook não chega** → o app responde `401` quando o HMAC não confere e nada é gravado.
- **Sessão perdida a cada deploy** → volume `/app/.sessions` não está montado.
- **Contato não recebe notificação** → presença ficou `online`; `WAHA_PRESENCE_AUTO_ONLINE=False`
  evita isso.
- **Fila parada** → `worker` precisa estar rodando com o mesmo `QUEUE_CONNECTION` do `app`.
- **`worker`/`scheduler` rodando o servidor web em vez do comando** → o Coolify ignora `start_command` em build
  por Dockerfile; confira `dockerfile_target_build` (`worker`/`scheduler`) e os estágios do Dockerfile.
- **`worker`/`scheduler` unhealthy** → o healthcheck usa `healthcheck-queue`/`healthcheck-schedule`
  (precisam de `pgrep` na imagem). Se reprovar, desligue o healthcheck do recurso e investigue.
- **`waha` unhealthy** → use healthcheck tipo `http` em `/ping`; o tipo `cmd` com `curl` reprova porque a
  imagem não tem `curl` nem `wget`.
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
