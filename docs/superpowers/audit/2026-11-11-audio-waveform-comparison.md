# Comparação da onda (waveform) do áudio: antes e depois do remux

Data: 11/11/2026. Beta, WAHA 2026.10.1 (GOWS). Envios para o contato de teste pelo componente real `voiceRecorder`, com MediaRecorder sintético (oscilador, mono, Opus).

| Braço | Caminho | Envios | Com onda |
|-------|---------|--------|----------|
| A (base) | WebM ~128 kbps, `convert:true` (ffmpeg + opustags do WAHA) | 6 | 2 |
| B | OGG/Opus mono 32 kbps remuxado no navegador, `convert:false` (PR #101) | 6 | 2 |

Sequência B (hora UTC): 16:58:56 NAO, 16:59:05 SIM, 16:59:12 SIM, 16:59:21 NAO, 16:59:24 NAO, 16:59:34 NAO.

## Conclusão
O remux **não resolveu** a falta de onda: 2 de 6 nos dois braços. O log de B não tem conversão do WAHA (o arquivo foi enviado como veio), e as falhas continuam sendo `Failed to generate waveform: Error: Cannot detect audio format` seguido de `not implemented` no GOWS. Arquivos idênticos (7806 bytes) deram onda num envio e não deram em outro: a falha é aleatória e interna ao WAHA (detecção de formato do `audio-decode` na geração da onda), não do arquivo nem do bitrate.

O que o PR #101 entrega mesmo assim: arquivo menor (32 kbps), sem recodificação nem ffmpeg/opustags no servidor, OGG validado no envio. Não entrega a onda.

## Caminhos abertos (não feitos)
1. Abrir issue no WAHA com o log e a amostra (falha intermitente com OGG/Opus válido).
2. Testar outra versão/engine do WAHA (NOWEB) no beta.
3. Gerar a onda no nosso lado: a API do WAHA não aceita `waveform`; só seria possível com mudança no WAHA.
