#!/bin/sh
# Cria os buckets de mídia (app:prepare-media) depois das automações do Laravel, só no container que roda AUTORUN.
# O armazenamento pode demorar a ficar de pé: 20 tentativas a cada 3 s; se falhar, o container cai e o Coolify reinicia.
[ "${AUTORUN_ENABLED:-false}" = "true" ] || exit 0

for i in $(seq 1 20); do
    timeout 5 php /var/www/html/artisan app:prepare-media && exit 0
    echo "app:prepare-media falhou (tentativa $i/20), nova tentativa em 3 s"
    sleep 3
done
exit 1
