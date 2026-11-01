# Deploy mais rápido e sem warnings (design curto)

## Intenção
O deploy do beta leva 3 min 42 s. Medido no log de 31/10: 94 s compilando `intl`, `gd` e `bcmath` (cache de build apagado
entre deploys), 27 s num `chown -R` redundante. Além disso, as 36 variáveis do app (com segredos) estão marcadas como
variáveis de build no Coolify, o que gera o warning de `APP_ENV` e expõe os valores na linha `--build-arg` do log.

## Objetivos
- Tirar o `chown -R /var/www/html` do Dockerfile (o `COPY --chown` já dá o dono certo; `vendor/` só é lido).
- Imagem base com as extensões já compiladas, publicada no GHCR (repositório público, sem custo), reconstruída semanalmente.
- Marcar as variáveis do Coolify como só de runtime (app, worker, scheduler e reverb).
- Dependabot cobrindo `docker/` e silenciar o aviso do npm.

## Não objetivos
- Remover `gd`/`bcmath` (decisão do dono: manter, por isso a imagem pré-compilada).
- Mexer na produção do Coolify (só o beta está em escopo).

## Abordagens
1. **Imagem base no GHCR (escolhida):** corta ~95 s por deploy; custo é um workflow novo e um passo manual (tornar o pacote público).
2. Só remover `gd`/`bcmath`: menos peça, mas perde recursos possíveis. Descartada pelo dono.
3. Cache de build em registry: Coolify não expõe `--cache-from` facilmente. Descartada.

## Desenho e ordem (dois PRs, porque o app não pode apontar para uma imagem que ainda não existe)
- **PR A:** `docker/base/Dockerfile`, workflow `base-image.yml` (push em main quando `docker/base/**` muda, semanal, manual),
  remoção do `chown -R`, `NPM_CONFIG_UPDATE_NOTIFIER=false`, Dependabot `docker`.
- Passo manual do dono: tornar o pacote `dimetal-php-base` público no GitHub (a API não permite).
- **PR B:** `docker/Dockerfile` passa a usar `FROM ghcr.io/jotasxbr/dimetal-php-base:8.5` e deixa de instalar extensões.
- Coolify: `PATCH` das variáveis com `is_buildtime=false`, sem imprimir valores; snapshot dos flags antes para reverter.

## Decisões minhas (custo se erradas)
- Tag `:8.5` mutável, reconstruída por semana: pega correções do SO, mas uma regressão da base chega sem PR. Custo: um deploy
  quebrado até reverter a tag.
- Variáveis só de runtime: se algum dia o build precisar delas, o Vite não as verá; hoje o Dockerfile não declara `ARG`.

## Atualização (mesma fatia, a pedido do dono)
- **Um PR só:** a imagem base é publicada no GHCR a partir da máquina do dono antes do merge, e a troca do `FROM` entra no mesmo
  PR. O pacote precisa ficar público (passo manual na interface do GitHub) e ter a etiqueta `org.opencontainers.image.source`.
- **Node 26 LTS** no CI (`setup-node`) e na imagem de assets (`node:26-alpine`). Validado: `npm ci`, `npm run build` e `node --test tests/js`.
- **Testes de JS no CI:** `tests/js` não rodava em lugar nenhum; passo `node --test tests/js` no job `tests`.
- **Fora:** alinhar o CI ao `phpunit.xml` (array/sync). O Redis real do CI pega bugs que `array` esconde.
