# Auditoria de UX, fechamento (12/11/2026)

Local (`dimetal_local`, porta 8123), dado realista: 20 tickets, contatos com nomes de até 70 caracteres, etiquetas e setores.
`docs/superpowers/audit/ux-audit.js`: 16 rotas × 360×800, 1280×720, 1920×1080 e 2560×1440 × claro e escuro. Mais captura de
tela, em claro e 1280×720, de Contatos, Fila (com e sem conversa), Setores, Relatórios, Histórico, Canais, Horário, Empresa e
Auditoria, e da Fila em 2560×1440.

## Antes

| Tela | Resolução | Achado |
|---|---|---|
| Contatos | 1280×720 | rolagem horizontal de 125 px, painel de detalhe cortado |
| Fila | todas | documento de 1682 px (rola ~950 px de branco): `sr-only` absoluto escapa da lista rolável |
| Fila e Histórico | 360×800 | documento de 856 px (56 px a mais): cabeçalho mobile somado ao `h-dvh` |
| Canais | 1280×720 | "dias" separado do campo "5" (quebra de linha) |

## Correções

- `contacts/index.blade.php`: `minmax(0,1fr)` na grade.
- `inbox/inbox.blade.php`: lista da Fila `relative`.
- `layouts/inbox.blade.php`: `h-[calc(100dvh-3.5rem)] lg:h-dvh`.
- `settings/channel-card.blade.php`: campo e "dias" agrupados.

## Depois

Script: nenhum achado de rolagem horizontal ou vertical de documento em Contatos, Fila e Histórico nos dois temas e em todas as
resoluções (docH igual à altura da janela). Resta só o editor de automação em 360 px (`ox=143`), ferramenta de desktop, conhecido.

## Não é defeito

- 21 px de rolagem vertical em 720p nas telas de Configuração: a navegação do hub (11 itens) mais o padding da página somam 741 px.
- Contatos tem rolagem de documento (lista longa): é página rolável.

## Limites

- Fila em 2560×1440: o texto das mensagens fica à esquerda numa coluna larga; é o layout atual (o dono refaz o layout final).
- "Sem conexão com o servidor" na Fila local: o Reverb não roda nesse ambiente.
- Tema escuro foi medido pelo script, não revisto em captura nesta passada (o contraste dos tokens tem teste automático).
