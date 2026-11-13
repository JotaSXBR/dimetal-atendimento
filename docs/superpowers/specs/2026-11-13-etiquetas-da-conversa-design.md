# Etiquetas da conversa pela tela: design

**Aprovado pelo dono em 13/11** ("pode seguir ate o pr, se tiver menores, corrigir, e falar na mensagem final junto com pr aberto"), depois da pergunta "o atendente pode tirar etiqueta que o bot colocou?" (sim).

## Intenção
Hoje a etiqueta da conversa (`ticket_tag`) só entra pelo nó "Etiquetar" do bot; a pessoa não consegue pôr nem tirar. As 8 etiquetas de conversa padrão ficam sem uso manual.

## Objetivos / não objetivos
- Bloco "Etiquetas da conversa" no painel Detalhes: chips redondos translúcidos, "x" para remover (inclusive as postas pelo bot) e seletor com as etiquetas de escopo conversa que faltam.
- Quem pode ver o ticket aberto pode etiquetar (não só o responsável). Ticket encerrado/histórico: só leitura.
- Cada adição/remoção grava um evento de auditoria (`tag_added`, `tag_removed`, payload `tag_id` e `tag`) e dispara `TicketUpdated` (a Fila atualiza o card).
- Fora: limite por ticket, criar etiqueta dali, atalho na lista da Fila.

## Abordagens
1. **Componente novo `Inbox\TicketTags`** no `x-ticket-details`, espelhando o bloco do contato. Escolhida: escopos separados, testável isolado.
2. Estender o `ContactCard`: mistura dois escopos. Descartada.
3. Seletor no cabeçalho da conversa: cheio em janela estreita. Descartada.

## Decisões minhas (custo se erradas)
- Qualquer um que veja o ticket etiqueta (custo: mais gente mexe; auditoria registra quem).
- A linha "Etiquetas" do bloco Ticket sai (duplicaria o novo bloco).

## Extra pedido pelo dono (13/11)
O botão "Conversar" ao lado de "Editar contato" na ficha do painel é inútil em ticket ativo (abre "Nova conversa" com o contato da conversa já aberta). Verificado: ele só serve em ticket **encerrado** (aba Encerrados do Inbox, para recomeçar), na tela Contatos e na ficha avulsa; no Histórico o rodapé nem aparece. Regra: some só quando a ficha está dentro de um ticket ativo.
