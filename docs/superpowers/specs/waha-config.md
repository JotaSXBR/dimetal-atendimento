# WAHA/GOWS — configuração de referência

Versão de referência: **WAHA 2026.10.1**, engine **GOWS**, imagem `devlikeapro/waha:gows`.
Levantado em 2026-10-27 direto da documentação oficial (`waha.devlike.pro/docs`).

Este documento existe porque o comportamento de sessão, presença e histórico do WAHA **não é
intuitivo** e porque quase tudo que sobra de configuração nele é variável de ambiente — ou seja,
mora no Coolify e não no banco do app. O app não tem tela para servidor, chave nem HMAC.

## 1. O que é ambiente e o que é sistema

| Onde | O quê | Por quê |
|---|---|---|
| **Coolify** (env do container WAHA) | engine, API key, limites de histórico do GOWS, presença automática, pasta de sessão/mídia | o WAHA só lê env antes de subir |
| **Env do app/worker** | `WAHA_URL`, `WAHA_API_KEY`, `WAHA_WEBHOOK_URL` (opcional) | infraestrutura; o app não pede isso na tela |
| **Sessão do WAHA** (registrada pelo app ao clicar **Conectar**) | URL do webhook, eventos, retries e HMAC | `POST/PUT /api/sessions`; o HMAC é derivado da `APP_KEY` |
| **Banco do app** (tela Canais) | nome do canal, nome da sessão, dias de backfill; futuramente SLA, horário, empresa, SMTP | muda sem redeploy e fica auditável |

A **API key** é a mesma (texto puro) no env do WAHA e no env do app. O HMAC não é digitado em lugar
algum: `hash_hmac('sha256', 'waha-webhook', APP_KEY)`, enviado ao WAHA na configuração da sessão.

## 2. Matriz de ambiente — `main` e `beta`

Cada projeto do Coolify tem seu próprio container WAHA, com número e sessão próprios. Nunca
compartilhar sessão: o WhatsApp derruba o cliente anterior quando o mesmo número conecta de novo.

| Variável | `main` | `beta` |
|---|---|---|
| `WAHA_WORKER_ID` | `waha-main` | `waha-beta` |
| `WHATSAPP_DEFAULT_ENGINE` | `GOWS` | `GOWS` |
| `TZ` | `America/Sao_Paulo` | `America/Sao_Paulo` |
| `WAHA_API_KEY` | `sha512:<hash>` | `sha512:<outro hash>` |
| `WAHA_DASHBOARD_ENABLED` | `false` | `false` |
| `WHATSAPP_SWAGGER_ENABLED` | `false` | `false` |
| `WAHA_WORKER_RESTART_SESSIONS` | `true` | `true` |
| `WAHA_PRESENCE_AUTO_ONLINE` | `false` | `false` |
| `WAHA_PRESENCE_AUTO_ONLINE_DURATION_SECONDS` | `0` | `0` |
| `WHATSAPP_FILES_FOLDER` | `/app/.media` | `/app/.media` |
| `WHATSAPP_FILES_LIFETIME` | `0` | `0` |
| `WAHA_EVENTS_DOWNLOAD_MEDIA` | `true` | `true` |
| `GODEBUG` | `netdns=go` | `netdns=go` |
| `WAHA_GOWS_DEVICE_REQUIRE_FULL_SYNC` | `false` | `false` |
| `WAHA_GOWS_DEVICE_HISTORY_SYNC_FULL_SYNC_DAYS_LIMIT` | `10` | `10` |
| `WAHA_GOWS_DEVICE_HISTORY_SYNC_RECENT_SYNC_DAYS_LIMIT` | `7` | `7` |
| `WAHA_GOWS_DEVICE_HISTORY_SYNC_INITIAL_SYNC_MAX_MESSAGES_PER_CHAT` | `200` | `200` |
| `WAHA_GOWS_DEVICE_HISTORY_SYNC_FULL_SYNC_SIZE_MB_LIMIT` | `256` | `256` |
| `WAHA_GOWS_DEVICE_HISTORY_SYNC_STORAGE_QUOTA_MB` | `512` | `512` |

O webhook (URL, HMAC, retries) não é mais variável do WAHA: o app o registra na sessão. Os eventos
**não** devem ser `*`. O app assina só o que o sistema usa:

```
session.status,message,message.any,message.ack,message.reaction,message.revoked,presence.update
```

`message.any` é obrigatório: `message` não entrega o que foi enviado por outro dispositivo
vinculado, e é justamente esse o caso do atendente que responde pelo celular. Como o WAHA emite
`message` **e** `message.any` para a mesma mensagem (às vezes concorrentes), a unicidade de
`wa_message_id` no banco é a única deduplicação confiável.

### Presença: auto-online desligado

`WAHA_PRESENCE_AUTO_ONLINE=False`. O número **não** aparece "online" para o contato; ele vê
"visto por último", como um celular comum.

A razão é operacional, não estética: enquanto existe um cliente web ativo, o WhatsApp **não manda
push** para o celular. Com auto-online ligado (ou com o padrão `90` segundos depois de cada
chamada), o aparelho do atendente fica sem notificação justamente quando o número é usado também
fora do painel — que é o nosso caso. Presença de `typing`/`recording` continua sendo enviada e
sempre é encerrada com `offline` (ver §5).

### Por que os limites de histórico

Sem limite, o GOWS sincroniza anos de histórico no primeiro pareamento — banda, banco e mídia
explodem. Os limites acima cobrem o backfill padrão de 5 dias com folga.

⚠️ Essas variáveis são enviadas ao WhatsApp no pareamento. Mudá-las **depois** exige
`logout` + novo QR (`DELETE /api/sessions/{session}` e reconectar).

### Persistência da sessão (não perder o QR no redeploy)

Pasta local com volume persistente do Coolify:

```
volume: waha-sessions  →  /app/.sessions
```

O WAHA grava as credenciais em arquivo; com o volume, recriar o container não pede QR. O
autostart é padrão (`WAHA_WORKER_RESTART_SESSIONS=true`), então a sessão volta sozinha quando o
container sobe.

Alternativa (não escolhida): `WHATSAPP_SESSIONS_POSTGRESQL_URL=postgres://...` guarda a sessão no
Postgres. Funciona, mas mistura estado do WAHA no banco do app e exige `max_connections` maior.

## 3. Superfície de rede

WAHA **sem domínio público**: o app o alcança pelo DNS interno do Coolify
(`http://<service-name>:3000`) e busca o QR pelo servidor. O webhook é uma chamada WAHA → app na
rede interna, então `WAHA_WEBHOOK_URL` (env do app) usa o hostname interno do app, não o domínio público.

`WAHA_DASHBOARD_ENABLED=false` e `WHATSAPP_SWAGGER_ENABLED=false` fecham as duas telas que
exigiriam senha própria. Diagnóstico é feito pela tela Canais do app e pelos logs do container.

`WAHA_API_KEY`: mesmo valor (texto puro) no WAHA e no env do app. Com `sha512:<hash>` no WAHA, o app
precisaria do texto puro em `WAHA_API_KEY`, pois o hash não vale como chave de chamada.

## 4. Sessão: estados e endpoints

Estados possíveis: `STOPPED`, `STARTING`, `SCAN_QR_CODE`, `PASSKEY_REQUIRED`,
`PASSKEY_CONFIRMATION_REQUIRED`, `WORKING`, `FAILED`.

| Ação | Chamada |
|---|---|
| criar (sem iniciar) | `POST /api/sessions` `{name, start:false}` |
| iniciar | `POST /api/sessions/{session}/start` |
| consultar | `GET /api/sessions/{session}` |
| QR em imagem | `GET /api/{session}/auth/qr?format=image` |
| QR base64 | `GET /api/{session}/auth/qr?format=base64` |
| parar (mantém sessão) | `POST /api/sessions/{session}/stop` |
| reiniciar | `POST /api/sessions/{session}/restart` |
| logout (perde vínculo) | `POST /api/sessions/{session}/logout` |
| apagar | `DELETE /api/sessions/{session}` |

Criar com nome existente responde **422** — o cliente trata isso como "já existe, então inicia".
`start`, `stop` e `delete` são idempotentes. O QR só existe enquanto o status é `SCAN_QR_CODE`;
fora disso a chamada falha e a tela mostra o status, não um QR velho.

### Código de pareamento (login por número)

Alternativa ao QR, para quem não pode abrir a câmera:

```
POST /api/{session}/auth/request-code
{ "phoneNumber": "5516998124477" }

→ { "code": "ABCD-ABCD" }
```

O usuário digita esse código no WhatsApp do celular: **Aparelhos conectados → Conectar aparelho →
Conectar com número de telefone**.

Condições:
- a sessão precisa estar criada e em `SCAN_QR_CODE`;
- o telefone vai só com dígitos, com DDI;
- os quatro engines suportam, GOWS incluído;
- a própria documentação avisa que **o código nem sempre está disponível** — o QR deve continuar
  na tela como alternativa, nunca ser removido.

O código tem validade curta e é reemitido a cada chamada; a tela mostra o mais recente e o botão
"Gerar novo código".

## 5. Envio humano (anti-bloqueio)

Sequência para cada mensagem enviada pelo painel (Plano 1):

1. `POST /api/sendSeen` `{session, chatId}` — marca como lida.
2. `POST /api/startTyping` `{session, chatId}`.
3. Espera proporcional ao tamanho do texto (mínimo ~1s, máximo ~5s).
4. `POST /api/stopTyping` `{session, chatId}`.
5. `POST /api/sendText` `{session, chatId, text}` → resposta traz `id` (`false_...@c.us_...`).
6. `POST /api/{session}/presence` `{presence:"offline"}` — libera o push no celular.

O WAHA **não** envia "digitando" sozinho; sem o passo 2 o contato não vê o comportamento humano.

## 6. Webhook: contrato real

Corpo:

```json
{
  "id": "evt_...",
  "timestamp": 1741249702485,
  "event": "session.status",
  "session": "main",
  "metadata": {},
  "me": { "id": "5511...@c.us", "pushName": "Dimetal" },
  "payload": { "status": "WORKING", "statuses": [ ... ] },
  "environment": { "version": "2026.9.2", "engine": "GOWS", "tier": "CORE" },
  "engine": "GOWS"
}
```

Autenticação: **HMAC-SHA512 do corpo bruto**, no header `X-Webhook-Hmac`, com
`X-Webhook-Hmac-Algorithm: sha512`. O app compara com `hash_equals` sobre
`hash_hmac('sha512', $rawBody, $segredo)`. Comparar header fixo (ideia original do plano) é mais
fraco e não é o que o WAHA oferece.

Outros headers: `X-Webhook-Request-Id`, `X-Webhook-Timestamp` (ms).

`session.status` traz `payload.status` e o histórico em `payload.statuses[]`. O webhook pode
**repetir** (política de retry acima) — o app tem que ser idempotente por `id` do evento.

## 7. Mídia

`WHATSAPP_EVENTS_DOWNLOAD_MEDIA=true` faz o evento chegar com a URL do arquivo. Com
`WHATSAPP_FILES_LIFETIME=0` o WAHA não apaga; ainda assim o app **baixa e guarda o próprio
arquivo**, porque a retenção de 90 dias é nossa e o volume do WAHA não é backup.

## 8. Riscos operacionais

- **Sessão cai** se o celular desvincular ou ficar muito tempo offline → status vira `FAILED` ou
  `STOPPED`; o app alerta e oferece reconectar sem tocar no servidor.
- **Mudar limites do GOWS** força novo QR (ver §2).
- **Um número por sessão**: `main` e `beta` exigem chips diferentes.
- **API key rotacionada** no Coolify exige atualizar a tela do app; por isso a tela valida a chave
  com um `GET /api/sessions` antes de salvar.

## 9. Armadilhas do GOWS (levantadas no crmv2 e reconferidas na doc)

### Mídia: a URL do evento não é confiável

`media.url` no evento `message` pode vir como `mmg.whatsapp.net/....enc` (cifrado, inútil),
`null`, ou apontando para `localhost` (endereço interno do container WAHA, inalcançável do app).
`GET /api/files` só serve o storage **local**; se um dia migrarmos para S3, é `/api/s3`.

Fallback obrigatório, na ordem:

1. tentar `media.url` se for absoluta e alcançável;
2. senão `GET /api/{session}/chats/{chatId}/messages/{messageId}?downloadMedia=true`;
3. se ainda falhar, gravar a mensagem com `media_path = null` e sinalizar na auditoria. Nunca
   perder o texto por causa do anexo.

`WHATSAPP_FILES_LIFETIME` acima de ~24 dias estoura um timeout de 32 bits dentro do WAHA (issue
#2018). Por isso ele fica em `0` (sem expiração) e a retenção de 90 dias é do nosso app.

### QR: vida curta e finita

O primeiro QR vale 60s; os seguintes, 20s cada, até 6 no total. Depois disso a sessão vira
`FAILED` e **só sai disso com `restart`**. A tela precisa avisar do prazo e oferecer o código de
pareamento como alternativa.

### Código de pareamento

`POST /api/{session}/auth/request-code` com `{phoneNumber}` devolve `{code}`. Não definir nome de
dispositivo customizado na sessão — isso quebra o código de pareamento no GOWS.

### Nono dígito (Brasil)

Sem o app `brazilian-phone-numbers` habilitado no container (`WAHA_APPS_ENABLED`,
`WAHA_APPS_ON`, `WAHA_API_KEY_PLAIN`), o envio escolhe o formato errado do número **em silêncio**:
a mensagem sai e não chega. Não existe correção no código do app; é configuração de container.

### Mensagem que chega com o WAHA fora do ar

`session.status` não reentrega o que passou durante a queda. As mensagens perdidas chegam depois
como *history sync*, não como evento `message`. Por isso existe catch-up na reconexão: ao ver a
sessão voltar para `WORKING`, rodar a mesma rotina idempotente do backfill para a janela recente.

### Filtros na origem

Grupos, status, newsletter e broadcast precisam ser ignorados antes de virar ticket. Sem isso a
fila enche de lixo que ninguém consegue fechar.

### Envio: ritmo importa mais que o texto

O DeskcommCRM (WAHA em produção) limita o envio a ~1 mensagem por 1,2s com jitter de até 800ms, e
5s entre mensagens de campanha. É a defesa mais barata contra bloqueio por padrão de robô. Vale
adotar no `WahaClient`: fila serial por sessão, não `sleep` no request do usuário.

### Envio travado

Mensagem gravada como `sending` há mais de 5 minutos é marcada `failed` por comando agendado.
Sem isso, uma queda do WAHA no meio de um envio deixa a bolha girando para sempre e o atendente
reenvia por fora — gerando justamente a duplicata que o painel deveria evitar.

### Interativos: o que existe de verdade no GOWS


| API | GOWS | Situação |
|---|---|---|
| `POST /api/sendButtons` | ❌ 501 | `DEPRECATED` na doc; implementado só no NOWEB |
| `POST /api/send/buttons/reply` | ❌ | somente WEBJS |
| `POST /api/sendList` | ✔️ | "may stop working anytime", só conversa 1:1 |
| `POST /api/sendPoll` | ✔️ | funciona; voto chega por evento `poll.vote` |

Decisão: **menu de texto numerado**. Aceita número ou nome, tem "voltar" e "atendente", não
depende de API frágil e é trivial de testar e auditar.

