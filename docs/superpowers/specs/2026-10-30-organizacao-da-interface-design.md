# Organização da interface: menu, painel de detalhes e linha do tempo (design)

Data: 30/10/2026. Branch: `feat/menu-e-componentes-de-tela`. Um PR só (pedido do dono).

## Intenção

Quem usa o app (atendente, supervisor, admin) se perde com muita informação ao mesmo tempo: menu com itens repetidos e links
do starter kit, uma ficha de contato sempre aberta com 10 campos, e uma linha do tempo com JSON cru (`{"from_sector":2,...}`),
ids no lugar de nomes e uma linha por mensagem. O objetivo é mostrar primeiro o que importa, em frases, e deixar o resto a um
clique. **Só apresentação**: nada muda no funcionamento (permissões, SLA, eventos gravados).

## Não objetivos

- Visões salvas, painéis redimensionáveis, menu personalizável por usuário.
- Mudar o que é gravado nos eventos ou a ordem da fila (vai para PR futuro, abaixo).
- Tirar o formulário de edição da tela Contatos (lá o foco é editar: continua aberto).

## Decisões do dono

1. Painel direito do Inbox mostra sem clicar: quem é o cliente, histórico do cliente, observações do contato e dados do
   ticket. Um botão **Editar** abre os demais campos para preencher e salvar.
2. Ticket anterior na lista do cliente: clique abre o **Histórico** já naquele ticket.
3. Status do Google na ficha: só quando há problema (aviso com "Reenviar"); o detalhe completo fica dentro do Editar.
4. Celular: botão **Detalhes** no cabeçalho abre o painel por cima da conversa, com voltar.
5. Linha do tempo do Histórico: só o que mudou o ticket, em frases com nomes. Sem linhas de mensagem.
6. Auditoria (admin) usa as mesmas frases, mas mantém **todos** os eventos, inclusive mensagens.
7. Menu lateral: reorganização proposta (abaixo). Tudo numa branch e num PR.

## Abordagens

1. **Estender o que existe (escolhida).** `ContactCard` ganha modo leitura com botão Editar; uma classe de frases dos eventos
   (usada pelo Histórico, pelo Inbox e pela Auditoria) busca nomes de setor e de pessoa em lote; os dois painéis de detalhes
   viram um componente Blade único.
2. Painel como componente Livewire novo: mais limpo, mas duplica as regras de permissão do Inbox. Descartada.
3. Só views com Alpine: mais rápido, mas as frases ficariam repetidas no Blade. Descartada.

## Design

### Menu lateral (`layouts/app/sidebar.blade.php`)
- Grupo **Atendimento**: Atendimento (com contador de aguardando e de SLA estourado), Contatos, Histórico. "Meus tickets" sai do
  menu (já é aba do Inbox). Título "Platform" some.
- Grupo **Gestão** (supervisor/admin): Relatórios; Auditoria (admin).
- Grupo **Configurações** em subgrupos: *Operação* (Canais, Setores, Horário, Respostas rápidas), *Pessoas e empresa* (Usuários,
  Empresa), *Dados e integrações* (Google Contatos, Retenção). Respeita as permissões atuais; Retenção troca de ícone.
- Rodapé: tiram-se "Repository" e "Documentation"; aviso **WhatsApp desconectado** quando há canal e nenhum está conectado.
- `/` redireciona para `/inbox`.
- `App\Support\SidebarState`: `counts(User)` (aguardando e SLA estourado, só o que o usuário vê) e `whatsappDown()`.

### Abas do Inbox
Aguardando e Meus passam a mostrar o contador ao lado do nome, na mesma regra de visibilidade (`visibleTo`).

### Painel de detalhes (Inbox e Histórico)
Componente Blade `components/ticket-details`. Ordem: cliente (nome, empresa, telefone) → dados do ticket (setor, responsável,
aberto em, SLA ou motivo de encerramento) → observações → tickets anteriores (data, setor, motivo; link para o Histórico) →
Editar. Sem repetir Canal/Protocolo/telefone que o cabeçalho já mostra (Canal fica nos dados do ticket). Recolhível com o estado
lembrado no navegador (Alpine + `localStorage`), com botão `aria-expanded`/`aria-controls`. Em tela pequena abre por botão
**Detalhes**. No Histórico é somente leitura e inclui a linha do tempo.

`ContactCard` recebe `compact` (true no Inbox): mostra o resumo e o botão Editar, que abre o formulário atual; o aviso do Google
só aparece com erro. Em Contatos (`compact` false) nada muda.

### Linha do tempo
`App\Support\TicketTimeline` transforma eventos em frases: "Ana assumiu", "Ana transferiu de Comercial para Financeiro (nota)",
"Ana reatribuiu para Beto", "SLA estourado (esperando desde 10:00, limite 10:10)", "Encerrado por Ana: Resolvido". Resolve
nomes de setor e usuário com uma consulta por tipo (sem N+1). Setor ou pessoa apagados: "setor removido"/"usuário removido".
Histórico filtra `message_in`/`message_out`; Auditoria mostra todos, com "Mensagem enviada/recebida" e sem JSON. Mantém o
rótulo atual (`TicketEventText::label`) como base.

### Telas e componentes
Componente `x-page-header` (título, subtítulo, ação) usado em Relatórios, Contatos, Auditoria e nas configurações. Larguras:
formulários `max-w-3xl`, listas e relatórios `max-w-6xl`. Histórico/Inbox (tela cheia) ficam fora. `x-empty-state` e
`x-status-badge` só onde já há duplicação (selos de SLA no Inbox e nos Relatórios). Dividir o Inbox em subcomponentes fica de fora
deste PR (risco alto, ganho só de manutenção).

### Histórico: abrir direto num ticket
`ticketId` passa a ser `#[Url]` em `History\Index`; a autorização de `select` continua valendo ao abrir pela URL.

## Para um PR futuro (muda funcionamento)
- Ordenar a fila Aguardando por prazo de SLA.
- Gravar nome de setor e de pessoa no payload do evento, para o texto passado não mudar com renomeação.
- Dividir o Inbox (374 linhas) em subcomponentes.
- Visão do celular completa (hoje só o botão Detalhes).

## Testes
Frases de cada tipo de evento e nomes removidos; Histórico sem linhas de mensagem e Auditoria com todas; painel com Editar
recolhido e aviso do Google só com erro; Contatos com formulário aberto; menu por papel; contadores; aviso do WhatsApp;
`/` → `/inbox`; `ticketId` pela URL respeita permissão.
