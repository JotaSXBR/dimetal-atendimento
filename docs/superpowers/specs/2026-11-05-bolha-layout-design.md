# Layout das bolhas da inbox: design curto

**Intenção.** Tirar o espaço em branco que o "⋯" cria em toda mensagem e aproximar a bolha do WhatsApp Web.

**Fora do escopo.** Encaminhar, fixar, favoritar, selecionar, transmissão; notas internas e rascunho (fatia 4, onde entra "Adicionar texto às notas").

## O que muda (`resources/views/livewire/inbox/transcript.blade.php`)
1. Chevron no canto superior direito da bolha, `absolute`, visível ao passar o mouse ou com foco (no toque, sempre visível e discreto). A linha própria do menu some.
2. Horário e tiques flutuam à direita na linha do fim do texto (`float`); caem para a linha de baixo quando o texto é longo. Mídia, documento e citação mantêm o horário embaixo.
3. Menu: pílula de emojis no topo, lista com ícones, filete antes de "Apagar para todos". Posicionamento `fixed` atual mantido.
4. Itens novos: **Copiar** (texto, via clipboard do navegador) e **Dados da mensagem** (enviada, entregue, lida e horários, de `delivery_status` e dos horários já gravados).
5. Botão de carinha fora da bolha, no lado de dentro da conversa, ao passar o mouse: abre a pílula de reações (mesma ação `react`, mesma policy).

## Abordagens
A) só mover o "⋯" para a linha do horário (menor, menos descobrível); B) chevron + horário flutuante + extras acima (escolhida pelo dono em 05/11, em duas etapas: 1-4, depois 5, no mesmo branch).

## Decisões minhas (custo se erradas: só CSS/Blade)
- Sem teste automatizado do CSS; testes de servidor para "Dados da mensagem" e para a presença dos itens por permissão.
- Sem biblioteca nova.
