# Menores pendentes

Achados menores adiados a pedido do dono, para fechar depois. O portão 1 de todo trabalho novo lê este arquivo e pergunta quais
incluir; o item sai no commit que o corrige. **Decisão:** quem precisa responder (dono ou advisor) antes de corrigir.

## Menores de revisão

Nenhum pendente. Os menores do sino (S1 a S4, `fix/ux-sino-alertas`) foram descartados pelo dono em 16/11/2026; o S4 foi
conferido no beta (ticket do histórico abre e mostra o protocolo). Os menores da série "UX simplificação" fecharam em 14/11/2026
(plano `docs/superpowers/plans/2026-11-14-ux-menores.md`).

## Menores adiados do Atendimento padrão (16/11/2026)

Branch `feat/modelo-distribuidora`, revisão final.

| # | Onde | O quê | Decisão |
|---|------|-------|---------|
| A1 | `app/Livewire/Automation/Index.php` (`create`) e `WorkflowTemplates::resolve` | Chave de modelo desconhecida (ex.: `horario`, de uma galeria antiga em cache) cria rascunho em branco sem aviso. Endurecer com `in:` das chaves de `WorkflowTemplates::all()` | dono |

## Achados do teste de UI no beta (16/11/2026)

| # | Onde | O quê | Decisão |
|---|------|-------|---------|

## Menores de UX antigos a reconferir (06/11 a 11/11)

Adiados nas fatias do redesenho (#73, #74, #83, #87, #88, #100). Podem ter sido resolvidos pelas fatias seguintes: conferir antes de corrigir.

Nenhum pendente. U2 fechado (conferido no navegador em 16/11 em 360 px: a página não rola de lado e a faixa rola por dentro); U6 fechado (prefixo `history-*` é só nome, sem conflito).

## Menores adiados da leva de menores baratos (16/11/2026)

Branch `chore/claude-md`, revisão final.

Nenhum pendente. L8 e L9 corrigidos (SLA sem quebra de linha; `title` na linha de metadados).

## Menores adiados da leva 2 (16/11/2026)

Branch `fix/menores-leva-2`, revisão final e conferência visual.

Nenhum pendente.

## Menores adiados do primeiro acesso (17/11/2026)

Branch `feat/assistente-primeiro-acesso`, revisão final.

| # | Onde | O quê | Decisão |
|---|------|-------|---------|
| P1 | `app/Livewire/Setup/FirstSteps.php` (`createChannel`) | Se o request morrer entre criar o canal e gravar `setup.canal`, a retomada cria um segundo canal. Improvável; o canal a mais se apaga em Canais | advisor |
| P2 | Campo de sessão do canal (componente da tela Canais) | `outline: none` no foco; conferir se a borda do campo marca o foco e, se não, dar foco visível | advisor |
| P3 | `/primeiros-passos` | A barra lateral completa aparece durante os primeiros passos (os links voltam ao assistente pelo middleware). Esconder ou manter | dono |

## Pendências maiores já combinadas (não são menores de revisão)

- Modernizar o `phpmd.xml` com o PHPMD mais recente.
- Futuro app/parte mobile separado (auditoria em `docs/ux-audit/2026-11-15-ux-audit-mobile.md`, branch local `docs/ux-audit-mobile`).
