#!/usr/bin/env bash
# Fumaça de atualização (regra do compose): sobe o docker-compose.yml da Release anterior com a imagem dela, cria dados, troca
# para o compose da raiz com FLUXIE_VERSION = imagem candidata (o que o botão Atualizar faz no Coolify, com o mesmo .env) e
# confere migrações, /up, /login sem cair no /instalar e os dados.
# Uso: upgrade-smoke.sh <imagem-candidata-local> [--from vX.Y.Z]. Sem --from, a última Release estável. Derruba tudo no fim.
set -euo pipefail
usage() { echo "uso: upgrade-smoke.sh <imagem-candidata-local> [--from vX.Y.Z]"; exit 2; }
IMG="${1:-}"
[ -n "$IMG" ] || usage
FROM=
case "$#:${2:-}" in
  1:) ;;
  3:--from) FROM=$3; [ -n "$FROM" ] || usage ;;
  *) usage ;;
esac
REPO=fluxie-tech/fluxie-inbox
if [ -z "$FROM" ]; then
  # releases/latest redireciona para a tag da última Release estável; sem API nem token. Sem nenhuma, fica em /releases.
  url=$(curl -fsSIL -o /dev/null -w '%{url_effective}' "https://github.com/$REPO/releases/latest") ||
    { echo "FALHA: não consegui consultar a última Release de $REPO"; exit 1; }
  [[ "$url" == */releases/tag/* ]] || { echo "FALHA: $REPO não tem Release estável publicada ($url)"; exit 1; }
  FROM=${url##*/}
fi
url="https://github.com/$REPO/releases/download/$FROM/docker-compose.yml"
OLD_COMPOSE=$(curl -fsSL "$url") || { echo "FALHA: não baixei o docker-compose.yml da Release $FROM ($url)"; exit 1; }
SMOKE_PROJECT=fluxie-upgrade-smoke
OLD=$(mktemp)
SMOKE_FILES=(-f "$(cygpath -m "$OLD" 2>/dev/null || echo "$OLD")")
source "$(dirname "$0")/lib.sh"
# A versão é a etiqueta da candidata (sha-… no CI); o compose puxa ghcr.io/…:$FLUXIE_VERSION, então a candidata ganha esse nome.
VERSION=${IMG##*:}
TAGGED=ghcr.io/$REPO:$VERSION
[ "$IMG" != "$TAGGED" ] || TAGGED=
CREATED= # só apaga a etiqueta se foi este script que a criou
[ -z "$TAGGED" ] || docker image inspect "$TAGGED" >/dev/null 2>&1 || CREATED=$TAGGED
trap 'dc down -v --remove-orphans >/dev/null 2>&1 || true; rm -f "$ENVF" "$OLD"; [ -z "$CREATED" ] || docker rmi "$CREATED" >/dev/null 2>&1 || true' EXIT
printf '%s\n' "$OLD_COMPOSE" >"$OLD"
SERVICES=(app worker scheduler reverb waha)
healthy() { # <fase>
  for s in "${SERVICES[@]}"; do
    wait_for "$s" healthy 240 || { dc logs "$s" 2>&1 | tail -60 || true; fail "$1: $s não ficou healthy"; }
  done
}
http() { dc exec -T app curl -s -o /dev/null -w '%{http_code}' "http://localhost:8080$1" || echo erro; }
# Dados que a atualização precisa preservar: contagens e as linhas criadas aqui, achadas pela chave natural. Depois do "|"
# vêm setores e etiquetas, que podem crescer (a migração que marca a instalação antiga como concluída instala os padrões).
DATA='echo json_encode([
  "users" => App\Models\User::count(), "quick_replies" => App\Models\QuickReply::count(),
  "admin" => App\Models\User::where("email", "admin@upgrade.smoke")->where("role", "admin")->count(),
  "setor" => App\Models\Sector::where("name", "Setor Upgrade")->count(),
  "etiqueta" => App\Models\Tag::where("name", "Etiqueta Upgrade")->count(),
  "resposta" => App\Models\QuickReply::where("shortcut", "upgrade")->count(),
]), "|", App\Models\Sector::count(), " ", App\Models\Tag::count();'

echo "1. instalação da Release $FROM, sem FLUXIE_VERSION gravada (imagem dela)"
unset FLUXIE_VERSION
dc up -d --pull missing "${SERVICES[@]}"
healthy "$FROM"

echo "2. dados: admin pelo comando, setor, etiqueta e resposta rápida"
printf '%s\n' "$(hex 12)" | dc exec -T app php artisan app:create-user admin@upgrade.smoke "Admin Upgrade" --role=admin ||
  fail "app:create-user falhou"
dc exec -T app php artisan tinker --execute '
  App\Models\Sector::create(["name" => "Setor Upgrade"]);
  App\Models\Tag::create(["name" => "Etiqueta Upgrade", "scope" => App\Enums\TagScope::cases()[0], "color" => App\Enums\TagColor::cases()[0]]);
  App\Models\QuickReply::create(["shortcut" => "upgrade", "body" => "Resposta da fumaça de atualização"]);' ||
  fail "criar setor, etiqueta e resposta rápida falhou"
before=$(dc exec -T app php artisan tinker --execute "$DATA") || fail "ler os dados antes falhou"
echo "antes:  $before"
[[ "$before" == *'"admin":1,"setor":1,"etiqueta":1,"resposta":1}|'* ]] || fail "dados não foram criados"

echo "3. troca para o compose candidato com FLUXIE_VERSION=$VERSION ($IMG)"
[ -z "$TAGGED" ] || docker tag "$IMG" "$TAGGED"
export FLUXIE_VERSION=$VERSION
# valida antes da troca: com o compose candidato inválido, o `down -v` do trap também falharia e deixaria a fase 1 de pé
docker compose -p "$SMOKE_PROJECT" -f docker-compose.yml --env-file "$(cygpath -m "$ENVF" 2>/dev/null || echo "$ENVF")" config -q ||
  fail "compose candidato inválido"
SMOKE_FILES=(-f docker-compose.yml)
dc up -d --pull missing "${SERVICES[@]}"
healthy "depois da troca"

echo "4. /up 200 e /login 200 (instalação existente não cai no /instalar)"
for pc in /up:200 /login:200; do
  code=$(http "${pc%:*}")
  [ "$code" = "${pc#*:}" ] || fail "${pc%:*} devolveu $code (esperado ${pc#*:})"
done

echo "5. sem migração pendente, versão candidata no app e dados preservados"
ms=$(dc exec -T app php artisan migrate:status) || fail "migrate:status falhou"
! grep -q Pending <<<"$ms" || fail "migração pendente"
v=$(dc exec -T app php artisan tinker --execute 'echo config("fluxie.version");') || fail "ler a versão falhou"
[ "$v" = "$VERSION" ] || fail "versão no app é '$v' (esperado $VERSION)"
after=$(dc exec -T app php artisan tinker --execute "$DATA") || fail "ler os dados depois falhou"
echo "depois: $after"
[ "${after%|*}" = "${before%|*}" ] || fail "dados mudaram na atualização"
read -r sb tb <<<"${before#*|}"
read -r sa ta <<<"${after#*|}"
[ "$sa" -ge "$sb" ] && [ "$ta" -ge "$tb" ] || fail "setores ou etiquetas sumiram ($sb -> $sa, $tb -> $ta)"

echo OK
