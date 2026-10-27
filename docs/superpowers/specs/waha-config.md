# WAHA/GOWS — configuração de referência

Versão de referência: **WAHA 2026.9.2**, engine **GOWS**, imagem `devlikeapro/waha:gows`.
Levantado em 2026-10-27 direto da documentação oficial (`waha.devlike.pro/docs`).

Este documento existe porque o comportamento de sessão, presença e histórico do WAHA **não é
intuitivo** e porque quase tudo que sobra de configuração nele é variável de ambiente — ou seja,
mora no Coolify e não no banco do app.

## 1. O que é ambiente e o que é sistema

| Onde | O quê | Por quê |
|---|---|---|
| **Coolify** (env do container WAHA) | engine, API key, HMAC do webhook, URL do webhook, limites de histórico do GOWS, presença automática, pasta de sessão/mídia | o WAHA só lê env antes de subir; não existe tela dele no nosso app |
| **Banco do app** (tela Configurações) | URL interna do WAHA, API key (cifrada), segredo HMAC, nome da sessão, dias de backfill, SLA, horário, empresa, SMTP | muda sem redeploy e fica auditável |
| **`.env` do app** | `APP_KEY`, conexão Postgres/Redis, domínio | bootstrap e infraestrutura |

O **mesmo valor** de API key e HMAC aparece nos dois lados (env do WAHA e tela do app). O app
guarda cópia cifrada para poder chamar o WAHA; o WAHA guarda para autenticar as chamadas.

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
| `WAHA_PRESENCE_AUTO_ONLINE` | `true` | `true` |
| `WAHA_PRESENCE_AUTO_ONLINE_DURATION_SECONDS` | `25` | `25` |
| `WHATSAPP_HOOK_URL` | `http://<app-main>/webhooks/waha` | `http://<app-beta>/webhooks/waha` |
| `WHATSAPP_HOOK_HMAC_KEY` | `<segredo 1>` | `<segredo 2>` |
| `WHATSAPP_HOOK_RETRIES_POLICY` | `exponential` | `exponential` |
| `WHATSAPP_HOOK_RETRIES_DELAY_SECONDS` | `3` | `3` |
| `WHATSAPP_HOOK_RETRIES_ATTEMPTS` | `8` | `8` |
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

`WHATSAPP_HOOK_EVENTS` **não** deve ser `*`. Assinar só o que o sistema usa:

```
session.status,message,message.ack,message.reaction,message.revoked,presence.update
```

### Por que 25 segundos de presença

O WAHA marca a sessão como `online` a cada chamada ao WhatsApp e a mantém assim por
`WAHA_PRESENCE_AUTO_ONLINE_DURATION_SECONDS` (padrão `90`, que é o comportamento do WhatsApp Web).
Com `25` o contato vê "online" por alguns segundos e depois "visto por último", igual a um celular
normal. Não existe código nosso nessa parte.

**Nuance que importa para a operação:** enquanto existe um cliente web ativo, o WhatsApp **não
manda push** para o celular do atendente. Por isso o envio humano termina com presença
`offline` (ver §5), senão o atendente não é notificado no telefone.

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
rede interna, então `WHATSAPP_HOOK_URL` usa o hostname interno do app, não o domínio público.

`WAHA_DASHBOARD_ENABLED=false` e `WHATSAPP_SWAGGER_ENABLED=false` fecham as duas telas que
exigiriam senha própria. Diagnóstico é feito pela tela Canais do app e pelos logs do container.

`WAHA_API_KEY` em `sha512:`: o valor em texto puro fica só na tela do app, nunca no env do WAHA.

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
