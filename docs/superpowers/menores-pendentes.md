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
| B1 | Painel Detalhes, etiquetas da conversa | Depois de remover o chip, o foco vai para o `BODY` em vez de voltar ao seletor | — |
| B2 | Sino de alertas | Dois alertas "0 mensagens em 0 conversas" apesar do #128: ver se são de antes do deploy ou novos (`systematic-debugging`) | — |
| B3 | Cabeçalho da conversa | Abrir Detalhes reorganiza a barra e o "Encerrar" cai sob o cursor | — |
| B4 | Editor do bot | A barra flutuante cobre o bloco "Início"; o card do fluxo "Padrão" diz "2 setores" e o menu do simulador lista 5 | — |
| B5 | Configuração > Canais, card do canal | O menu "Mais opções" abre e fecha sozinho, sem dar tempo de chegar às outras opções (visto pelo dono) | `systematic-debugging` |

## Menores de UX antigos a reconferir (06/11 a 11/11)

Adiados nas fatias do redesenho (#73, #74, #83, #87, #88, #100). Podem ter sido resolvidos pelas fatias seguintes: conferir antes de corrigir.

| # | Origem | O quê | Decisão |
|---|--------|-------|---------|
| U1 | #83 | `aria-label` do botão "Mais" da Fila sem o nome visível (WCAG 2.5.3) | — |
| U2 | #83 | Faixa do hub de Configuração com rolagem horizontal no celular | — |
| U3 | #83 | Token `ink-foreground` sem uso nas views | — |
| U4 | #83 | Estados de sucesso ainda fora do token `ok` do status-badge | — |
| U5 | #87 | Ordenação das etiquetas sensível a acento | — |
| U6 | #87 | Prefixo da chave `history-*` | — |
| U7 | #87 | `title` duplica o `sr-only` do "+N etiquetas" | — |
| U8 | #88 | Ícone do slot da barra lateral ignora `icon:variant`; `ring-zinc` fixo no ponto da Fila | — |
| U9 | #88 | `basis-56` pode quebrar as ações do cabeçalho em `lg` com Detalhes aberto (ver também B3) | — |
| U10 | #73 | Chevron do menu cobre o fim da 1ª linha em bolha curta | — |
| U11 | #74 | Rascunhos antigos sem limpeza; `localStorage` indisponível não tratado | — |
| U12 | #100 | Estilo do shadow DOM do seletor de emoji sob a CSP em enforce (não aberto no teste de 16/11) | — |

## Pendências maiores já combinadas (não são menores de revisão)

- Modernizar o `phpmd.xml` com o PHPMD mais recente.
- Futuro app/parte mobile separado (auditoria em `docs/ux-audit/2026-11-15-ux-audit-mobile.md`, branch local `docs/ux-audit-mobile`).
