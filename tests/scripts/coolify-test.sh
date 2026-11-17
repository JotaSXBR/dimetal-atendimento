#!/usr/bin/env bash
# Teste sem rede de .github/scripts/coolify.sh: `curl` é trocado por uma função falsa com códigos programados.
cd "$(dirname "$0")/../.." || exit 1
fail=0
check() { if [ "$2" = "$3" ]; then :; else echo "FALHA: $1 (esperado '$2', veio '$3')"; fail=1; fi; }

source .github/scripts/coolify.sh || { echo "FALHA: coolify.sh não carrega"; exit 1; }
export COOLIFY_URL=https://coolify.test COOLIFY_API_TOKEN=segredo

# PATCH_CODE/POST_CODE programam a resposta; $LOG registra "|METODO caminho" (arquivo, pois curl roda em subshell).
LOG=$(mktemp); trap 'rm -f "$LOG"' EXIT

curl() {
  local method=GET url="" out=""
  while [ $# -gt 0 ]; do
    case "$1" in
      -X) method=$2; shift ;;
      -o) out=$2; shift ;;
      http*) url=$1 ;;
    esac
    shift
  done
  echo -n "|$method ${url#"$COOLIFY_URL"}" >> "$LOG"
  [ -n "$out" ] && echo '{}' > "$out"
  case "$method" in PATCH) echo -n "$PATCH_CODE" ;; POST) echo -n "$POST_CODE" ;; esac
}

check "release_tags v1.2.3" "1.2.3 1.2" "$(release_tags v1.2.3)"
for t in v1.2 1.2.3 v1.2.3-rc1; do
  rc=0; release_tags "$t" >/dev/null 2>&1 || rc=$?
  check "release_tags $t sai 1" 1 "$rc"
done

PATCH_CODE=404 POST_CODE=201 rc=0; : > "$LOG"
coolify_set_env services abc FLUXIE_VERSION 1.2.3 >/dev/null 2>&1 || rc=$?
check "PATCH 404 faz POST (rc)" 0 "$rc"
check "PATCH 404 faz POST (chamadas)" "|PATCH /api/v1/services/abc/envs|POST /api/v1/services/abc/envs" "$(cat "$LOG")"

PATCH_CODE=200 rc=0; : > "$LOG"
coolify_set_env applications abc FLUXIE_VERSION sha-1 >/dev/null 2>&1 || rc=$?
check "PATCH 200 não faz POST (rc)" 0 "$rc"
check "PATCH 200 não faz POST (chamadas)" "|PATCH /api/v1/applications/abc/envs" "$(cat "$LOG")"

PATCH_CODE=500 rc=0; : > "$LOG"
coolify_set_env services abc FLUXIE_VERSION 1.2.3 >/dev/null 2>&1 || rc=$?
check "PATCH 500 falha" 1 "$([ "$rc" -ne 0 ] && echo 1 || echo 0)"

PATCH_CODE=404 POST_CODE=500; rc=0
coolify_set_env services abc FLUXIE_VERSION 1.2.3 >/dev/null 2>&1 || rc=$?
check "POST 500 falha" 1 "$([ "$rc" -ne 0 ] && echo 1 || echo 0)"

PATCH_CODE=000; rc=0
ERR=$(coolify_set_env services abc FLUXIE_VERSION 1.2.3 2>&1 >/dev/null) || rc=$?
check "sem conexão (000) falha" 1 "$([ "$rc" -ne 0 ] && echo 1 || echo 0)"
case "$ERR" in *"sem conexão com o Coolify ($COOLIFY_URL)"*) ;; *) echo "FALHA: faltou a mensagem de 000 (veio '$ERR')"; fail=1 ;; esac
case "$ERR" in *"$COOLIFY_API_TOKEN"*) echo "FALHA: token vazou na saída"; fail=1 ;; esac

# restart: sleep falso e status programado em $SEQ (um por linha; o último se repete), lido de um arquivo contador.
sleep() { :; }
IDX=$(mktemp); trap 'rm -f "$LOG" "$IDX"' EXIT
_coolify_service_status() {
  local n; n=$(($(cat "$IDX") + 1)); echo "$n" > "$IDX"
  echo "$SEQ" | sed -n "${n}p" | grep . || echo "$SEQ" | tail -n 1
}
run_restart() { echo 0 > "$IDX"; SEQ=$1; rc=0; OUT=$(coolify_restart_service abc 2>&1) || rc=$?; }

run_restart "running:healthy"
check "restart sem ver a saída segue com aviso (rc)" 0 "$rc"
case "$OUT" in *"::warning::"*) ;; *) echo "FALHA: restart sem ver a saída deveria avisar"; fail=1 ;; esac

run_restart "running:healthy
restarting
exited
running:healthy"
check "restart com saída e volta saudável (rc)" 0 "$rc"
case "$OUT" in *"::warning::"*) echo "FALHA: não deveria avisar"; fail=1 ;; esac

run_restart "exited"
check "restart que nunca fica saudável falha" 1 "$rc"

[ "$fail" = 0 ] && echo OK
exit "$fail"
