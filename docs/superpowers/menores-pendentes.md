# Menores pendentes

Achados menores adiados a pedido do dono (14/11/2026), para fechar depois. O portão 1 de todo trabalho novo lê este arquivo e
pergunta quais incluir; o item sai no commit que o corrige. **Decisão:** quem precisa responder (dono ou advisor) antes de corrigir.

## Série "UX simplificação" (spec `2026-11-14-ux-simplificacao-design.md`)

As fatias A, B e C fecharam sem menores. As fatias D, E e F estão abaixo.

### Fatia D: confirmações e Desfazer (PR #122, branch `feat/ux-confirmacoes-e-desfazer`, 14/11)

| # | Onde | O quê | Por quê / cenário | Decisão |
|---|------|-------|-------------------|---------|
| D1 | `resources/js/confirm-dialog.js` (listener de clique) | O clique passa sem confirmar se o layout não tem `#confirm-dialog` | Hoje só o layout da barra lateral tem `data-confirm`; vira risco numa tela futura com outro layout. Opção: bloquear o clique quando o diálogo falta (falha fechada) | Advisor: falhar fechado ou aberto? |
| D2 | `resources/js/confirm-dialog.js` (`pending`) | Nó reaproveitado pelo morph do Livewire com o diálogo aberto confirma a ação de outro item | Listas sem `wire:key` (setores, etiquetas, respostas rápidas); o risco é baixo mas a ação é destrutiva. Opção: guardar o `wire:click` (texto) e comparar ao confirmar | Dono: vale a pena? |
| D3 | `app/Livewire/Inbox/Transcript.php` (`restoreNote`) | Não revalida usuário ativo nem ticket ainda existente/legível (ticket mesclado dá erro de FK) | Janela de 60 s, cache por usuário; impacto pequeno | Advisor |
| D4 | `app/Livewire/Inbox/TicketTags.php` (`undoRemove`/`removeTag`) | `removeTag` aceita qualquer escopo de etiqueta e o Desfazer só `ofTicket()`; 403 em ticket encerrado vira modal de erro do Livewire | Toast promete algo que não acontece; trocar o 403 por no-op silencioso no listener | Dono: silencioso ou erro? |
| D5 | `resources/js/confirm-dialog.js` (rótulo do botão) | `textContent` apaga o `<span>`/indicador de loading do botão Flux | Só cosmético; o diálogo não usa loading | — (pode virar "não fazer") |

### Fatia E: editor do bot (PR #123, branch `feat/ux-editor-do-bot`, 14/11)

| # | Onde | O quê | Por quê / cenário | Decisão |
|---|------|-------|-------------------|---------|
| E1 | `resources/js/flow-editor.js` (`variableOptions`) | A lista do "Inserir variável" não reage a editar a variável da própria pergunta aberta (só após reselecionar o bloco) | `api.getGraph()` não é reativo | Advisor: tornar reativo ou aceitar? |
| E2 | `resources/js/flow-editor/editor.js` (`fitView`) | Sem teste automatizado da geometria (âncora do Início a 24 px, ordem `zoom` → `translate`) | Verificado só no Chrome | Dono: vale Playwright/Dusk? (mesma pergunta já aberta para o Alpine) |
| E3 | `resources/js/flow-editor/editor.js` (`fitView`) | Fluxo sem bloco Início não recebe o piso de zoom | Alternativa: ancorar no canto do `boundingBox` | — |
| E4 | `resources/views/livewire/automation/editor.blade.php` (select dentro do `<label>`) | O nome acessível do textarea inclui "Inserir variável…" | Mover o select para fora do `<label>` ou usar `aria-labelledby` | — |
| E5 | `app/Services/Bot/Nodes/PerguntaNode.php` / `FlowValidator` | O campo "Guardar a resposta na variável" aceita nome que o bot não resolve (maiúsculas, espaços, `_` no início) | O seletor já esconde esses nomes; o certo é validar no servidor | Dono: validar e recusar a publicação? (muda o que é publicável) |

### Fatia F: gráfico de barras nos Relatórios (branch `feat/ux-grafico-relatorios`, 14/11)

Preencher ao fechar a fatia (PR em andamento).

## Pendências maiores já combinadas (não são menores de revisão)

- Achados do sino de alertas (alertas duplicados, "0 mensagens", clique no alerta de SLA abrir o ticket): depois da fatia H.
- Modernizar o `phpmd.xml` com o PHPMD mais recente (nota em memória, 14/11).
- Futuro app/parte mobile separado.
