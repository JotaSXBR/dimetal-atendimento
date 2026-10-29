# Notas — WAHA: presença, envio humanizado e mídia (28/10/2026)

Levantamento feito a partir da documentação do WAHA e do repositório dele, para orientar a fatia de mídia e presença
e os limites de requisição. Vale para o engine GOWS, que é o que o projeto usa. **Verificar sempre no Swagger da
instância (`/swagger`) antes de depender de um detalhe**, porque a documentação nem sempre separa os engines.

## Como o WhatsApp "se comporta" e o que o WAHA oferece

| Comportamento | Endpoint | Observação |
|---|---|---|
| Marcar como visto | `POST /api/sendSeen` (`session`, `chatId`, `messageIds`) | Sem `messageIds`, marca tudo o que estiver não lido. Em GOWS há relato de que **só marca com `messageIds`** (issue aberta); passar sempre os ids. |
| Digitando / parou | `POST /api/startTyping`, `POST /api/stopTyping` ou `POST /api/{session}/presence` com `typing` e `paused` | O WAHA **não envia digitando sozinho**; é preciso chamar antes do `sendText`. |
| Gravando áudio | `POST /api/{session}/presence` com `chatId` e `presence: "recording"` | Mesmo mecanismo do digitando. |
| Online / offline | `POST /api/{session}/presence` sem `chatId`, `presence: "online"` ou `"offline"` | Qualquer chamada ao WhatsApp deixa a sessão **online por 90 s** (`WAHA_PRESENCE_AUTO_ONLINE_DURATION_SECONDS`). Enquanto "online", o **celular do atendente não recebe notificação push**. Mandar `offline` depois da atividade devolve as notificações. |
| Ver presença do cliente | `POST /api/{session}/presence/{chatId}/subscribe` + webhook `presence.update` | Só aparece se o contato compartilha. |

**Sequência recomendada para cada mensagem enviada** (a do próprio WAHA): `sendSeen` das mensagens do cliente →
`startTyping` → espera aleatória proporcional ao tamanho do texto → `stopTyping` → `sendText` → `presence offline`.
Sem tempo fixo: delays previsíveis são tão suspeitos quanto nenhum.

## Envio de arquivos

| Tipo | Endpoint | Cuidado |
|---|---|---|
| Imagem | `/api/sendImage` | Usar JPEG. |
| Áudio de voz | `/api/sendVoice` | Exige **OGG com OPUS**. `convert: true` ou `POST /api/{session}/media/convert/voice` converte. Áudio gravado no navegador (webm) precisa dessa conversão. |
| Vídeo | `/api/sendVideo` | `asNote` envia como vídeo redondo; `convert` disponível. |
| Documento, figurinha | `/api/sendFile`, `/api/sendSticker` | Documentados só na tabela de recursos. |

Cada arquivo vai por `url` ou `data` (base64).

## Visualização única (view once)

**Não há suporte utilizável.** O envio é um pedido de recurso aberto no repositório do WAHA, e o recebimento no
webhook também está em aberto (o único tratamento existente é do engine NOWEB). Consequência para o produto: uma
mensagem de visualização única chega sem conteúdo. O painel deve mostrar um aviso claro ("o cliente enviou uma
mensagem de visualização única; abra no celular"), e não uma bolha vazia. O projeto já guarda o tipo da mensagem sem
corpo, então falta só o texto de aviso na tela.

## Saúde da conta (evitar bloqueio)

- **Só responder**, nunca iniciar conversa. O bot recebe primeiro, então o modelo do produto já respeita isso.
- Texto variado, nada de envio em massa; uma mensagem curta ao abrir a conversa.
- Ritmo: o WAHA sugere pacotes pequenos com pausas (ex.: no máximo 4 mensagens por contato que respondeu, por hora,
  em disparo ativo). Atendimento 1:1 respondendo fica muito abaixo disso.
- **Erros 463 (timelock) e 475 (limite de mensagens)** em envio para contato novo: pausar, **não reiniciar nem
  refazer o pareamento da sessão** (não resolve e piora). Há avisos `FIRST_WARNING` e `SECOND_WARNING` no webhook.
- A conta precisa de foto, nome e status; mensagem de conta "vazia" é mais marcada como spam.

## Consequências para a implementação

1. **Envio com presença** vira parte do `SendTicketMessageJob`: `sendSeen` com os ids, digitando, espera aleatória
   de 1 a 6 s (proporcional ao texto), `sendText`, `offline`. Todas as falhas de presença são "melhor esforço" e não
   derrubam o envio.
2. **A espera não pode travar a fila**: com um único worker, o `sleep` do digitando atrasaria ingestão de mensagens
   e acks. Opções: fila e worker próprios para saída, ou mais processos no worker. Decidir na spec de mídia e presença.
3. **Indicador "digitando" do atendente** (painel → cliente) é um evento por tecla: precisa de debounce no navegador
   (um aviso a cada poucos segundos) e de **limite próprio**, separado do limite de envio.
4. **Limites de requisição** (decisão do dono): webhook 300/min por IP; envio de mensagem 30/min por usuário. O
   digitando e a gravação ganham um limite à parte, mais folgado, quando a presença for implementada.
5. **Tratar 463/475** no envio: motivo de falha específico e alerta no canal, sem retentar.
6. **Mensagem de visualização única**: aviso no lugar do conteúdo vazio.
7. **Áudio e imagem gravados no painel**: conversão para OGG/OPUS (`convert: true`) e JPEG antes de enviar.

## Envio humanizado sem travar a fila (decisão de desenho)

**Problema.** O envio com presença leva de 1 a 6 s de espera (digitando) dentro do job. Hoje existe **um** worker e ele
também processa a ingestão do webhook, os acks e os **broadcasts** do tempo real (`InboxEvent` é `ShouldBroadcast`, que
roda na fila `default`). Um `sleep` de 6 s no meio atrasa mensagens recebidas, ticks e a tela em tempo real, que é
justamente o motivo de o projeto usar o Reverb.

**Restrição que decide.** A **ordem das mensagens de uma conversa**. O atendente manda A (longa) e logo B (curta): têm de
chegar A e depois B. Como o tempo de digitação cresce com o tamanho do texto, qualquer desenho que despache cada envio
com um atraso próprio deixa B ultrapassar A.

| Opção | Ordem por conversa | Custo | Veredito |
|---|---|---|---|
| **A. Fila `outbound` com worker próprio, um processo** | Garantida: a fila é FIFO e só há um consumidor | Um recurso novo no Coolify (estágio `worker-outbound` no Dockerfile) | **Recomendada** |
| B. Jobs encadeados sem bloquear (job 1: visto + digitando; job 2 com `delay`: para de digitar + envia + offline) | Precisa de serialização por conversa (`WithoutOverlapping` com chave do chat, ou "próximo envio em" por conversa) | Código mais complexo; a documentação do Laravel não confirma que o `delay` de um job dentro de `Bus::chain` seja respeitado, então exige teste | Só se um único worker de saída virar gargalo |
| C. Vários processos no worker atual | Perde a ordem: dois envios seguidos caem em processos diferentes | Nenhum | Descartada |

**Detalhes da opção A.**
- Worker próprio: `php artisan queue:work --queue=outbound --tries=1 --timeout=120`. O `SendTicketMessageJob` passa a ir para
  `onQueue('outbound')`. Ingestão, acks e broadcasts continuam no worker `default`, sem espera.
- **Um processo só.** Com cinco atendentes, a vazão de um worker de saída (alguns segundos por mensagem) basta. Se não bastar,
  a saída é a opção B, e não mais processos.
- **Timeout.** Cada envio faz até 5 chamadas ao WAHA (visto, digitando, parar, enviar, offline), cada uma com timeout de HTTP de 15 s.
  O pior caso passa de 60 s, o timeout padrão do job. Subir o timeout do job para 120 s e manter o `retry_after` da fila acima disso
  (a documentação do Laravel exige timeout menor que `retry_after`). Cliente HTTP com timeout próprio, porque I/O bloqueante pode
  ignorar o timeout do worker.
- **`tries = 1` continua valendo**: o serviço grava a linha antes de chamar o WAHA e repetir duplicaria a mensagem. Falha de
  presença (visto, digitando, offline) é melhor esforço e não derruba o envio.
- **Mensagem aparece na hora.** Hoje a linha só nasce quando o job roda. Com fila de saída com atraso, o atendente ficaria sem ver a
  própria mensagem. Criar a linha (estado "enviando") no momento do clique e deixar o job só enviar. O eco do celular continua
  casando com essa linha pelo texto, como já faz.
- **`offline` com debounce.** Mandar `offline` depois de cada mensagem faz a presença oscilar numa conversa rápida. Mandar uma vez,
  depois de um período sem envio, cobre o objetivo (devolver o push ao celular).
- **Limite de envio**: o teto de 30 mensagens por minuto por usuário já existe no painel. Para a saúde da conta, um teto por sessão do
  WhatsApp na fila de saída (`RateLimited`) é a próxima camada, e a documentação do WAHA recomenda ritmo, não rajada.

**Broadcasts.** Separar o tempo real da ingestão é barato: o Laravel 13 aceita o atributo `#[Queue('broadcasts')]` no evento, e o worker
pode consumir `--queue=broadcasts,default` para atender o tempo real primeiro. Não é necessário agora; entra se a ingestão pesar.

**Decisão registrada para a spec de mídia e presença:** opção A, com ordem por conversa como critério de desempate.
