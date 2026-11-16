# CLAUDE.md

Fonte única das regras do projeto (o `AGENTS.md` só aponta para cá). O Claude Code relê este arquivo depois de compactar.
Antes de qualquer tarefa, ver se há skill aplicável. Sempre as skills do plugin `superpowers:`; o plugin `agent-skills` fica
desligado neste projeto (`.claude/settings.local.json`).

## Fluxo de desenvolvimento (Superpowers)

Feature nova ou mudança relevante passa pelos portões em ordem, cada um chamando a skill pela ferramenta `Skill` na hora dele.
O como de cada etapa é o da skill; aqui ficam só a prova e o que este projeto muda.

| # | Etapa | Skill | Prova para abrir o próximo |
|---|-------|-------|----------------------------|
| 1 | Design | `superpowers:brainstorming` | spec em `docs/superpowers/specs/AAAA-MM-DD-tema-design.md` com Approaches, aprovado pelo dono |
| 2 | Branch | (git) | branch ≠ `main`, criada da base certa |
| 3 | Plano | `superpowers:writing-plans` | plano em `docs/superpowers/plans/AAAA-MM-DD-tema.md` |
| 4 | Execução | `superpowers:subagent-driven-development` | ledger `.superpowers/sdd/<plano>/progress.md` com uma linha por tarefa |
| 5 | Implementação | `superpowers:test-driven-development` | RED visto falhando antes do código, em cada tarefa |
| 6 | Revisão | `superpowers:requesting-code-review` (+ `receiving-code-review`) | revisão do branch inteiro pelo agente `revisor-final` |
| 7 | Verificação | `superpowers:verification-before-completion` | saída dos comandos do portão 7 (abaixo) lida na sessão |
| 8 | Fim | `superpowers:finishing-a-development-branch` | opção escolhida, respeitando as Regras do dono |

O que este projeto muda nas skills:

- Trava de código: o hook `.claude/hooks/require-plan.js` nega `Edit`/`Write` em `app/`, `resources/`, `routes/`, `database/`
  e `tests/` na `main`, e pergunta ao dono quando nenhum plano mudou no branch. Ajuste trivial pedido explicitamente (texto,
  rótulo) passa pela pergunta e dispensa spec e plano; se muda comportamento, tem TDD.
- Etapa só se pula quando o dono nomear; vai ao ledger como `Desvio:` com o custo se errado.
- Execução: a sessão fica em Opus como controladora e despacha os agentes de `.claude/agents/`. Implementadores um por vez
  (a skill proíbe dois em paralelo, e os testes dividem o mesmo Postgres e Redis). Em paralelo, só agentes que leem: busca,
  revisão de tarefa, investigação de falhas independentes, pré-voo do plano.
- Worktree só se o dono pedir (o setup local é caro: `vendor`, `.env`, Postgres de teste); a skill trabalha no branch atual.
- Achados menores da revisão: os baratos (poucas linhas, sem refazer revisão) se corrigem antes do PR, com RED antes. O resto
  vai para `docs/superpowers/menores-pendentes.md` (data, branch, `arquivo:linha`, motivo); o portão 1 de todo trabalho novo
  lê esse arquivo e pergunta ao dono quais incluir.
- Bug ou comportamento inesperado: `superpowers:systematic-debugging` antes de corrigir. Skill fora de hora ou plano ignorado:
  `superpowers:diagnosing-superpowers`.

## Agentes e modelos

Sempre declarar `model` e `effort` na chamada (omitido, herda o da sessão). Os agentes herdam o modo de permissão da sessão e
os hooks do projeto.

| Agente | Modelo | Uso |
|--------|--------|-----|
| `implementador` | sonnet, medium | uma tarefa do plano, com TDD; `haiku` quando o plano traz o código completo e a tarefa toca 1 a 2 arquivos |
| `revisor-tarefa` | sonnet, medium | revisão de spec e qualidade de cada tarefa (molde da skill SDD) |
| `revisor-final` | opus, low | revisão do branch inteiro no portão 6 (molde `code-reviewer.md`) |
| `Explore` | haiku, low | busca só fora do índice do CodeGraph: `vendor/` (Livewire, Flux), Blade, migrations, `docs/` |
| `leitor-web-logs` | haiku, low | páginas da web na pesquisa e logs grandes (Coolify, WAHA, saída de teste) |
| `conferente-visual` | sonnet, medium | conferência no navegador pelo Claude in Chrome (servidor local ou beta): layout, foco, medidas, console e CSP |

Builder-check do brainstorming e investigações em paralelo: subagente comum com `sonnet`, `medium`. Ciclo de correção da SDD
nas rodadas 4 e 5: um modelo acima do implementador. Mudar um destes é decisão do dono.

## Pesquisa antes de feature nova

Feature que ainda não existe no repositório não se escreve do zero; a pesquisa alimenta as Approaches do portão 1.
Dispensada para ajuste trivial.

1. Ordem: documentação oficial pelo Context7 (IDs abaixo), depois Packagist, depois GitHub e internet.
2. Filtro: projeto vivo (commits ou releases recentes), compatível com as versões daqui, licença MIT, Apache-2.0, BSD ou
   GPL/AGPL; na dúvida, avisar o dono.
3. Comparar trade-offs e custo de manutenção, incluindo fazer internamente. Candidato claramente melhor: seguir; trade-off
   relevante (dependência pesada, mudança de arquitetura): o dono escolhe.
4. Referências no chat e no ledger, nunca em commit nem PR. Código de terceiros adaptado mantém o aviso de copyright exigido.

## CodeGraph

- Código indexado (PHP de `app/`, `routes/`, `database/`, `tests/`, `config/`, `bootstrap/`, `lang/` e JS de `resources/js`)
  se busca e se lê com `codegraph_explore`: uma chamada com todos os nomes, consulta qualificada
  (`App\Livewire\Alerts\Bell markAllRead`). O hook `block-code-search.js` nega `Grep`/`Glob` nesse código.
- Fora do índice ou sem símbolos: `.env*`, configs, `docs/`, `vendor/`, migrations (esquema é texto no `up()`) e Blade. Ali
  vale `Grep`/`Read` ou o agente `Explore`.
- Antes de editar, ver callers e testes do `explore`. Livewire: `wire:click`, `wire:model`, `$wire.` e `@this` não viram
  arestas; ao mudar método, propriedade pública ou evento, buscar também em `resources/views`.
- Testes afetados no ciclo RED/GREEN:
  `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`.
  Mudança só em Blade dá zero afetados: rodar o teste do componente.
- O índice atrasa alguns segundos após gravar; aviso de arquivo pendente no `explore` → `Read` nele. `codegraph sync` só se
  `codegraph status` não disser "Index is up to date". `.codegraph/` é local, nunca commitar.

## Stack e comandos

Laravel 13, Livewire 4.4, Flux 2, Reverb, Fortify, PostgreSQL 18, Redis 8. Versões de PHP e Node do CI:
`docker/base/Dockerfile` e `docker/Dockerfile`. Commits em português com prefixo (`feat:`, `fix:`).

- Dúvida sobre a stack: Context7 antes de responder ou implementar. IDs: Laravel `/laravel/docs/__branch__13.x`, Livewire
  `/livewire/livewire/v4.4.8`, Flux `/websites/fluxui_dev` (documenta a versão mais nova; conferir em `vendor/livewire/flux`
  se o recurso existe na instalada). Atualizar o ID ao subir a dependência.
- Postgres de teste (sem ele o teste trava em vez de falhar):
  `docker run -d --name pg-test -e POSTGRES_USER=dimetal -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=dimetal_test -p 5432:5432 postgres:18-alpine`,
  depois migrar uma vez:
  `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=dimetal_test DB_USERNAME=dimetal DB_PASSWORD=secret DB_URL= php artisan migrate --force`.
  Remover no fim com `docker rm -f pg-test`. Não rodar testes enquanto um servidor local de verificação usa o `dimetal_test`.
- Ambiente de teste local: `php artisan migrate:fresh --seed --seeder=AmbienteDeTesteSeeder` (banco dimetal_local); cenário novo de conferência
  visual = classe nova em `database/seeders/Cenarios/` chamada pelo agregador, com assert no `AmbienteDeTesteSeederTest`.
- Suíte do dia a dia: `php artisan test --parallel` (um banco `dimetal_test_test_N` por processo; fila `sync`). Teste que
  abre processo filho passa `DB_DATABASE` a ele (ver `ClaimConcurrencyTest`).
- Suíte com Redis (espelho do CI): teste com `travel()` ou TTL passa no `array` e falha no Redis; simular expiração com
  `Cache::forget`, nunca `travel()`.
  `MSYS_NO_PATHCONV=1 docker run -d --name redis-test -p 6379:6379 redis:8-alpine redis-server --databases 32`, depois
  `CACHE_STORE=redis QUEUE_CONNECTION=redis SESSION_DRIVER=redis REDIS_CLIENT=predis REDIS_HOST=127.0.0.1 REDIS_PORT=6379 php artisan test --parallel`;
  remover com `docker rm -f redis-test`.
- Portão 7: `vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress`,
  `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`, `node --test tests/js`, `npm run build` e a suíte com
  Redis. Ler o código de saída de cada um, sem `| tail` no meio (ou com `set -o pipefail`).
- Composer no Git Bash é `composer.bat`. `composer.bat test` roda Pint, Larastan, PHPMD e a suíte em série, sem Redis. A
  cobertura mínima de 80% só é conferida no CI.
- Editar com a ferramenta `Edit`. Python em heredoc só para a mesma troca em muitos pontos ou arquivo em CRLF, sempre com
  string raw (`r'...'`) e `assert` na contagem de trocas antes de gravar.
- As ferramentas condensam HTML/Blade na leitura: o original sai com `caveman_retrieve` e o handle `ccr_...`.

## Regras do dono

- Não abrir PR sem pedido. Docs, specs e notas vão no PR da fatia que as usa, ou ficam no branch até o dono pedir. Merge só
  do que o dono nomeou.
- Só o beta está em escopo: nunca tocar a `main`/produção do Coolify, nunca commitar na `main`.
- Credenciais do Coolify em `.env.coolify.local` (ignorado; nunca imprimir o token).
- Este `CLAUDE.md` é versionado. Locais e ignorados pelo git: `AGENTS.md`, `.claude/`, `.mcp.json`, `GEMINI.md`, `.gemini`,
  `.codegraph`, `skills-lock.json`, `.referencias/` (prints de produtos de terceiros, só para consulta) e `.superpowers/`
  (ledger). O `.devin/` é ignorado só nesta máquina, pelo `.git/info/exclude`.
