#!/usr/bin/env bash
# Teste sem rede de .github/scripts/coolify.sh: `curl` é trocado por uma função falsa com códigos programados.
cd "$(dirname "$0")/../.." || exit 1
fail=0
check() { if [ "$2" = "$3" ]; then :; else echo "FALHA: $1 (esperado '$2', veio '$3')"; fail=1; fi; }

source .github/scripts/coolify.sh || { echo "FALHA: coolify.sh não carrega"; exit 1; }
export COOLIFY_URL=https://coolify.test COOLIFY_API_TOKEN=segredo

# PATCH_CODE/POST_CODE programam a resposta; $LOG registra "|METODO caminho" e $BODIES o -d de cada chamada, uma por linha
# (arquivos, pois curl roda em subshell). GET de /services/abc devolve $SERVICE_JSON, de /services/abc/envs $ENVS_JSON;
# FAIL_CALL="METODO caminho" faz essa chamada sair 22, como o `curl -f` num HTTP de erro.
LOG=$(mktemp); BODIES=$(mktemp); WORK=$(mktemp -d); trap 'rm -rf "$LOG" "$BODIES" "$WORK"' EXIT

curl() {
  local method=GET url="" out="" data=""
  while [ $# -gt 0 ]; do
    case "$1" in
      -X) method=$2; shift ;;
      -o) out=$2; shift ;;
      -d) data=$2; shift ;;
      http*) url=$1 ;;
    esac
    shift
  done
  local call="$method ${url#"$COOLIFY_URL"}"
  echo -n "|$call" >> "$LOG"
  [ -n "$data" ] && echo "$data" >> "$BODIES"
  [ "$call" = "$FAIL_CALL" ] && return 22
  [ -n "$out" ] && echo '{}' > "$out"
  case "$call" in
    "GET /api/v1/services/abc") echo -n "$SERVICE_JSON" ;;
    "GET /api/v1/services/abc/envs") echo -n "$ENVS_JSON" ;;
    "PATCH /api/v1/services/abc/envs") echo -n "${ENV_PATCH_CODE:-$PATCH_CODE}" ;;
    PATCH*) echo -n "$PATCH_CODE" ;;
    POST*) echo -n "$POST_CODE" ;;
  esac
}
reset_calls() { : > "$LOG"; : > "$BODIES"; }

check "release_tags v1.2.3" "1.2.3 1.2" "$(release_tags v1.2.3)"
check "release_tags v0.0.0-teste.1 (pré-release sem apelido X.Y)" "0.0.0-teste.1" "$(release_tags v0.0.0-teste.1)"
check "release_tags v1.2.3-rc1" "1.2.3-rc1" "$(release_tags v1.2.3-rc1)"
for t in v1.2 1.2.3 v01.2.3 v1.02.3 v1.2.3- v1.2.3-a..b v1.2.3-01 v1.2.3-a_b v1.2.3+build; do
  rc=0; release_tags "$t" >/dev/null 2>&1 || rc=$?
  check "release_tags $t sai 1" 1 "$rc"
done

# Compose de verdade tem várias linhas, `$`, aspas e acento; a ida e volta precisa ser byte a byte.
printf 'x-fluxie-image: &fluxie-image ghcr.io/fluxie-tech/fluxie-inbox:${FLUXIE_VERSION:-0.1.0}\nservices:\n  app:\n    image: *fluxie-image\n    command: "echo \\"olá\\""\n' > "$WORK/antigo.yml"
printf 'services:\n  app:\n    image: ghcr.io/fluxie-tech/fluxie-inbox:${FLUXIE_VERSION:-0.2.0}\n' > "$WORK/novo.yml"
SERVICE_JSON=$(jq -n --rawfile c "$WORK/antigo.yml" '{uuid: "abc", status: "running:healthy", docker_compose_raw: $c}')
decoded() { sed -n "${1}p" "$BODIES" | jq -r .docker_compose_raw | base64 -d; }

reset_calls; rc=0
coolify_get_compose abc "$WORK/lido.yml" >/dev/null 2>&1 || rc=$?
check "get_compose (rc)" 0 "$rc"
check "get_compose (chamadas)" "|GET /api/v1/services/abc" "$(cat "$LOG")"
cmp -s "$WORK/antigo.yml" "$WORK/lido.yml" || { echo "FALHA: get_compose não gravou o compose byte a byte"; fail=1; }

SERVICE_JSON='{"uuid":"abc","status":"running:healthy"}' rc=0
coolify_get_compose abc "$WORK/lido.yml" >/dev/null 2>&1 || rc=$?
check "get_compose sem docker_compose_raw (sem read:sensitive) falha" 1 "$([ "$rc" -ne 0 ] && echo 1 || echo 0)"

FAIL_CALL="GET /api/v1/services/abc" rc=0
coolify_get_compose abc "$WORK/lido.yml" >/dev/null 2>&1 || rc=$?
check "get_compose com GET recusado falha" 1 "$([ "$rc" -ne 0 ] && echo 1 || echo 0)"
FAIL_CALL=""
SERVICE_JSON=$(jq -n --rawfile c "$WORK/antigo.yml" '{uuid: "abc", status: "running:healthy", docker_compose_raw: $c}')

PATCH_CODE=200; reset_calls; rc=0
coolify_put_compose abc "$WORK/antigo.yml" >/dev/null 2>&1 || rc=$?
check "put_compose (rc)" 0 "$rc"
check "put_compose (chamadas)" "|PATCH /api/v1/services/abc" "$(cat "$LOG")"
decoded 1 > "$WORK/enviado.yml"
cmp -s "$WORK/antigo.yml" "$WORK/enviado.yml" || { echo "FALHA: put_compose não mandou o compose em base64 byte a byte"; fail=1; }

PATCH_CODE=500 rc=0
coolify_put_compose abc "$WORK/antigo.yml" >/dev/null 2>&1 || rc=$?
check "put_compose com PATCH 500 falha" 1 "$([ "$rc" -ne 0 ] && echo 1 || echo 0)"

ENVS_JSON='[{"key":"FLUXIE_VERSION","value":"9.9.9","is_preview":true},{"key":"APP_KEY","value":"x","is_preview":false},{"key":"FLUXIE_VERSION","value":"0.2.0","is_preview":false}]'
check "get_env ignora a duplicata de preview" "0.2.0" "$(coolify_get_env services abc FLUXIE_VERSION)"
ENVS_JSON='[{"key":"APP_KEY","value":"x","is_preview":false}]' rc=0
OUT=$(coolify_get_env services abc FLUXIE_VERSION) || rc=$?
check "get_env ausente sai vazio (rc)" 0 "$rc"
check "get_env ausente sai vazio" "" "$OUT"
FAIL_CALL="GET /api/v1/services/abc/envs" rc=0
coolify_get_env services abc FLUXIE_VERSION >/dev/null 2>&1 || rc=$?
check "get_env com GET recusado falha" 1 "$([ "$rc" -ne 0 ] && echo 1 || echo 0)"
FAIL_CALL=""

PATCH_CODE=200; reset_calls
coolify_set_env services abc FLUXIE_VERSION 1.2.3 >/dev/null 2>&1
coolify_set_env applications abc FLUXIE_VERSION sha-1 >/dev/null 2>&1
check "set_env services manda is_preview false" '{"key":"FLUXIE_VERSION","value":"1.2.3","is_preview":false}' "$(sed -n 1p "$BODIES")"
check "set_env applications mantém o corpo" '{"key":"FLUXIE_VERSION","value":"sha-1"}' "$(sed -n 2p "$BODIES")"
coolify_set_env applications abc NOME 'a"b\c' >/dev/null 2>&1
check "set_env escapa o valor" 'a"b\c' "$(sed -n 3p "$BODIES" | jq -r .value)"

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
IDX=$(mktemp); trap 'rm -rf "$LOG" "$BODIES" "$WORK" "$IDX"' EXIT
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

# coolify_update_service roda como no Actions (bash -eo pipefail, sem `||` em volta), num subshell.
run_update() {
  echo 0 > "$IDX"; SEQ=$1; reset_calls
  ( set -eo pipefail; coolify_update_service abc "$WORK/novo.yml" 0.2.0 > "$WORK/saida" 2>&1 ); rc=$?
  OUT=$(cat "$WORK/saida")
}
GUARDA="|GET /api/v1/services/abc|GET /api/v1/services/abc/envs"
APLICA="|PATCH /api/v1/services/abc|PATCH /api/v1/services/abc/envs|POST /api/v1/services/abc/restart?latest=true"
DEVOLVE="|PATCH /api/v1/services/abc|PATCH /api/v1/services/abc/envs"
VOLTA="|POST /api/v1/services/abc/restart"
# 31 leituras sem saúde esgotam o primeiro restart (1 na fase 1, 30 na fase 2); depois o Service volta saudável.
FALHA_E_VOLTA="$(for _ in $(seq 31); do echo exited; done)
running:healthy"
PATCH_CODE=200 ENVS_JSON='[{"key":"APP_KEY","value":"x","is_preview":false}]'

run_update "restarting
running:healthy"
check "update_service (rc)" 0 "$rc"
check "update_service (chamadas)" "$GUARDA$APLICA" "$(cat "$LOG")"
decoded 1 > "$WORK/enviado.yml"
cmp -s "$WORK/novo.yml" "$WORK/enviado.yml" || { echo "FALHA: update_service não gravou o compose novo"; fail=1; }
check "update_service grava a versão" '{"key":"FLUXIE_VERSION","value":"0.2.0","is_preview":false}' "$(sed -n 2p "$BODIES")"

run_update "$FALHA_E_VOLTA"
check "update_service com restart falho sai 1" 1 "$([ "$rc" -ne 0 ] && echo 1 || echo 0)"
check "update_service com restart falho devolve e reinicia sem pull (chamadas)" "$GUARDA$APLICA$DEVOLVE$VOLTA" "$(cat "$LOG")"
decoded 3 > "$WORK/devolvido.yml"
cmp -s "$WORK/antigo.yml" "$WORK/devolvido.yml" || { echo "FALHA: a devolução não regravou o compose guardado"; fail=1; }
check "devolução sem FLUXIE_VERSION gravada volta para 0.1.0" '{"key":"FLUXIE_VERSION","value":"0.1.0","is_preview":false}' "$(sed -n 4p "$BODIES")"
case "$OUT" in *"::error::"*"reiniciado na versão anterior"*) ;; *) echo "FALHA: faltou dizer que voltou à versão anterior (veio '$OUT')"; fail=1 ;; esac

run_update "exited"
check "segundo restart falho sai 1" 1 "$([ "$rc" -ne 0 ] && echo 1 || echo 0)"
check "segundo restart falho (chamadas)" "$GUARDA$APLICA$DEVOLVE$VOLTA" "$(cat "$LOG")"
case "$OUT" in *"::error::"*"atenção manual no Coolify"*"docs/deploy.md"*) ;; *) echo "FALHA: segundo restart falho sem pedir atenção manual (veio '$OUT')"; fail=1 ;; esac

ENVS_JSON='[{"key":"FLUXIE_VERSION","value":"0.1.5","is_preview":false}]'
run_update "exited"
check "devolução volta para a FLUXIE_VERSION guardada" '{"key":"FLUXIE_VERSION","value":"0.1.5","is_preview":false}' "$(sed -n 4p "$BODIES")"
ENVS_JSON='[{"key":"APP_KEY","value":"x","is_preview":false}]'

ENV_PATCH_CODE=500; run_update "running:healthy"
check "update_service com a versão recusada sai 1" 1 "$([ "$rc" -ne 0 ] && echo 1 || echo 0)"
check "update_service com a versão recusada devolve sem reiniciar" "$GUARDA|PATCH /api/v1/services/abc|PATCH /api/v1/services/abc/envs$DEVOLVE" "$(cat "$LOG")"
ENV_PATCH_CODE=""

SERVICE_JSON='{"uuid":"abc","status":"running:healthy"}'
run_update "running:healthy"
check "update_service sem compose legível sai 1" 1 "$([ "$rc" -ne 0 ] && echo 1 || echo 0)"
check "update_service sem compose legível não grava nada" "|GET /api/v1/services/abc" "$(cat "$LOG")"
SERVICE_JSON=$(jq -n --rawfile c "$WORK/antigo.yml" '{uuid: "abc", status: "running:healthy", docker_compose_raw: $c}')

FAIL_CALL="GET /api/v1/services/abc/envs"
run_update "running:healthy"
check "update_service sem ler as variáveis sai 1" 1 "$([ "$rc" -ne 0 ] && echo 1 || echo 0)"
check "update_service sem ler as variáveis não grava nada" "$GUARDA" "$(cat "$LOG")"
FAIL_CALL=""

[ "$fail" = 0 ] && echo OK
exit "$fail"
