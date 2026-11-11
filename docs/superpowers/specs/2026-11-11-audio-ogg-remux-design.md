# Áudio de voz: remux para OGG/Opus no navegador e envio sem recodificar

Data: 2026-11-11. Só o beta está em escopo. Design curto (mudança pequena), aprovado pelo dono no chat em 11/11 ("faça o A e os testes
de comparação", depois "fazer configuração do formato ogg ... veja outras configurações e pesquise sobre").

## Por quê

No beta o primeiro áudio de voz enviado chegou ao cliente sem a onda (waveform), e o segundo em sequência chegou com. Evidência (log do
WAHA 2026.10.1 e print do dono, 11/11): as duas mensagens saíram como voz (`PTT:true`, `audio/ogg; codecs=opus`) pelo mesmo caminho do
app (`/api/sendVoice`, `convert: true`, conversão ffmpeg sem erro); na primeira o WAHA registrou `Failed to generate waveform: Error:
Cannot detect audio format`, que o `audio-decode` lança quando os bytes recebidos não têm assinatura reconhecível (OggS, RIFF, ID3...).
O conversor do WAHA gera sempre OGG/Opus válido para um WebM como o do Chrome (testado na imagem do beta), então o buffer ruim é interno
do WAHA. A API do `sendVoice` não aceita onda pronta.

## Metas

1. **Remux no navegador:** ao parar a gravação em WebM/Opus (Chrome), converter o contêiner para OGG/Opus **sem recodificar** e subir
   esse arquivo (prévia e envio). Firefox grava OGG/Opus e segue como está. Safari (AAC em mp4) e qualquer falha do remux seguem o caminho
   de hoje (`convert: true`).
2. **Envio sem conversão quando dá:** `WahaMedia::send` manda `convert: false` e o tipo `audio/ogg; codecs=opus` só quando os bytes são
   OGG/Opus **mono** de verdade (`OggS` no byte 0, `OpusHead` no byte 28, canais = 1 no byte 37). Qualquer outro áudio (mp3, m4a, OGG em
   Vorbis, estéreo) continua com `convert: true`. A checagem é no servidor.
3. **Configuração da gravação (pesquisada):** `MediaRecorder` com `audioBitsPerSecond: 32000` (voz "praticamente transparente" a 32 kbps
   no Opus; é o mesmo valor que o WAHA usa hoje), microfone mono 48 kHz com eco, ruído e ganho automático ligados (padrão dos
   navegadores). O `MediaRecorder` não expõe tamanho de quadro nem a aplicação do codificador. O tamanho máximo (5 min) fica em ~1,2 MB.
4. **Testes de comparação no beta:** áudios curtos para o contato de teste do dono, antes e depois: braço antigo (mp3, `convert: true`) e
   braço novo (OGG/Opus, `convert: false`), em dois padrões (seguidos e depois de ~5 min parado); leitura do log do WAHA (erro de onda) e
   do campo `waveform` de cada webhook.

## Não metas

- Mudar o WAHA, gerar a onda por fora ou mexer na prévia/UI do gravador.
- Recodificar áudio no navegador (WASM) ou no servidor (sem ffmpeg na imagem do app).
- Mudar o fluxo de anexos de áudio comuns (continuam `convert: true`).

## Anti-metas

- Enviar ao WhatsApp um OGG que não seja Opus mono com `convert: false` (voz inaudível ou recusada).
- Perder a gravação se o remux falhar: sempre cai no WebM original.

## Abordagens consideradas

- **Remux no navegador + `convert: false` (escolhida pelo dono):** sem dependência nova, sem recodificar; custo: código de contêiner próprio.
- **Remux em PHP no servidor:** mesma lógica em PHP, sem ffmpeg; mais código no servidor e mantém o navegador subindo WebM maior.
- **OGG/Opus direto no navegador com `opus-recorder` ou `opus-media-recorder` (WASM):** dependência nova (a primeira sem manutenção).
- **Gerar a onda nós mesmos:** a API do `sendVoice` não tem o campo.

## Deixado ao construtor

Nomes dos arquivos e funções, tamanho das páginas OGG, como validar o OGG gerado (testes de JS e `ffprobe` da imagem do WAHA).
