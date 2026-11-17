# Funções das fumaças do compose (compose-smoke.sh e upgrade-smoke.sh). Uso: `source`, depois de definir SMOKE_PROJECT e
# SMOKE_FILES (as opções -f do compose). Gera o .env do Coolify e derruba tudo (down -v) na saída, mesmo em falha.
export MSYS_NO_PATHCONV=1 # Git Bash no Windows converte caminhos como /up
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
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
SERVICE_PASSWORD_SETUP=$(hex 8)
SERVICE_HEX_32_REVERBKEY=$(hex 16)
SERVICE_HEX_32_REVERBID=$(hex 16)
SERVICE_PASSWORD_REVERBSECRET=$(hex 16)
ENV
dc() { docker compose -p "$SMOKE_PROJECT" "${SMOKE_FILES[@]}" --env-file "$(cygpath -m "$ENVF" 2>/dev/null || echo "$ENVF")" "$@"; }
trap 'dc down -v --remove-orphans >/dev/null 2>&1 || true; rm -f "$ENVF"' EXIT
fail() { echo "FALHA: $*"; exit 1; }
status() { docker inspect -f '{{.State.Health.Status}}' "$(dc ps -q "$1")" 2>/dev/null || echo none; }
wait_for() { # <serviço> <estado> <segundos>
  for _ in $(seq 1 "$(($3 / 3))"); do [ "$(status "$1")" = "$2" ] && return 0; sleep 3; done
  return 1
}
