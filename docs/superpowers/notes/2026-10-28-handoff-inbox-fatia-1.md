# Passagem de contexto — Inbox fatia 1 (2026-10-28)

Para quem retomar o trabalho (inclusive uma sessão nova do Claude). Leia junto:
`docs/superpowers/specs/2026-10-28-inbox-fatia-1-design.md` e `docs/superpowers/plans/2026-10-28-inbox-fatia-1.md`.

## Onde estamos
- **PR 0** (#18) e **fix worker/scheduler** (#19): mergeados.
- **PR A** (#20, branch `feat/inbox-dados-e-ingestao`): Tasks 1–6 prontas, CI verde, **aberto e sem merge**
  (o merge aplica migrações no beta; aguardar OK do dono). Inclui a passada de correções da revisão e a
  mudança para UTC (abaixo).
- **PR B** (Tasks 7–12: permissões, assumir/encerrar, Reverb, tela da Inbox, `reverb-beta`, prova real):
  não começou. Só começa depois do merge do PR A.

## Decisões que valem daqui para frente
- **Horário**: app e banco em **UTC** (`APP_TIMEZONE=UTC`). As telas mostram em São Paulo via
  `config('app.display_timezone')` (`APP_DISPLAY_TIMEZONE`, padrão `America/Sao_Paulo`). O `timestamp` do
  WAHA é epoch em segundos e entra com `CarbonImmutable::createFromTimestampUTC`. Toda tela nova deve
  formatar com `->timezone(config('app.display_timezone'))`. Linhas antigas do beta (canais, eventos do
  WAHA) foram gravadas em hora de São Paulo e agora aparecem 3 h adiantadas; são dados de teste.
- **Worker e scheduler**: o Coolify ignora `start_command` em build por Dockerfile. Eles usam os estágios
  `worker` e `scheduler` do `docker/Dockerfile`, escolhidos em `dockerfile_target_build` (o app usa
  `runtime`). Healthchecks `healthcheck-queue` / `healthcheck-schedule` (tipo cmd).
- **Concorrência na ingestão**: advisory lock por remetente e por (contato, canal); job com 6 tentativas;
  ack lido com `lockForUpdate`; horários do ticket só avançam (`Ticket::recordInbound/recordOutbound`).
  Os testes verificam o lock no SQL, não uma corrida real entre workers.
- Contatos duplicados por LID **não** são mesclados (ficam dois registros até existir ferramenta de mescla).
- Laravel MCP descartado; Laravel Boost não adotado (reavaliar com teste curto depois do PR A).

## Recursos do beta (Coolify)
`app-beta` ipbknnticmaubtzdonxjqgy6 · `worker-beta` jme7xgh4ugrrf3qbrxaclwlj ·
`scheduler-beta` 3thwz32zdtnmr2sme70jrpdf · `waha-beta` gxpbtauw9ylqyd8nni3keznx.
Acesso pela API com `.env.coolify.local` (chaves `COOLIFY_API` e `COOLIFY_URL`). Deploy automático:
merge em `main` → `tests` → `deploy-beta` (sincroniza `beta` e chama o Coolify).

## Como o dono trabalha
- Só o **beta**. Nada de commit, PR ou merge sem pedido; merge é sempre pedido por PR.
- Economizar tokens. Registrar nas mudanças as referências consultadas (regra do `AGENTS.md`).
- Testar antes de enviar: `php artisan test`, `vendor/bin/phpstan analyse --no-progress`,
  `vendor/bin/pint --test` (ignorar só `line_ending`, é o CRLF local). `composer` não está no PATH do bash.

## Armadilhas já vividas
- Heredoc grande no shell às vezes falha com "unexpected EOF": gravar arquivos com a ferramenta de escrita.
- `git stash -u` + trocar de branch + `pop` conflita quando a outra branch não tem os arquivos; prefira
  commitar ou usar outra pasta.
- `main` local pode estar atrás: use `git fetch` e `origin/main`.
- Ledger de execução: `.superpowers/sdd/2026-10-28-inbox-fatia-1/progress.md` (ignorado pelo git; todas as
  decisões e achados da revisão estão lá).

## Próximo passo
Com OK do dono: mergear o PR #20, conferir no beta uma mensagem real (contato, ticket e mensagem no banco,
ack de leitura) e então seguir a Task 7 do plano.
