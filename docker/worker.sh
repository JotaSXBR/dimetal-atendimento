#!/bin/bash
# Dois processos de fila no mesmo container: `outbound` (um só, para as mensagens de cada conversa saírem na ordem; o
# envio humanizado espera segundos) e `default` (ingestão, acks, tempo real; nunca fica preso nessa espera). Se um
# morrer, o outro é encerrado e o container sai, para o Coolify reiniciar os dois juntos.
artisan=/var/www/html/artisan

php "$artisan" queue:work --queue=outbound --tries=1 --timeout=120 --max-time=3600 &
outbound=$!
php "$artisan" queue:work --queue=default --tries=3 --timeout=120 --max-time=3600 &
default=$!

trap 'kill -TERM "$outbound" "$default" 2>/dev/null' TERM INT

wait -n
status=$?

kill -TERM "$outbound" "$default" 2>/dev/null
wait
exit "$status"
