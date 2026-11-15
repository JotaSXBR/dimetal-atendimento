# Menores pendentes

Achados menores adiados a pedido do dono, para fechar depois. O portão 1 de todo trabalho novo lê este arquivo e pergunta quais
incluir; o item sai no commit que o corrige. **Decisão:** quem precisa responder (dono ou advisor) antes de corrigir.

## Menores de revisão

Do bug do sino (`fix/ux-sino-alertas`, 14/11), limites aceitos e sem efeito visível hoje:

| # | Onde | O quê | Decisão |
|---|------|-------|---------|
| S1 | `app/Livewire/Alerts/Bell.php` (`read`) | Carrega todos os não lidos do usuário a cada clique (poucos, pelo purge de 7 dias); dá para filtrar no banco por `data->ticket_id` | — |
| S2 | `app/Livewire/Alerts/Bell.php` (`render`) | Hidrata até 200 alertas por render (3 consultas fixas, sem N+1); dá para selecionar só as colunas usadas | — |
| S3 | `app/Livewire/Alerts/Bell.php` (`markRead`) | Não é mais chamado pela view (só pelos testes); remover e trocar os testes por `open` | — |
| S4 | `Bell::open` x `render` | O link usa `readableBy` (inclui histórico) e a lista mostra o protocolo com `visibleTo`: ticket do histórico abre mas sem o protocolo no item | Dono: alinhar? |

Fora esses, nenhum pendente. Os menores das fatias D, E, F e G da série "UX simplificação", e os da revisão do próprio `fix/ux-menores`, foram
resolvidos (ou aceitos e fechados) em 14/11/2026, com rulings do advisor registrados no ledger (`Ruling:`); o plano é
`docs/superpowers/plans/2026-11-14-ux-menores.md`. Os menores das fatias A, B e C fecharam sem pendências.

## Pendências maiores já combinadas (não são menores de revisão)

- Achados do sino de alertas (alertas duplicados, "0 mensagens", clique no alerta de SLA abrir o ticket): depois da fatia H.
- Modernizar o `phpmd.xml` com o PHPMD mais recente (nota em memória, 14/11).
- Futuro app/parte mobile separado.
