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
  local path="$COOLIFY_URL/api/v1/$1/$2/envs" body code extra='{}'
  # No Service há duplicata de preview; a de produção é is_preview false.
  case "$1" in services) extra='{"is_preview":false}' ;; esac
  body=$(jq -cn --arg k "$3" --arg v "$4" --argjson e "$extra" '{key: $k, value: $v} + $e') || return 1
  code=$(_coolify_code -X PATCH "$path" -d "$body")
  if [ "$code" = 404 ]; then code=$(_coolify_code -X POST "$path" -d "$body"); fi
  case "$code" in
    200|201) return 0 ;;
    *) echo "Coolify recusou a variável $3 em $1/$2 (HTTP $code)"; return 1 ;;
  esac
}

# Status do Service (ex.: "running:healthy"). O openapi do Coolify não documenta o campo; o GET devolve `status`.
_coolify_service_status() { _coolify_api "$COOLIFY_URL/api/v1/services/$1" | jq -r '.status // empty'; }

# coolify_restart_service <uuid> [query]: restart (padrão `?latest=true`, com pull das imagens; "" reinicia sem pull) e espera o
# Service ficar saudável (até ~11 min). Duas fases, para não aceitar o `running:healthy` do estado anterior ao restart:
#  1) até 60 s esperando o status sair de running*; se nunca sair (Coolify recriou rápido demais para a sondagem),
#     segue com ::warning:: para a fase 2;
#  2) espera running:healthy.
coolify_restart_service() {
  local status="" _
  _coolify_api -X POST "$COOLIFY_URL/api/v1/services/$1/restart${2-?latest=true}" >/dev/null || return 1
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

# coolify_get_env <applications|services> <uuid> <key>: valor da variável (a de is_preview false); ausente = saída vazia.
coolify_get_env() {
  local body
  body=$(_coolify_api "$COOLIFY_URL/api/v1/$1/$2/envs") || return 1
  jq -r --arg k "$3" '[.[] | select(.key == $k and .is_preview != true)][0].value // empty' <<< "$body"
}

# coolify_get_compose <uuid> <arquivo>: grava o docker_compose_raw do Service byte a byte. Sem o campo (token sem read:sensitive) sai 1.
coolify_get_compose() {
  local body
  body=$(_coolify_api "$COOLIFY_URL/api/v1/services/$1") || return 1
  jq -e -j '.docker_compose_raw | select(type == "string" and . != "")' <<< "$body" > "$2" ||
    { echo "Coolify não devolveu o docker_compose_raw de $1 (o token tem read:sensitive?)"; return 1; }
}

# coolify_put_compose <uuid> <arquivo>: grava o compose do Service (a API pede base64).
coolify_put_compose() {
  local body code
  body=$(jq -cRs '{docker_compose_raw: @base64}' < "$2") || return 1
  code=$(_coolify_code -X PATCH "$COOLIFY_URL/api/v1/services/$1" -d "$body")
  case "$code" in
    200|201) return 0 ;;
    *) echo "Coolify recusou o compose de services/$1 (HTTP $code)"; return 1 ;;
  esac
}

# coolify_update_service <uuid> <compose> <versão>: guarda compose e FLUXIE_VERSION atuais, grava os novos e reinicia com pull.
# Se gravar ou reiniciar falhar, regrava os guardados (nada é apagado), reinicia sem pull na versão anterior (o caminho de volta
# testado) e sai 1 com ::error:: dizendo onde parou.
coolify_update_service() {
  local saved old_version rc=0
  saved=$(mktemp) || return 1
  coolify_get_compose "$1" "$saved" || { rm -f "$saved"; echo "::error::Não deu para guardar o compose atual de $1: nada foi alterado"; return 1; }
  old_version=$(coolify_get_env services "$1" FLUXIE_VERSION) ||
    { rm -f "$saved"; echo "::error::Não deu para ler FLUXIE_VERSION de $1: nada foi alterado"; return 1; }
  # A production está na 0.1.0 sem FLUXIE_VERSION gravada (o compose da 0.1.0 cai no padrão 0.1.0).
  old_version=${old_version:-0.1.0}
  if coolify_put_compose "$1" "$2" && coolify_set_env services "$1" FLUXIE_VERSION "$3" && coolify_restart_service "$1"; then
    rm -f "$saved"
    return 0
  fi
  echo "::error::Atualização de $1 para $3 falhou: devolvendo o compose guardado e FLUXIE_VERSION=$old_version"
  coolify_put_compose "$1" "$saved" || rc=1
  coolify_set_env services "$1" FLUXIE_VERSION "$old_version" || rc=1
  rm -f "$saved"
  local manual="o Service $1 precisa de atenção manual no Coolify (recuperação em docs/deploy.md, seção Atualizar, Rollback)"
  if [ "$rc" != 0 ]; then
    echo "::error::A devolução do compose ou de FLUXIE_VERSION=$old_version falhou: $manual"
  elif coolify_restart_service "$1" ""; then
    echo "::error::Compose e FLUXIE_VERSION=$old_version restaurados; Service $1 reiniciado na versão anterior."
  else
    echo "::error::Compose e FLUXIE_VERSION=$old_version restaurados, mas o reinício na versão anterior também falhou: $manual"
  fi
  return 1
}

# release_tags vX.Y.Z -> "X.Y.Z X.Y"; vX.Y.Z-pre -> "X.Y.Z-pre" (o apelido X.Y é só de versão estável). Mesma regra do
# App\Services\Update\Version, sem o +build (tag de imagem não aceita `+`). Qualquer outro formato sai com 1.
release_tags() {
  local n='(0|[1-9][0-9]*)' id='(0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)'
  [[ "$1" =~ ^v$n\.$n\.$n(-$id(\.$id)*)?$ ]] || { echo "tag fora do padrão vX.Y.Z[-pré-release]: $1" >&2; return 1; }
  local core="${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.${BASH_REMATCH[3]}"
  if [ -n "${BASH_REMATCH[4]}" ]; then echo "$core${BASH_REMATCH[4]}"; else echo "$core ${BASH_REMATCH[1]}.${BASH_REMATCH[2]}"; fi
}
