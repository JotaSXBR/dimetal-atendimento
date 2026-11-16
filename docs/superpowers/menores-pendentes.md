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

## Menores de UX antigos a reconferir (06/11 a 11/11)

Adiados nas fatias do redesenho (#73, #74, #83, #87, #88, #100). Podem ter sido resolvidos pelas fatias seguintes: conferir antes de corrigir.

| # | Origem | O quê | Decisão |
|---|--------|-------|---------|
| U2 | #83 | Faixa do hub de Configuração com rolagem horizontal no celular (`settings-page.blade.php:16`, `overflow-x-auto`): conferir no navegador | — |
| U6 | #87 | Prefixo da chave `history-*`: sem conflito hoje (ramos exclusivos em `contact-card`, componentes distintos); só nomenclatura | — |

## Menores adiados da leva de menores baratos (16/11/2026)

Branch `chore/claude-md`, revisão final.

| # | Onde | O quê | Decisão |
|---|------|-------|---------|
| L3 | `app/Livewire/Settings/Backups.php` | TTL de 30 s do lock `restore-dispatch` | — |
| L4 | `resources/js/composer-mode.js:45` | Estilo: ternário usado como instrução | — |
| L5 | `resources/js/ticket-details.js:26` | Estilo: `catch` vazio | — |
| L6 | `tests/js/storage-blocked.test.mjs` | Mock de `window`/`document` sem restaurar ao fim | — |
| L7 | seed de teste | `sync` dos setores dos usuários de teste; `UsuariosDeTeste` sozinho fica sem setores; "sobra etiqueta" por `count > 1` | — |
| L8 | `resources/views/livewire/inbox/inbox.blade.php` | Span do SLA sem `whitespace-nowrap` no cabeçalho em 2xl | — |
| L9 | `resources/views/livewire/inbox/inbox.blade.php` | Linha de metadados truncada sem `title` | — |

## Pendências maiores já combinadas (não são menores de revisão)

- Modernizar o `phpmd.xml` com o PHPMD mais recente.
- Futuro app/parte mobile separado (auditoria em `docs/ux-audit/2026-11-15-ux-audit-mobile.md`, branch local `docs/ux-audit-mobile`).
