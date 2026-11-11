# Áudio de voz: remux para OGG/Opus e envio sem recodificar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O áudio gravado no Chrome (WebM/Opus) vira OGG/Opus no navegador sem recodificar e vai ao WAHA com `convert: false` quando os bytes forem OGG/Opus mono; o resto segue como hoje. Depois, comparar a presença da onda antes e depois no beta.

**Architecture:** Um módulo JS puro (`resources/js/webm-to-ogg.js`) lê o WebM da gravação (elementos EBML, Opus em SimpleBlock) e escreve um OGG (OpusHead, OpusTags, páginas com CRC). O `voice-recorder.js` o chama ao parar a gravação, com queda para o WebM original se algo falhar. No servidor, uma checagem de assinatura (`OggOpus::isMono`) decide em `WahaMedia::send` se vai `convert: false`.

**Tech Stack:** Laravel 13, Livewire 4.4, Alpine, PHPUnit, `node --test`, ffmpeg/ffprobe da imagem `devlikeapro/waha:gows-2026.10.1` (só para conferir arquivos).

**Spec:** `docs/superpowers/specs/2026-11-11-audio-ogg-remux-design.md`.

## Global Constraints

- Sem dependência nova (composer ou npm), sem WASM, sem mudar o WAHA. Voz só passa a `convert: false` com OGG/Opus **mono** de verdade (`OggS` no byte 0, `OpusHead` no byte 28, canais = 1 no byte 37); qualquer outro áudio mantém `convert: true`.
- Gravação: `MediaRecorder` com `audioBitsPerSecond: 32000`; microfone com `channelCount: 1`, `sampleRate: 48000` (ideais) e eco, ruído e ganho automático ligados. O remux só vale se o `OpusHead` do WebM disser mono; senão sobe o WebM original (caminho de hoje).
- Falha do remux nunca perde a gravação: sempre cai no arquivo original.
- Anexos de áudio comuns (mp3, m4a) não mudam. Nada muda na UI do gravador.
- Commits em português com prefixo, um por tarefa; sem referência a projetos de terceiros em código, commit e PR. Editar com `Edit`; script Python no Windows grava CRLF (Pint reprova): `sed -i 's/\r$//'`; Blade/Python com `\` em heredoc: usar arquivo de script.
- Testes: Postgres de teste no ar (`pg-test`, porta 5432), `php artisan test --parallel`; JS com `node --test tests/js`.

## Review Focus

1. OGG em Vorbis, OGG estéreo, arquivo truncado e mp3 nunca vão com `convert: false`. (Task 1)
2. O OGG gerado tem CRC de página correto, granule position crescente (48 kHz, com o pre-skip) e o mesmo número de pacotes do WebM; um decodificador real (ffmpeg) lê a duração certa. (Task 2)
3. WebM de MediaRecorder tem Segment e Cluster com tamanho desconhecido, SimpleBlock e (às vezes) BlockGroup: o leitor tolera isso e recusa (devolve `null`) lacing, outro codec, mais de uma faixa ou estéreo. (Task 2)
4. Se o remux falhar ou o navegador gravar outro formato (Safari, mp4), o áudio sobe igual a hoje. (Task 3)
5. Pacote Opus de 120 ms e quadros com mais de um frame (códigos 1 a 3 do TOC) somam a duração certa no granule. (Task 2)

---

### Task 1: Assinatura OGG/Opus mono e `convert` no envio

**Files:**
- Create: `app/Services/Inbox/OggOpus.php`, `tests/Unit/OggOpusTest.php`
- Modify: `app/Services/Waha/WahaMedia.php:25-55` (`send`), `tests/Feature/Livewire/InboxVoiceTest.php`, `tests/Feature/Inbox/SendMediaTest.php`

**Interfaces:**
- Produces: `OggOpus::isMono(string $bytes): bool` — `true` só se `OggS` em 0..3, `OpusHead` em 28..35 e o byte 37 (canais) for 1; qualquer entrada curta ou diferente é `false`.
- Produces: em `WahaMedia::send(...)`, para `$kind === 'audio'`: decodifica só o começo do base64 (`base64_decode(substr($data, 0, 88), true)`) e, se `OggOpus::isMono(...)`, manda `file.mimetype = 'audio/ogg; codecs=opus'` e `convert = false`; senão mantém o `mimetype` recebido e `convert = true`.

- [ ] **Step 1: Write the failing tests:** `OggOpusTest`: `test_ogg_opus_mono_e_aceito`, `test_estereo_e_recusado` (byte 37 = 2), `test_ogg_vorbis_e_recusado` (sem `OpusHead` no 28), `test_arquivo_curto_ou_truncado_e_recusado`, `test_mp3_e_webm_sao_recusados`; usar um cabeçalho OGG/Opus montado em string no teste. `InboxVoiceTest`: `test_voz_ogg_opus_mono_vai_sem_conversao_e_com_o_tipo_ogg_opus` (upload `audio.ogg` com o cabeçalho; `deliver`; `convert === false`, `file.mimetype === 'audio/ogg; codecs=opus'`) e `test_voz_ogg_estereo_continua_com_conversao` (`convert === true`); o teste existente com WebM segue exigindo `convert === true`. `SendMediaTest`: mp3 anexado continua `convert: true`.
- [ ] **Step 2: Run** `php artisan test --filter='OggOpusTest|InboxVoiceTest|SendMediaTest'` → Expected: FAIL (classe ausente; `convert` sempre `true`).
- [ ] **Step 3: Implement** `OggOpus` e a decisão em `WahaMedia::send`, mantendo o `mimetype` original quando não é nativo.
- [ ] **Step 4: Run** a mesma linha e `vendor/bin/phpstan analyse --no-progress` → Expected: PASS / 0 erros.
- [ ] **Step 5: Commit** `feat: voz em OGG/Opus mono vai ao WAHA sem conversão`.

### Task 2: Remux WebM/Opus → OGG/Opus em JS puro

**Files:**
- Create: `resources/js/webm-to-ogg.js`, `tests/js/webm-to-ogg.test.mjs`, `tests/js/fixtures/voice-live.webm` (WebM/Opus mono de ~1 s gerado com o ffmpeg da imagem do WAHA, em modo `live`, que dá Segment e Cluster de tamanho desconhecido como o do navegador)

**Interfaces:**
- Produces: `parseWebmOpus(bytes: Uint8Array): { head: Uint8Array, packets: Uint8Array[] } | null` — `head` é o `CodecPrivate` (OpusHead); `null` se a faixa não for única `A_OPUS` mono, se houver lacing, ou se o arquivo estiver truncado antes do primeiro pacote.
- Produces: `opusPacketSamples(packet: Uint8Array): number` — amostras a 48 kHz do pacote pelo byte TOC (configuração 0-31 → 2,5/5/10/20/40/60 ms; código 0 = 1 frame, 1 e 2 = 2 frames, 3 = número de frames no segundo byte).
- Produces: `buildOgg(head: Uint8Array, packets: Uint8Array[], serial?: number): Uint8Array` — OGG com página 1 (`OpusHead`, BOS), página 2 (`OpusTags`, vendor `Recorder`, 0 comentários), páginas de áudio de até 50 pacotes ou 255 segmentos (laçada `255`), granule = pre-skip + amostras acumuladas, última página com EOS, CRC32 do Ogg (polinômio 0x04C11DB7, sem reflexão) em cada página.
- Produces: `webmToOgg(blob: Blob): Promise<Blob | null>` — `null` em qualquer falha; `audio/ogg` em caso de sucesso.

- [ ] **Step 1: Write the failing tests:** `test_parse_le_a_faixa_opus_mono_e_os_pacotes_do_webm_de_fixture` (cabeçalho começa por `OpusHead`, canais 1, mais de 20 pacotes); `test_parse_recusa_estereo_lacing_e_arquivo_truncado` (bytes adulterados); `test_opus_packet_samples_cobre_20ms_60ms_e_dois_frames` (TOC montados à mão: 960, 2880, 1920); `test_build_ogg_tem_assinatura_crc_e_granule_corretos` (começa por `OggS`, `OpusHead` em 28, canais 37, CRC de toda página confere, granule monotônico terminando em `pre-skip + soma das amostras`, EOS só na última, número de pacotes igual ao de entrada); `test_webm_to_ogg_devolve_blob_audio_ogg_ou_nulo` (fixture → `audio/ogg`; bytes lixo → `null`).
- [ ] **Step 2: Run** `node --test tests/js/webm-to-ogg.test.mjs` → Expected: FAIL (módulo ausente).
- [ ] **Step 3: Implement** o leitor EBML mínimo (IDs `0x1A45DFA3`, `0x18538067` Segment, `0x1654AE6B` Tracks, `0xAE` TrackEntry, `0x86` CodecID, `0x63A2` CodecPrivate, `0x9F` Channels, `0x1F43B675` Cluster, `0xA3` SimpleBlock, `0xA0` BlockGroup, `0xA1` Block; tamanho desconhecido = todos os bits 1) e o escritor OGG; um módulo de ~200 linhas, sem estado global.
- [ ] **Step 4: Run** `node --test tests/js` → Expected: PASS. Conferir o arquivo de saída com o ffmpeg da imagem do WAHA: `ffprobe` mostra `opus`, 1 canal, 48000 Hz, duração dentro de 40 ms da do WebM; `ffmpeg -v error -i out.ogg -f null -` sem erro.
- [ ] **Step 5: Commit** `feat: remux de WebM/Opus para OGG/Opus em JS puro`.

### Task 3: Gravador usa o remux e a configuração pesquisada

**Files:**
- Modify: `resources/js/voice-recorder.js` (constantes, `start`, `finish`)
- Test: `tests/js/voice-recorder-config.test.mjs` (se o arquivo precisar de uma função pura exportada, criar `resources/js/voice-config.js` com as constantes e `voiceFile(blob, mime)`)

**Interfaces:**
- Consumes: `webmToOgg` (Task 2).
- Produces: `VOICE_BITS_PER_SECOND = 32000`, `VOICE_CONSTRAINTS = { audio: { channelCount: { ideal: 1 }, sampleRate: { ideal: 48000 }, echoCancellation: true, noiseSuppression: true, autoGainControl: true } }` e `voiceFile(blob: Blob, mime: string): Promise<{ blob: Blob, mime: string }>` — para `mime` com `webm`, tenta `webmToOgg`; sucesso → `{ blob: oggBlob, mime: 'audio/ogg' }`; falha ou outro `mime` → o original.

- [ ] **Step 1: Write the failing tests:** `test_voice_file_troca_webm_por_ogg_quando_o_remux_funciona` (fixture), `test_voice_file_mantem_o_original_quando_o_remux_falha_ou_o_formato_e_outro` (bytes lixo em `audio/webm`; `audio/mp4`; `audio/ogg`), `test_configuracao_pede_32_kbps_mono_48k_com_processamento_ligado`.
- [ ] **Step 2: Run** `node --test tests/js` → Expected: FAIL.
- [ ] **Step 3: Implement** e ligar no `voice-recorder.js`: `getUserMedia(VOICE_CONSTRAINTS)`, `new MediaRecorder(stream, { mimeType, audioBitsPerSecond: VOICE_BITS_PER_SECOND })`, e em `finish()` (que vira `async`) aplicar `voiceFile` ao blob antes de `setPhase('preview')` (a prévia toca o arquivo que vai ser enviado); `this.mime` passa a ser o do arquivo final para `extensionFor`.
- [ ] **Step 4: Run** `node --test tests/js`, `npm run build` → Expected: PASS / exit 0. No navegador local (o gravador precisa de microfone: conferir só que a tela de gravação abre e a prévia não quebra; a verificação de ponta a ponta é a comparação da Task 4).
- [ ] **Step 5: Commit** `feat: gravador de voz remuxa para OGG/Opus e grava a 32 kbps mono`.

### Task 4: Testes de comparação no beta

**Files:**
- Create: `docs/superpowers/audit/2026-11-11-audio-waveform-comparison.md`

**Interfaces:**
- Consumes: log do WAHA do beta pela API do Coolify (receita em `.env.coolify.local` e na memória `coolify-beta-referencia`).

- [ ] **Step 1 (antes do deploy, caminho antigo):** com o beta ainda no código antigo, enviar ao contato de teste do dono 4 áudios mp3 de ~3 s anexados pelo painel (um depois de longa pausa, um logo em seguida, um depois de ~5 min parado, um logo em seguida) e ler o log do WAHA e os webhooks: registrar, por mensagem, `Failed to generate waveform` (sim/não) e `waveform` presente no `audioMessage` (sim/não).
- [ ] **Step 2 (depois do merge e do deploy, caminho novo):** repetir o mesmo padrão com 4 arquivos OGG/Opus mono anexados (a Task 1 faz o anexo OGG/Opus mono ir com `convert: false`) e registrar da mesma forma; conferir também no log que o `sendVoice` não rodou o ffmpeg de conversão nessas mensagens.
- [ ] **Step 3:** escrever a tabela comparativa e a conclusão no documento (o que mudou, o que não dá para afirmar com 4 amostras); se o braço novo ainda falhar, dizer isso.
- [ ] **Step 4: Commit** `docs: comparação da onda de voz antes e depois do OGG sem conversão`.
