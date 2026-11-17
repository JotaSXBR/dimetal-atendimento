#!/usr/bin/env bash
# Funções para a API do Coolify. Carregar com `source`; lê COOLIFY_URL e COOLIFY_API_TOKEN. Sem efeito colateral ao carregar.
# `curl` é chamado pelo nome para o teste (tests/scripts/coolify-test.sh) poder trocá-lo.

_coolify_api() { curl -fsS --max-time 30 -H "Authorization: Bearer $COOLIFY_API_TOKEN" -H "Content-Type: application/json" "$@"; }

# Código HTTP de uma chamada com corpo JSON; o corpo da resposta vai para /dev/null.
_coolify_code() {
  local code
  code=$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $COOLIFY_API_TOKEN" \
    -H "Content-Type: application/json" "$@")
  [ "$code" != 000 ] || echo "sem conexão com o Coolify ($COOLIFY_URL)" >&2
  echo "$code"
}

# coolify_set_env <applications|services> <uuid> <key> <value>: PATCH e, se a variável não existir (404), POST.
coolify_set_env() {
  local path="$COOLIFY_URL/api/v1/$1/$2/envs" body code
  body=$(printf '{"key":"%s","value":"%s"}' "$3" "$4")
  code=$(_coolify_code -X PATCH "$path" -d "$body")
  if [ "$code" = 404 ]; then code=$(_coolify_code -X POST "$path" -d "$body"); fi
  case "$code" in
    200|201) return 0 ;;
    *) echo "Coolify recusou a variável $3 em $1/$2 (HTTP $code)"; return 1 ;;
  esac
}

# 0 = terminou; 1 = falhou ou estourou o tempo; 2 = o token não pode ler o status
_coolify_wait_finished() {
  local code status _
  for _ in $(seq 1 90); do
    code=$(curl -s --max-time 30 -o /tmp/deployment.json -w '%{http_code}' \
      -H "Authorization: Bearer $COOLIFY_API_TOKEN" "$COOLIFY_URL/api/v1/deployments/$1")
    case "$code" in
      200) ;;
      401|403) return 2 ;;
      *) sleep 20; continue ;;
    esac
    status=$(jq -r .status /tmp/deployment.json)
    case "$status" in
      finished) return 0 ;;
      failed|error|cancelled*) echo "deploy $1 terminou como $status"; return 1 ;;
    esac
    sleep 20
  done
  echo "deploy $1 não terminou a tempo"
  return 1
}

# coolify_deploy_wait <uuid[,uuid...]>: enfileira o deploy e espera cada um terminar.
coolify_deploy_wait() {
  local ids id rc
  ids=$(_coolify_api -X POST "$COOLIFY_URL/api/v1/deploy" -d "{\"uuid\":\"$1\"}" | jq -r '.deployments[].deployment_uuid')
  [ -n "$ids" ] || { echo "Coolify não enfileirou deploy para $1"; return 1; }
  for id in $ids; do
    rc=0
    _coolify_wait_finished "$id" || rc=$?
    case "$rc" in
      0) ;;
      2) echo "::warning::COOLIFY_API_TOKEN sem permissão de leitura: seguindo sem esperar o deploy $id" ;;
      *) return 1 ;;
    esac
  done
}

# Status do Service (ex.: "running:healthy"). O openapi do Coolify não documenta o campo; o GET devolve `status`.
_coolify_service_status() { _coolify_api "$COOLIFY_URL/api/v1/services/$1" | jq -r '.status // empty'; }

# coolify_restart_service <uuid>: restart com pull das imagens e espera o Service ficar saudável (até ~10 min).
# Duas fases, para não aceitar o `running:healthy` do estado anterior ao restart:
#  1) até 60 s esperando o status sair de running*; se nunca sair (Coolify recriou rápido demais para a sondagem),
#     segue com ::warning:: para a fase 2;
#  2) espera running:healthy.
coolify_restart_service() {
  local status="" _
  _coolify_api -X POST "$COOLIFY_URL/api/v1/services/$1/restart?latest=true" >/dev/null || return 1
  for _ in $(seq 1 6); do
    status=$(_coolify_service_status "$1") || status=""
    case "$status" in running*) sleep 10 ;; *) break ;; esac
  done
  case "$status" in running*) echo "::warning::Service $1 não saiu de running após o restart: pode não ter sido recriado; conferindo a saúde mesmo assim" ;; esac
  for _ in $(seq 1 30); do
    status=$(_coolify_service_status "$1") || status=""
    case "$status" in running:healthy) return 0 ;; esac
    sleep 20
  done
  echo "Service $1 não ficou saudável em 10 min (último status: ${status:-desconhecido})"
  return 1
}

# release_tags vX.Y.Z -> "X.Y.Z X.Y"; qualquer outro formato sai com 1.
release_tags() {
  [[ "$1" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)$ ]] || { echo "tag fora do padrão vX.Y.Z: $1" >&2; return 1; }
  echo "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.${BASH_REMATCH[3]} ${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"
}
