# SLA vivo: medição do custo da rotina por minuto (30/10/2026)

Pergunta: a rotina `app:check-sla` (a cada minuto) e a lista do Inbox aguentam o volume previsto (2 números de WhatsApp,
anos de tickets)? Resposta curta: **só com um índice parcial sobre os tickets abertos**; sem ele, cada consulta lê a tabela inteira.

**Método.** PostgreSQL 18.6 em Docker local. Tabela `tickets` simplificada (as colunas que as consultas usam e o índice
`(status, opened_at)` que existe hoje): 1.000.000 de encerrados (cerca de 3 anos) e 300 abertos (100 aguardando, 200 em
atendimento, metade deles esperando resposta). 81 MB, tudo em memória, dados sintéticos, uma medida por consulta, sem concorrência.

| Consulta | Sem índice parcial | Com índice parcial dos abertos |
|---|---|---|
| Adotar espera sem `waiting_since` | 37,4 ms (leitura paralela da tabela) | 0,07 ms |
| Aviso: `sla_warn_at` vencido sem marca | 39,5 ms | 0,06 ms |
| Estouro: `sla_due_at` vencido sem marca | 44,9 ms | 0,06 ms |
| Lista da direção (abertos com `sla_due_at` passado, 50) | 28,6 ms | 0,05 ms |
| **Lista do Inbox (já existente)**: abertos por `last_inbound_at` | **38,9 ms** | **0,37 ms** |
| Aba Encerrados (`status = 'encerrado'`, ordenada por `opened_at`) | 0,20 ms (usa o índice atual) | n/d |

Índices testados: `tickets (id) where status <> 'encerrado'` (16 kB) e `tickets (sla_due_at) where status <> 'encerrado' and
sla_due_at is not null` (16 kB). O índice atual não ajuda porque `status <> 'encerrado'` casa com quase tudo e o planejador prefere
ler a tabela.

**Conclusões.** (1) Sem o índice parcial a rotina gastaria ~150 ms por minuto, crescendo em linha reta com os tickets, e a lista do
Inbox ~39 ms a cada atualização de tela de cada usuário. (2) Com o índice parcial, cerca de 100 vezes menos, e o custo passa a
depender dos tickets abertos, não do histórico. (3) O cálculo de expediente (`BusinessHours`, 2 consultas por chamada) roda uma
vez por espera, então escala com as esperas novas por minuto, não com o total; um limite de 200 por passo protege contra rajada.

**Limites.** Dados sintéticos e tudo em cache (numa VPS com pouca memória a leitura da tabela piora); sem concorrência com o resto
do app; `closed_at` (Histórico e aba Encerrados) não foi medido e também não tem índice próprio. Da documentação do Laravel:
`withoutOverlapping()` sem argumento segura o bloqueio por 24 horas se o processo morrer no meio.
