# Caixa de saída: envio que sobrevive à queda do WhatsApp (design)

Data: 29/10/2026. Pedido do dono: se o WhatsApp cair ou a sessão precisar ser sincronizada de novo, as mensagens que os
atendentes mandarem nesse intervalo **esperam e saem depois**, na ordem, em vez de virar falha. Caminho 3 escolhido
(caixa de saída durável no banco).

## Fatos que decidem o desenho

- O WAHA **não enfileira**: com a sessão fora de `WORKING`, `sendText` responde 422 ("Session status is not as expected")
  e não guarda a mensagem. Quem guarda é o nosso app.
- **Repetir às cegas duplica** (WAHA #1225): só se repete o que **certamente não saiu**.
- DeskcommCRM (referência): a linha nasce `queued` e, com a sessão fora de `WORKING`, nem tenta enviar; a regra de reenvio é
  uma só (tela e servidor) e separa falha em **seguro / confirmar / nenhum**; o desconhecido cai em "confirmar"
  ("perguntar custa um toque, duplicar custa a confiança do cliente"); insistir nos limites de spam derruba o número.

## Estados (`messages.delivery_status` + `messages.failure_code`)

| Estado | Significado | Reenvio |
|---|---|---|
| `null` | enviando | — |
| `queued` | **aguardando conexão**; nada saiu | automático ao reconectar (até 24 h) |
| `sent`/`delivered`/`read` | saiu | — |
| `failed` + `session_down` | nunca ocorre: vira `queued` | — |
| `failed` + `expired` | ficou 24 h na fila | seguro (um toque) |
| `failed` + `unknown` | sem resposta (timeout): **pode ter saído** | confirmar antes (conferir no celular) |
| `failed` + `rate_limit` | 463/475 do WhatsApp | **nenhum**: insistir piora a conta |
| `failed` + outro | recusa do WAHA com motivo | confirmar |

## Regras

1. **Ordem por conversa:** uma mensagem nova entra em `queued` se já houver `queued` mais antiga no mesmo canal.
2. **Só o certamente-não-saiu entra na fila:** 422 de sessão, conexão recusada, DNS. Timeout, reset e resposta vazia são
   "desconhecido" (`unknown`).
3. **Eco tardio cura:** o eco `fromMe` casa com a linha `queued`/`failed` sem id (janela de 60 s a partir da última
   tentativa, `updated_at`) e a marca `sent`, limpando a falha.
4. **Esvaziar a fila:** ao chegar `session.status = WORKING` (e de minuto em minuto, como rede de segurança), o
   `DrainOutboxJob` do canal envia as `queued` em ordem (`id`), para na primeira que voltar a esperar e expira as com mais de
   24 h. Roda na fila `outbound` (um processo), então não concorre com os envios novos.
5. **Reenviar na mesma linha** (sem bolha nova), com trava por `updated_at` (compare-and-set): dois toques enviam uma vez.
   A regra de reenvio é uma só, usada pela tela e pelo servidor.
6. **Aviso na tela:** faixa acima da caixa de texto quando o canal não está `WORKING`; bolha "Aguardando conexão".

## Fora do escopo

Freio por canal depois de 463/475 (vem com o limite de envio por sessão); mídia na caixa de saída (a fatia de envio de
arquivos herda esta mesma regra).
