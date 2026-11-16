#!/usr/bin/env bash
# Confere a imagem runtime: worker.sh, nginx -t, /up e proxy do Reverb. Uso: image-check.sh <imagem>
set -euo pipefail
export MSYS_NO_PATHCONV=1 # Git Bash no Windows converte caminhos como /usr/local/bin
IMG="${1:?uso: image-check.sh <imagem>}"
NAME="fluxie-smoke-$$"
trap 'docker rm -f "$NAME" >/dev/null 2>&1 || true' EXIT

echo "1. worker.sh executável"
docker run --rm --entrypoint test "$IMG" -x /usr/local/bin/worker.sh

echo "2. container sobe com AUTORUN_ENABLED=false e /up responde 200"
docker run -d --name "$NAME" -e AUTORUN_ENABLED=false -e APP_ENV=production \
  -e APP_KEY=base64:$(head -c 32 /dev/urandom | base64) -e DB_CONNECTION=sqlite "$IMG" >/dev/null
for _ in $(seq 1 30); do
  code=$(docker exec "$NAME" curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/up || true)
  [ "$code" = 200 ] && break
  sleep 2
done
[ "$code" = 200 ] || { echo "FALHA: /up devolveu $code"; docker logs "$NAME" | tail -20; exit 1; }

echo "3. nginx -t (nginx.conf é gerado na entrada, por isso roda no container)"
docker exec "$NAME" nginx -t

echo "4. /app/x passa pelo proxy do Reverb (502/504)"
code=$(docker exec "$NAME" curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/app/x || true)
case "$code" in 502|504) ;; *) echo "FALHA: /app/x devolveu $code"; exit 1 ;; esac

echo OK
