#!/bin/bash
# Quatro processos de fila no mesmo container, um por tipo de trabalho, para um lento não segurar os outros:
#   outbound  envio ao cliente. UM só: as mensagens de cada conversa saem na ordem, e o envio humanizado espera segundos.
#   inbound   ingestão do webhook do WAHA e download de mídia (um download demorado não atrasa o envio nem a tela).
#   realtime  avisos de tela (Reverb) e, por rede de segurança, a fila `default`, onde cai o que não escolheu fila.
#   google    contatos para o Google: o People API pede escrita sequencial e pode demorar ou limitar (429).
# Se um processo morrer, os outros são encerrados e o container sai, para o Coolify reiniciar todos juntos.
artisan=/var/www/html/artisan
pids=()

start() {
    php "$artisan" queue:work "$@" --timeout=120 --max-time=3600 &
    pids+=($!)
}

start --queue=outbound --tries=1
start --queue=inbound --tries=3
start --queue=realtime,default --tries=3
start --queue=google --tries=1

trap 'kill -TERM "${pids[@]}" 2>/dev/null' TERM INT

wait -n
status=$?

kill -TERM "${pids[@]}" 2>/dev/null
wait
exit "$status"
