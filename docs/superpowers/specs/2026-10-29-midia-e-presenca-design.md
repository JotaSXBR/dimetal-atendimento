# Mídia e presença: design (fatia 3)

Data: 29/10/2026. Base: `docs/superpowers/notes/2026-10-28-waha-presenca-e-midia.md`.

## Intenção

Hoje uma foto ou áudio do cliente aparece como bolha vazia. O atendente precisa **ver imagens e vídeos e ouvir áudios**. As
respostas devem sair com cara humana (visto, digitando, pausa, envio, offline) para proteger o número de bloqueio e manter o
celular do atendente recebendo notificação.

## Escopo

- **Dentro:** receber e mostrar imagem, áudio, vídeo e documento; aviso para visualização única; envio de texto humanizado
  numa fila de saída própria.
- **Fora (próxima fatia):** enviar arquivo e áudio gravado pelo painel; indicador "digitando" do atendente para o cliente;
  limite por sessão do WhatsApp na fila.
- **Anti-objetivos:** nada de `ffmpeg` na imagem; WAHA sem endereço público; nenhuma bolha vazia; nunca perder o texto por
  causa do anexo.

## Decisões do dono

- Navegadores de uso: Chrome, Edge, Safari do iPhone (iOS 27) e Chrome do Android. OGG/OPUS toca direto em todos (Safari
  desde o iOS 18.4), então **o áudio recebido não é convertido**.
- **O WAHA guarda os arquivos** (entrada e saída), com a limpeza por variável dele. S3 entra depois, trocando
  `WAHA_MEDIA_STORAGE`; o modelo não muda.
- Retenção de 90 dias: `WHATSAPP_FILES_LIFETIME=7776000` (hoje `0`) com `WHATSAPP_FILES_FOLDER=/app/.media`. O limite antigo de
  ~24 dias foi corrigido na 2026.4.1; o beta usa `gows-2026.10.1`.
- Redis no WAHA: fica sem. A documentação só o liga aos "Apps" (bullmq), e nada de mídia ou presença depende dele.

## Approaches

Como o navegador recebe o arquivo:

1. **Rota do Laravel que repassa o arquivo (escolhida).** Autoriza pelo ticket, busca no WAHA com a chave e devolve em stream.
   A chave não sai do servidor. Com S3, a mesma rota redireciona para URL assinada.
2. Navegador direto no WAHA: exigiria expor o WAHA e a chave. Descartada.
3. Laravel copia o arquivo para disco próprio: duplica armazenamento e contraria a decisão de guardar no WAHA. Descartada.

Como o envio humanizado não trava o resto: **fila `outbound` com worker próprio de um processo** (a nota registra o porquê:
ordem das mensagens por conversa). Jobs encadeados com atraso e vários processos foram descartados.

## Riscos aceitos do armazenamento no WAHA (verificar no beta)

1. Volume `/app/.media` sem backup; o texto continua no Postgres.
2. A limpeza usa temporizador interno: confirmar que arquivos antigos ainda expiram depois de reiniciar o WAHA. Se não, plano B
   é `find /app/.media -mtime +90 -delete` por cron no host.
3. Não se sabe se o WAHA guarda sozinho o que enviamos por `data`/`url`: verificar na fatia de envio de arquivos.

## Receber

- `messages` ganha colunas nulas (só expansão): `media_mime`, `media_name`, `media_size`, `media_ref` (nome do arquivo no WAHA).
- Obtenção, na ordem: arquivo local indicado no evento (`media.filename`/`/api/files/...`); senão `GET
  /api/{session}/chats/{chatId}/messages/{id}?downloadMedia=true`. A `media.url` do GOWS vem como `….enc` (CDN
  criptografado) e não serve. Falhou tudo: grava a mensagem com `media_ref=null` e mostra "mídia indisponível".
- Visualização única chega sem conteúdo: aviso "o cliente enviou uma mensagem de visualização única; abra no celular".

## Servir

- `GET /midia/{message}`: `TicketPolicy` decide; busca o arquivo no WAHA; repassa em stream.
- `Content-Type` só de uma lista permitida (imagem, áudio, vídeo, pdf); o resto vira `application/octet-stream` com
  `Content-Disposition: attachment`; `X-Content-Type-Options: nosniff`; `Cache-Control: private`.
- `media_ref` validado contra `..` e separadores de caminho. Suporte a `Range` para áudio e vídeo (Safari exige).

## Enviar com presença

`sendSeen` com os ids do cliente → `startTyping` → espera aleatória de 1 a 6 s proporcional ao texto → `stopTyping` →
`sendText`; `offline` com debounce depois de um período sem envio. Falha de presença é melhor esforço. A linha da mensagem
nasce no clique ("enviando"). Erros 463/475: motivo próprio, alerta no canal, sem retentar e sem reparear a sessão. Job com
`--tries=1 --timeout=120`, `retry_after` maior. Infra: estágio `worker-outbound` no Dockerfile e recurso `outbound-beta` no
Coolify, incluído na ordem do `deploy-beta`.

## Testes

Ingestão (arquivo local, fallback `downloadMedia`, falha total preservando o texto, visualização única); rota de mídia (403
sem permissão, tipo fora da lista vira download, `..` recusado, `Range`); envio (ordem A→B, falha de presença não impede o
envio, 463/475 sem retentar).

## Fica para o construtor

Aparência das bolhas de mídia (seguir o padrão visual atual do Inbox com Flux); tamanho máximo do repasse; textos dos avisos.
