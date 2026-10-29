# Mídia e presença: design (fatia 3)

Data: 29/10/2026. Escopo escolhido pelo dono: **receber e mostrar mídia + envio de texto humanizado** (visto, digitando,
espera, envio, offline). Enviar arquivos e áudio gravado pelo painel ficam para a fatia seguinte. Pesquisa de base em
`docs/superpowers/notes/2026-10-28-waha-presenca-e-midia.md`.

## Decisões do dono

- **Armazenamento: dentro do WAHA**, com a limpeza por variável de ambiente dele. O Laravel não copia o arquivo.
- Retenção de 90 dias: `WHATSAPP_FILES_LIFETIME=7776000` (segundos) e `WHATSAPP_FILES_FOLDER=/app/.media` (volume já existente).
  O teto antigo de ~24 dias (estouro de 32 bits, issue #2018) foi corrigido na 2026.4.1; o beta usa `gows-2026.10.1`.

## Riscos aceitos do armazenamento no WAHA (verificar no beta antes de confiar)

1. **Backup:** o volume `/app/.media` não tem backup. Perdeu o volume, perdeu a mídia; o texto continua no Postgres.
2. **Reinício do WAHA:** a limpeza usa temporizador interno. Verificar no beta se, depois de reiniciar, arquivos antigos
   ainda expiram. Se não expirarem, o volume cresce sem limite; plano B: `find /app/.media -mtime +90 -delete` por cron no host.
3. **Acoplamento:** trocar o WAHA ou a pasta perde os arquivos. A mensagem guarda só a referência (abaixo), então migrar para
   S3 depois (`WAHA_MEDIA_STORAGE=S3`) muda a origem sem mudar o modelo.

## Receber

- `messages` ganha colunas **nulas** (migração só de expansão): `media_mime`, `media_name`, `media_size` e `media_ref`
  (caminho do arquivo no WAHA, relativo, nunca a URL de dentro do container).
- `IncomingMessage` já marca `type='media'`; passa a ler `media.url`, `media.mimetype`, `media.filename`.
- Obtenção do arquivo, na ordem do `waha-config.md` §9: `media.url` do evento se for do próprio WAHA; senão
  `GET /api/{session}/chats/{chatId}/messages/{id}?downloadMedia=true`; se falhar, gravar a mensagem com `media_ref=null`
  e mostrar "mídia indisponível". **Nunca perder o texto por causa do anexo.**
- Visualização única: o evento chega sem conteúdo. A bolha mostra o aviso "o cliente enviou uma mensagem de visualização
  única; abra no celular", sem bolha vazia.

## Servir

- Rota autenticada `GET /midia/{message}`: autoriza com `TicketPolicy` (mesma regra de visibilidade do ticket), busca o
  arquivo no WAHA com a chave de API e repassa em stream. O navegador **nunca** fala com o WAHA nem recebe a chave.
- Cabeçalhos: `Content-Type` vindo da lista permitida (imagem, áudio, vídeo, pdf; o resto sai como
  `application/octet-stream` com `Content-Disposition: attachment`), `X-Content-Type-Options: nosniff`, `Cache-Control: private`.
- Limite de tamanho no repasse e `ETag` para o navegador não rebaixar. O `media_ref` é validado contra `..` e contra sair da pasta.

## Enviar com presença (fila `outbound`)

Segue a opção A da nota: fila `outbound`, worker próprio de **um processo**, `--tries=1 --timeout=120`.

1. Clique em enviar: a linha nasce na hora em estado "enviando" e o job só envia.
2. Job: `sendSeen` com os ids do cliente → `startTyping` → espera aleatória de 1 a 6 s proporcional ao texto →
   `stopTyping` → `sendText`. Falha de presença é melhor esforço e não derruba o envio.
3. `offline` com debounce: uma vez, depois de um período sem envio, para devolver o push ao celular.
4. Erros 463/475: motivo de falha específico, alerta no canal, sem retentar e sem reparear a sessão.
5. `retry_after` da fila acima do timeout do job.

Infra: estágio `worker-outbound` no Dockerfile e novo recurso `outbound-beta` no Coolify, antes do `deploy-beta` incluí-lo na ordem.

## Fora do escopo desta fatia

Enviar arquivo, áudio gravado no painel e conversão OGG/OPUS; indicador "digitando" do atendente para o cliente (precisa de
limite próprio); limite por sessão do WhatsApp na fila (`RateLimited`).

## Testes

- Ingestão: mídia com URL boa, URL inútil (`.enc`, `localhost`) com fallback, falha total (texto preservado), visualização única.
- Rota de mídia: 403 para quem não vê o ticket, tipo fora da lista vira download, caminho com `..` recusado.
- Envio: ordem A→B garantida pela fila; falha de presença não impede o envio; 463/475 não retenta.
