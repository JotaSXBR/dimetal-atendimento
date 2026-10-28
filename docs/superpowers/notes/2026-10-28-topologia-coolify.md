# Notas — topologia do deploy no Coolify (28/10/2026)

## Reversão de decisão

A topologia anterior (uma aplicação docker-compose com 6 containers) foi **rejeitada** pelo dono do
produto: no Coolify isso aparece como **um** recurso, com todos os containers pendurados nele. Ele
quer cada container visível e administrável separadamente (start/stop, logs, terminal, limites).

Consequência: `compose.coolify.yaml` deixa de ser o artefato de deploy. A aplicação passa a ser
montada com recursos nativos do Coolify — Applications, Databases e, se preciso, um Service.

## Escopo

- Só o **beta** por enquanto. O `main` fica intocado (`dimetal-main`, uuid
  `nztdbc9d6agpmza31ozhhmo7`).
- Nada de novos testes de ponta a ponta até a topologia nova estar de pé.

## Fatos que sustentam a decisão

- `Application` = segue um repositório Git (ou uma imagem Docker pronta). `Database` = um banco
  gerenciado pelo Coolify, com backup e credenciais na tela. `Service` = um compose inteiro, de novo
  um recurso só — não serve para este caso.
- Recursos separados só se enxergam pela rede Docker se estiverem na mesma rede. O Coolify oferece
  "Connect to predefined network" (rede `coolify`) e **Network Aliases** para dar nome estável ao
  container (`app`, `waha`, `postgres`, `redis`).
- Nomes de alias precisam ser únicos por rede. Main e beta dividindo a rede `coolify` exigem sufixo
  (`app-beta`, `postgres-main`…), senão o DNS resolve para o projeto errado.
- Um `Application` tem seu próprio build. Três Applications (app, worker, scheduler) do mesmo
  Dockerfile significam três builds por deploy, salvo se o build for produzido uma vez e consumido
  como imagem pronta.

## Decisões (28/10/2026)

1. **6 recursos separados**: app, worker, scheduler, postgres, redis, waha. Cada um com start/stop,
   logs e terminal próprios.
2. Postgres e Redis são **`Database` do Coolify** (credenciais na tela, backup nativo).
3. **3 builds por deploy** — app, worker e scheduler constroem do mesmo Dockerfile. Sem registry.
4. `dimetal-main` fica **intocado** por enquanto.

## Desenho do beta

| Recurso | Tipo | Alias de rede | Porta | Storage |
|---|---|---|---|---|
| `app-beta` | Application (Git, `/docker/Dockerfile`) | `app-beta` | 8080 + domínio | `storage/app` |
| `worker-beta` | Application (mesmo Dockerfile, `queue:work`) | `worker-beta` | — | — |
| `scheduler-beta` | Application (mesmo Dockerfile, `schedule:work`) | `scheduler-beta` | — | — |
| `pg-beta` | Database PostgreSQL 18 | nome interno gerado | 5432 | volume do recurso |
| `redis-beta` | Database Redis 8 | nome interno gerado | 6379 | volume do recurso |
| `waha-beta` | Application (imagem `devlikeapro/waha:gows-2026.10.1`) | `waha-beta` | 3000, sem domínio | `/app/.sessions`, `/app/.media` |

- `pg-beta` e `redis-beta` não aceitam alias na API; o host é o nome interno que o Coolify gera e
  mostra na URL interna (estável entre deploys).
- Os quatro Applications entram na rede `coolify` com alias próprio, porque main e beta convivem no
  mesmo VPS: `app-beta`, `waha-beta`… evitam colisão de DNS.
- Só `app-beta` tem `AUTORUN_ENABLED=true` (migrate no start). Domínio só nele.
- WAHA aponta o webhook para `http://app-beta/webhooks/waha`, nunca para o domínio público.

## Regra para atualizações (definida pelo dono)

- Update futuro sai por **GitHub Actions com outra API key do Coolify**, não pelo push automático.
- A versão nova só entra no ar **depois de verificada**; a antiga não é derrubada antes.
- Objetivo: **zero downtime**. Isso pede atualização em duas etapas (subir o novo, conferir, então
  trocar o tráfego) — não cabe no replace de container que o Coolify faz por padrão.

## Executado no beta (28/10/2026)

| Recurso | Tipo | UUID |
|---|---|---|
| `app-beta` | Application (Git) | `ipbknnticmaubtzdonxjqgy6` |
| `worker-beta` | Application (Git) | `jme7xgh4ugrrf3qbrxaclwlj` |
| `scheduler-beta` | Application (Git) | `3thwz32zdtnmr2sme70jrpdf` |
| `waha-beta` | Application (imagem) | `gxpbtauw9ylqyd8nni3keznx` |
| `reverb-beta` | Application (Git, estágio `reverb`) | `oncydt57liptigusmj64ac3o` |
| `pg-beta` | Database PostgreSQL 18 | `m3kcov2jn01utd97jjho2lox` |
| `redis-beta` | Database Redis 8 | `vp3u0qyz2tsmyveqrb6ml2zi` |

Estado: seis recursos no ar, `/up` 200 com TLS válido, `/login` 200, `http` → `https` 302, cookie
`Secure`, assets 200. `app` alcança `waha-beta:3000` (probe via scheduled task).

Achados que viraram documentação:

- `custom_network_aliases` não está no OpenAPI, mas o controller aceita no create e no PATCH.
- `POST /storages` exige `name` em volume persistente (422 sem ele).
- A imagem do WAHA não tem `curl` nem `wget`: healthcheck `cmd` com `curl` reprova. O que funciona é o
  tipo `http` em `/ping` (porta 3000, retorno 200), executado pelo Coolify.
- O Redis criado como Database **gera senha**; `REDIS_PASSWORD` é obrigatório.
- `connect_to_docker_network` só aparece dentro de `settings` no `GET`.

## Consequências técnicas já mapeadas

- `DB_HOST`, `REDIS_HOST` e `WAHA_URL` passam a apontar para os aliases, não para nomes do compose.
- Migração roda em um serviço só (o `app`), com `AUTORUN_ENABLED`/comando de deploy; worker e
  scheduler não migram.
- `storage/app` deixa de ser volume compartilhado entre app/worker/scheduler por padrão. Hoje nada
  grava ali (Fase 0); quando a mídia chegar, decidir entre volume nomeado comum ou armazenamento
  externo.
- O WAHA continua sem domínio público, com volume de sessão próprio.
