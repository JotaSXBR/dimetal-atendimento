# Auditoria P0: limpeza do `waha_events` (design curto)

Origem: auditoria de 13/11/2026 (checklist de segurança, resiliência, performance, observabilidade). Aprovado pelo dono no chat em 13/11/2026.

## Intenção e porquê

`waha_events` guarda o JSON completo de cada webhook (texto de mensagens, telefones, nomes) e nunca é apagada. O disco e o `pg_dump` diário crescem sem limite, e há dado pessoal guardado sem prazo (LGPD). O evento só serve para o job `HandleWahaEvent` ler logo depois da chegada; as mensagens e tickets já têm a cópia definitiva.

## Objetivo

Apagar de `waha_events` os eventos com mais de 7 dias, todo dia, sem segurar lock longo.

## Fora do escopo (decisão do dono, 13/11/2026)

- 2FA obrigatório para admin e supervisor: o recurso existe; não será exigido agora.
- Sentry/Nightwatch: não agora.
- Política de retenção do texto das mensagens (LGPD): decisão separada.
- Variáveis de produção (`BACKUP_RESTORE_UPLOAD=false`, `LOG_LEVEL=warning`): só viram checklist em `docs/security.md`; o beta não muda (o dono mantém `LOG_LEVEL=debug` no desenvolvimento).

## Abordagens consideradas

1. **Comando agendado `app:purge-waha-events`** (escolhida): mesmo padrão de `PurgeNotificationsCommand` e `PurgeMediaCommand`; o `schedule:work` já existe.
2. Trait `Prunable` do Eloquent + `model:prune`: exige agendar `model:prune` para todos os modelos e foge do padrão do projeto.
3. Particionar a tabela por data: correto em escala grande, custo alto demais para o volume atual.

## Design

- Comando `app:purge-waha-events {--batch=5000}`: apaga `received_at < now()->subDays(RETENTION_DAYS)` em lotes por `id` (o Postgres não tem `DELETE ... LIMIT`), imprime `Eventos apagados: N`. `RETENTION_DAYS = 7` (constante pública).
- Agendamento: diário às 03:50 (UTC, como as demais), `withoutOverlapping(10)`.
- Migration: índice em `waha_events(received_at)`. O índice atual `(event, received_at)` começa por `event` e não serve para filtrar só por data. Criar o índice bloqueia gravações por alguns segundos.
- Doc: checklist de variáveis de produção em `docs/security.md`.

## Decisões minhas (o dono pode corrigir)

- **7 dias.** Custo se errado: um `HandleWahaEvent` que falhou e for reprocessado depois de 7 dias não acha o evento e termina em silêncio (já é o comportamento de `HandleWahaEvent::handle` quando o evento não existe). Mensagem duplicada não ocorre: `wa_message_id` é único.
- Lote de 5.000 e opção `--batch` só para o teste usar lote pequeno.
- Fix do Histórico (commit `793162d`, branch `fix/historico-aviso-aba-visivel`) entra no mesmo PR.
