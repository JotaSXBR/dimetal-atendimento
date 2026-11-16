# Menores pendentes

Achados menores adiados a pedido do dono, para fechar depois. O portão 1 de todo trabalho novo lê este arquivo e pergunta quais
incluir; o item sai no commit que o corrige. **Decisão:** quem precisa responder (dono ou advisor) antes de corrigir.

## Menores de revisão

Nenhum pendente. Os menores do sino (S1 a S4, `fix/ux-sino-alertas`) foram descartados pelo dono em 16/11/2026; o S4 foi
conferido no beta (ticket do histórico abre e mostra o protocolo). Os menores da série "UX simplificação" fecharam em 14/11/2026
(plano `docs/superpowers/plans/2026-11-14-ux-menores.md`).

## Achados do teste de UI no beta (16/11/2026)

| # | Onde | O quê | Decisão |
|---|------|-------|---------|
| B3 | Cabeçalho da conversa | Abrir Detalhes reorganiza a barra e o "Encerrar" cai sob o cursor | — |

## Menores de UX antigos a reconferir (06/11 a 11/11)

Adiados nas fatias do redesenho (#73, #74, #83, #87, #88, #100). Podem ter sido resolvidos pelas fatias seguintes: conferir antes de corrigir.

| # | Origem | O quê | Decisão |
|---|--------|-------|---------|
| U2 | #83 | Faixa do hub de Configuração com rolagem horizontal no celular (`settings-page.blade.php:16`, `overflow-x-auto`): conferir no navegador | — |
| U6 | #87 | Prefixo da chave `history-*`: sem conflito hoje (ramos exclusivos em `contact-card`, componentes distintos); só nomenclatura | — |
| U9 | #88 | `basis-56` pode quebrar as ações do cabeçalho em `lg` com Detalhes aberto (ver também B3) | — |
| U10 | #73 | Chevron do menu da bolha (`transcript.blade.php:146`, `absolute end-1 top-1`) cobre o fim da 1ª linha em bolha curta no hover; precisa reservar espaço na bolha (layout): conferir no navegador | — |
| U11 | #74 | Rascunhos antigos sem limpeza de `localStorage` (o storage bloqueado já foi tratado, `445fd7d`); a limpeza precisa de regra de validade | — |

## Pendências maiores já combinadas (não são menores de revisão)

- Modernizar o `phpmd.xml` com o PHPMD mais recente.
- Futuro app/parte mobile separado (auditoria em `docs/ux-audit/2026-11-15-ux-audit-mobile.md`, branch local `docs/ux-audit-mobile`).
