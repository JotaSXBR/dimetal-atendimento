# Relatório: CI, deploy e Dependabot (31/10 a 01/11/2026)

## Resultado em números
| Medida | Antes | Depois |
|---|---|---|
| PR espera o CI (job mais lento) | ~2 min 15 s (uma amostra; antes variava de 1 min 39 s a 8 min 10 s) | `tests` 1 min 50 s a 2 min 13 s (duas amostras); os ~45 s de checagens estáticas saíram do caminho crítico (`static` 17-25 s em paralelo) |
| Deploy do beta (início ao fim, Coolify) | 3 min 42 s | 1 min 46 s (uma amostra cada) |
| Compilar `intl`/`gd`/`bcmath` | 94 s por deploy | 0 s (imagem base pré-compilada no GHCR) |
| `chown -R` do Dockerfile | 26,8 s | removido |
| Variáveis de build com segredos no log | 135 (4 apps) | 0 (só runtime) |
| Erro de estilo/tipo descoberto | só no CI | no `pre-push`, em segundos (só depois de `composer hooks:install` em cada clone) |
| `tests/js` no CI | não rodava | roda (6/6) |
| Node | 24 | 26 LTS (lido do Dockerfile) |

## O que foi feito
1. **PR #50 — CI em jobs paralelos.** Job `static` (Pint, Larastan, PHPMD, sem banco) em paralelo com `tests`; cache do Larastan/Pint
   (`ci:static`: 48 s frio, 8 s quente); scripts `ci:static`/`ci:tests`; hook `.githooks/pre-push` (`composer hooks:install`);
   proteção da `main` passou a exigir `tests` + `static` + PR (0 aprovações), aplicada por POST/PATCH com snapshot.
2. **Análise do log de deploy** e **PR #51 — deploy rápido:** Dockerfile sem `chown -R`; imagem base `ghcr.io/jotasxbr/dimetal-php-base:8.5`
   (workflow `base-image`: muda `docker/base/**`, toda segunda e sob demanda; pacote público); Dependabot para `docker/`; Node 26;
   passo `node --test tests/js`; 135 variáveis do Coolify só de runtime (antes em `$TEMP/coolify-envs-ANTES.json`).
3. **Este PR — Dependabot:** patch+minor agrupados e major em PR próprio (cooldown 14 dias); `fontaine` major ignorado; PHP e Node
   do CI lidos dos Dockerfiles (ação local `versions`); guarda Postgres/Redis do CI x `compose.yaml`; `docker-compose` no Dependabot;
   `deploy-beta` com checkout por SHA e `actions/cache` v6.1.0. O PR #52 (npm) falhou por `ERESOLVE` (fontaine 1.x x peer ^0.8 do
   `laravel-vite-plugin`) e o #53 (actions) passou: ambos ficam substituídos por este PR.

## Descartado, com motivo
Runners pagos (sem fila a eliminar); `paratest` (ganho máximo ~30 s, `flushdb()` compartilhado vira corrida); sharding em máquinas
(custo de subir cada uma come o ganho); pular CI em PR de docs (travaria os checks exigidos); cobertura só na `main` (tira a trava de
80% dos PRs); alinhar o CI ao `phpunit.xml` (o Redis real pega bugs que `array` esconde); tirar `npm run build` do CI (views usam `@vite`).

## Erros meus no caminho (corrigidos)
- Apaguei o container `pg-test` antes de ler seus logs: os logs se perderam.
- Um monitor em segundo plano ficou preso no shell; parado.
- Uma edição do Dockerfile por script falhou em silêncio (CRLF do Windows) e o primeiro build validou o arquivo antigo; refeito.
- Disse que o `fontaine` talvez não fosse usado: ele é peer opcional do `laravel-vite-plugin` (fontes). Não remover.

## Em aberto
- **Merge automático do Dependabot** (patch/minor, só com `tests` e `static` verdes) — dono ainda não decidiu; exige ligar "Allow auto-merge".
- Smoke do Reverb no beta (precisa de sessão logada); 30 s sem explicação no rolling update do Coolify (ligar "Show Debug Logs");
  o hook `pre-push` olha a árvore de trabalho, não os commits enviados; `postgres`/`redis` do CI só são conferidos, não gerados do compose.
- O Dependabot vai oferecer majors do Node que não são LTS (ex.: `node:27-alpine`) como PR único: recusar, ou criar uma regra `ignore`
  se incomodar (a sintaxe de `ignore` para `docker` aceita `dependency-name` sem tag; não testada).
- `base-image` e `deploy-beta` disparam no mesmo push na `main`: o primeiro deploy depois de mudar `docker/base/**` pode pegar o digest antigo
  da `:8.5`; um segundo deploy resolve.
- O snapshot das variáveis do Coolify ficava em `$TEMP` (não persiste). Reverter = remarcar "Available at Buildtime" nas variáveis
  que precisarem; hoje nenhuma precisa (o Dockerfile não declara `ARG`).
- Auto-merge do Dependabot e o smoke do Reverb continuam abertos (acima).
