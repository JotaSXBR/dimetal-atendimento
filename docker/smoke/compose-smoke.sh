#!/usr/bin/env bash
# Fumaça do docker-compose.yml: sobe app, worker, scheduler e reverb e confere o que o Coolify vai depender.
# Uso: compose-smoke.sh <imagem-local>. Derruba tudo no fim (down -v), mesmo em falha.
set -euo pipefail
export MSYS_NO_PATHCONV=1 # Git Bash no Windows converte caminhos como /up
IMG="${1:?uso: compose-smoke.sh <imagem-local>}"
cd "$(dirname "$0")/../.."
export FLUXIE_VERSION=smoke
docker tag "$IMG" ghcr.io/fluxie-tech/fluxie-inbox:smoke
# Valores aleatórios a cada execução, no formato que o Coolify gera; nada parecido com segredo fica versionado.
ENVF=$(mktemp)
hex() { openssl rand -hex "$1"; }
cat >"$ENVF" <<ENV
SERVICE_URL_APP=http://localhost:8080
SERVICE_REALBASE64_32_APPKEY=$(openssl rand -base64 32)
SERVICE_PASSWORD_POSTGRES=$(hex 16)
SERVICE_PASSWORD_REDIS=$(hex 16)
SERVICE_USER_RUSTFS=u$(hex 6)
SERVICE_PASSWORD_RUSTFS=$(hex 16)
SERVICE_PASSWORD_WAHA=$(hex 16)
SERVICE_HEX_32_REVERBKEY=$(hex 16)
SERVICE_HEX_32_REVERBID=$(hex 16)
SERVICE_PASSWORD_REVERBSECRET=$(hex 16)
ENV
dc() { docker compose -p fluxie-smoke -f docker-compose.yml --env-file "$(cygpath -m "$ENVF" 2>/dev/null || echo "$ENVF")" "$@"; }
trap 'dc down -v --remove-orphans >/dev/null 2>&1 || true; rm -f "$ENVF"' EXIT
fail() { echo "FALHA: $*"; exit 1; }
status() { docker inspect -f '{{.State.Health.Status}}' "$(dc ps -q "$1")" 2>/dev/null || echo none; }
wait_for() { # <serviço> <estado> <segundos>
  for _ in $(seq 1 "$(($3 / 3))"); do [ "$(status "$1")" = "$2" ] && return 0; sleep 3; done
  return 1
}

echo "1. compose config válido"
dc config -q

echo "2. app, worker, scheduler, reverb e waha saudáveis (até 180 s)"
dc up -d --pull missing app worker scheduler reverb waha
for s in app worker scheduler reverb waha; do
  wait_for "$s" healthy 180 || { dc logs "$s" | tail -30; fail "$s não ficou healthy"; }
done

echo "3. /up e /login devolvem 200"
for p in /up /login; do
  code=$(dc exec -T app curl -s -o /dev/null -w '%{http_code}' "http://localhost:8080$p")
  [ "$code" = 200 ] || fail "$p devolveu $code"
done

echo "4. websocket do Reverb em /app devolve 101"
key=$(grep '^SERVICE_HEX_32_REVERBKEY=' "$ENVF" | cut -d= -f2)
wskey=$(openssl rand -base64 16)
# curl devolve 28 (timeout) depois do 101, porque a conexão fica aberta
for _ in 1 2 3; do # o Reverb pode estar healthy e ainda recusar a primeira conexão
  code=$(dc exec -T app curl -s -o /dev/null -w '%{http_code}' --max-time 5     -H 'Connection: Upgrade' -H 'Upgrade: websocket' -H 'Sec-WebSocket-Version: 13'     -H "Sec-WebSocket-Key: $wskey" "http://localhost:8080/app/$key?protocol=7" || true)
  [ "$code" = 101 ] && break
  sleep 2
done
[ "$code" = 101 ] || fail "websocket devolveu $code"

echo "4b. a chave do WAHA é a mesma nos dois lados e o WAHA alcança o webhook do app"
# a chave vem do ambiente do próprio container do app, o que prova que o app a recebeu
code=$(dc exec -T app sh -c 'curl -s -o /dev/null -w "%{http_code}" -H "X-Api-Key: $WAHA_API_KEY" http://waha:3000/api/sessions')
[ "$code" = 200 ] || fail "waha com a chave certa devolveu $code"
code=$(dc exec -T app sh -c 'curl -s -o /dev/null -w "%{http_code}" -H "X-Api-Key: errada-$WAHA_API_KEY" http://waha:3000/api/sessions')
[ "$code" = 401 ] || fail "waha com chave errada devolveu $code"
code=$(dc exec -T waha curl -s -o /dev/null -w '%{http_code}' http://app:8080/up)
[ "$code" = 200 ] || fail "waha não alcançou app:8080/up ($code)"

echo "5. buckets criados pela entrada do app"
out=$(dc exec -T app php artisan app:prepare-media)
echo "$out"
[ "$(echo "$out" | grep -c 'já existe')" = 2 ] || fail "os dois buckets deviam existir"

echo "6. sem migração pendente e sem migrar fora do app"
ms=$(dc exec -T worker php artisan migrate:status) || fail "migrate:status falhou"
! grep -q Pending <<<"$ms" || fail "migração pendente"
for s in worker scheduler reverb; do
  # nunca `dc logs | grep -q` com pipefail: o grep fecha o pipe, o logs leva SIGPIPE e o resultado se inverte
  slogs=$(dc logs "$s" 2>&1)
  ! grep -q 'Running migrations' <<<"$slogs" || fail "$s rodou migração"
done

echo "7. imagens de terceiros com versão fixa"
cfg=$(dc config)
for i in devlikeapro/waha rustfs/rustfs postgres redis; do
  grep -Eq "image: $i:" <<<"$cfg" || fail "imagem $i ausente"
done
! grep -E 'image: .*:latest' <<<"$cfg" || fail "tag latest"

echo "8. RustFS fora: o app não sobe sem buckets e volta quando o RustFS volta"
dc stop rustfs
since=$(date -u +%Y-%m-%dT%H:%M:%SZ)
dc restart app
wait_for app healthy 15 && fail "app ficou healthy sem o RustFS"
# 20 tentativas de no máximo 5 s mais 3 s de pausa (~160 s); depois o script de entrada sai com 1
for _ in $(seq 1 70); do
  [ "$(docker inspect -f '{{.State.Status}}' "$(dc ps -aq app)")" = exited ] && break
  sleep 3
done
# só os logs depois do restart: a subida normal já imprimiu "prepare-media"
logs8=$(dc logs --since "$since" app 2>&1)
grep -q 'app:prepare-media falhou' <<<"$logs8" || {
  echo "estado: $(docker inspect -f '{{.State.Status}} exit={{.State.ExitCode}}' "$(dc ps -aq app)")"
  tail -60 <<<"$logs8"
  fail "falha do script de entrada não registrada"
}
[ "$(docker inspect -f '{{.State.ExitCode}}' "$(dc ps -aq app)")" = 1 ] || fail "app devia ter saído com código 1"
dc start rustfs
dc restart app
wait_for app healthy 180 || { dc logs app | tail -30; fail "app não voltou a healthy"; }

echo OK
