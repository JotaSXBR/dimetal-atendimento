# Envio de anexos e áudio gravado pelo painel — design curto

Data: 2026-10-29. Status: aguardando aprovação do dono. Faz parte da "leva de anotações" do inbox (antes da Fase 2,
Contatos). Referências lidas: gravador de voz do CRMv2 (`apps/web/src/components/voice-recorder.tsx`,
`lib/media-upload.ts`), compositor do Whaticket (MIT) e a barra de resposta do Chatwoot.

## Por que

O painel só envia texto. O atendente manda foto já salva, PDF, Word, Excel e áudio gravado na hora, como no WhatsApp Web.
Sem isso o painel não substitui o Cam Krolik.

## Objetivos

1. Anexar um ou vários arquivos (clipe, Ctrl+V de imagem, arrastar para a conversa), ver a bandeja de prévia e enviar.
2. Gravar áudio pelo microfone do computador, ouvir antes e enviar como mensagem de voz do WhatsApp.
3. Anexo e áudio passam pela mesma fila, ordem, "aguardando conexão", falha e reenvio do texto.

## Não-objetivos

Figurinha, GIF animado, localização e contato enviados pelo painel, vídeo gravado na webcam, editar imagem, compartilhar tela,
responder citando mensagem. Foto `.heic` não é aceita (o WhatsApp não a aceita como foto).

## Decisões do dono

- **Vários arquivos de uma vez.** Cada arquivo vira uma mensagem própria, enviada na ordem escolhida.
- **Legenda.** O texto digitado na caixa vira a legenda do **primeiro** arquivo; os demais vão sem legenda. Sem arquivo,
  o texto é uma mensagem normal.
- **Tipos aceitos:** JPEG, PNG, WebP; PDF; Word (`.doc`, `.docx`); Excel (`.xls`, `.xlsx`, `.csv`); áudio como arquivo (MP3, OGG, M4A);
  vídeo MP4. Executáveis e scripts (`.exe`, `.bat`, `.js`, `.zip` e parecidos) são recusados.
- **Limites:** 16 MB por arquivo, até 10 arquivos por envio.
- **Áudio gravado:** botão de microfone ao lado do clipe; faixa de gravação com cronômetro, **Descartar** e **Parar**; prévia
  no player de áudio com **Regravar**, **Descartar** e **Enviar**; sai como mensagem de voz; gravação com menos de 1 s é
  descartada; máximo de 5 minutos (para sozinha, com aviso); permissão negada mostra "Permita o microfone no navegador para
  gravar". Enquanto grava, o cliente vê "gravando áudio…" (presença da leva), renovada a cada poucos segundos e encerrada ao
  parar ou descartar.

## Decisões do builder (contestáveis)

- **Cópia local.** O arquivo enviado fica no disco privado (`storage/app/private/outbound/…`), porque um anexo "aguardando
  conexão" por até 24 h, ou para reenvio, precisa existir fora do WAHA. Nova coluna `messages.local_path` e
  `messages.media_purged_at`. A rota `midia/{message}` serve do disco quando `local_path` existe e do WAHA quando não.
  Comando diário `app:purge-media` apaga as cópias com mais de 90 dias (mesma regra do desenho) e grava `media_purged_at`.
- **Envio ao WAHA** em base64 (`file: {mimetype, filename, data}`): `sendImage` (imagem), `sendFile` (documentos e planilhas),
  `sendVideo` (MP4), `sendVoice` (áudio gravado e arquivos de áudio, `convert: true`). Legenda no campo `caption`.
- **Conversão do áudio gravado** (navegador grava `webm/opus`): `convert: true` do `sendVoice`. A documentação do WAHA descreve
  essa opção, mas não diz se a edição gratuita a inclui: **verificar no Swagger do beta**. Se faltar, o servidor converte com
  `ffmpeg` (instalado na imagem do app) antes de enviar.
- **Validação no servidor:** tipo real do arquivo (conteúdo, não a extensão do navegador), tamanho, quantidade, nome saneado
  (sem caminho, sem caracteres de controle). Livewire `WithFileUploads` guarda o temporário; só vai para o disco definitivo
  quando a mensagem é criada.
- **Mesmo limite de envio** do texto (30/min por usuário): cada arquivo conta como um envio. Presença "gravando" tem limite
  próprio, mais folgado.
- **Mensagem de mídia** reaproveita `type` (`image`, `document`, `audio`, `video`), `media_mime`, `media_name`, `media_size` e `body`
  (legenda). Quando o eco `fromMe` do WAHA volta, ele casa com a linha e o `ResolveMessageMediaJob` preenche `media_ref`.

## Comportamentos que não podem quebrar

- Ordem: A (arquivo) e B (texto) enviados nessa ordem chegam nessa ordem; fila `outbound`, um consumidor.
- Disco: nenhum arquivo enviado fica sem prazo de vida; sem cópia órfã se a criação da mensagem falhar.
- A barra trava (botão desabilitado) enquanto sobe o upload; erro de um arquivo não bloqueia os outros.
- Sessão do WhatsApp caída: mensagem de mídia fica "aguardando conexão", como o texto.
- Recorder: ao trocar de ticket ou sair da tela, o microfone é liberado e a presença "gravando" é encerrada.

## Testes

Unitários da validação de tipo/tamanho/nome; `Http::fake` para cada endpoint do WAHA (corpo, `caption`, `convert`); envio de 3
arquivos mantém a ordem; arquivo recusado não cria mensagem nem deixa arquivo; "aguardando conexão" com anexo envia na
reconexão; `app:purge-media` apaga só o que passou de 90 dias; rota de mídia serve a cópia local. O gravador (JavaScript) é
verificado à mão no beta; o servidor é coberto por teste.

## Em aberto

Disponibilidade do `convert` na edição do WAHA do beta (ver acima).
