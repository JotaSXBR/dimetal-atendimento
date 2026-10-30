# Correções futuras do frontend (30/10/2026)

Origem: comparação entre as telas do arquivo `Dimetal.pen` (Pencil) e o app no beta. O dono vai refazer o layout depois, com
outras skills de frontend e possivelmente mudando lógica; nada aqui está implementado. A mesma lista está no frame "Notas -
correcoes futuras do frontend" do Pencil.

## Já repassado ao design nesta passada
- "Meus tickets" removido das 16 barras laterais (segue como aba da lista do Inbox).
- Menu agrupado como no app: Atendimento (Atendimento, Contatos, Histórico) e Gestão (Relatórios, Auditoria, Fluxos, Configurações); item ativo corrigido por tela.
- Inbox: abas Todos, Aguardando 2, Meus 2, Encerrados; filtro "SLA" e ordenação "mais antigos primeiro" ficaram ocultos.
- Painel de detalhes: Ticket, Cliente, Observações, Atendimentos anteriores, Editar contato.
- Tela "Ticket encerrado": título "Fila".

## Bem feito no design e diferente no app (candidatos)
1. Barra lateral escura verde, com marca e contador âmbar (o app usa o padrão claro do Flux).
2. Configurações como um item só do menu, com abas no topo (Canais, Equipe e setores, Horários, Respostas rápidas, Empresa).
3. "Equipe e setores" numa tela, com coluna lateral de setores e "o que cada perfil pode".
4. Canais: faixa de aviso quando um canal cai, e cartões com QR.
5. Painel do ticket: bloco "SLA em risco", anexos e tags.
6. Ficha do contato: cabeçalho com tags e tabela de atendimentos.
7. Relatórios: gráfico de barras por setor, ranking por atendente e cartões.
8. Meu perfil: notificações, sessões ativas e troca de senha.
9. Encerrar ticket em modal.
10. Lista do Inbox: filtro "SLA" e ordenação "mais antigos primeiro".

## No design, sem funcionalidade no app (não é só frontend)
Fluxos de pré-atendimento e Editor de fluxo (bot); tags; Exportar CSV (Contatos, Relatórios, Auditoria; fora do escopo da v1);
notificações do perfil e sessões ativas; ordenar a fila por prazo de SLA.

## Falta no design
Tela do Histórico (lista, filtros, painel com a linha do tempo em frases); aviso "WhatsApp desconectado" no menu e na caixa de
resposta; botão "Detalhes" e painel por cima da conversa no celular; estados vazios e de erro; painel de detalhes recolhido.

## Defeitos pequenos vistos no beta (ainda abertos)
Aba "Meus" quebra em duas linhas; menu mostra "0 aguardando" quando só há SLA estourado; SLA aparece no cabeçalho e no painel do
ticket; botão que recolhe o painel é só um ícone "i" sem texto.

## Atualização: retrabalho no Pencil (mesmo dia)
- A barra lateral virou componente (`Sidebar`, `Nav Item`, `Nav Item Active`, `Nav Group Label`, frame "Componentes"); as 16 cópias viraram instâncias, com o item ativo por tela e o papel do usuário como Administrador.
- Nova tela "Historico": lista com filtros e paginação simples, conversa somente leitura, painel com linha do tempo em frases.
- Ainda como componente a fazer: cabeçalho de página, abas de Configurações, cartão, selo de status, botões.
- Observação: o Pencil marca "partially clipped" nas instâncias da barra dentro das telas, mas as capturas mostram tudo certo.

## Atualização 2: segunda passada no Pencil
- Componentes novos em "Componentes - base": botões (primário, secundário, ghost, destrutivo), selos (neutro, conectado, perto de estourar, SLA estourado, bot), abas (ativa e inativa), cabeçalho de página, avisos (atenção, erro, info) e estado vazio. 32 botões e as abas das telas de Configurações viraram instâncias.
- Telas novas: Config - Google Contatos, Inbox - canal desconectado, Inbox - fila vazia, Inbox - painel recolhido, Celular - fila, Celular - detalhes.
- Configurações em 7 abas: Canais, Horário, Equipe e setores, Respostas rápidas, Empresa, Google Contatos, Retenção.
- Acentos corrigidos em 210 textos. Frame novo "Guia - Pencil para o app" com o mapa de rotas, de componentes e as regras de design.
- Ainda de fora: estados de erro e de carregamento, cabeçalho de página aplicado nas telas, cartão, tabela e campo como componentes, e refazer as telas de Relatórios, Contatos e Perfil com esses componentes.
- Ideia para depois: atalhos de teclado na lista de tickets (próximo, assumir, encerrar).

## Atualização 3: fechamento do trabalho no Pencil
- Mais componentes em "Componentes - base": Card, Field, Toggle Row, Skeleton Row, Stat, Table Header Row, Table Row e Table Footer.
- 12 cabeçalhos de página viraram instâncias de `Page Header` (com as abas de Configurações e os botões nos slots).
- Tela nova "Estados do sistema": carregando, vazio, erro, sucesso e sem permissão.
- Texto mínimo de 10 px no arquivo todo (512 textos de 9 px subiram) e itens do menu com 40 px.
- Ainda de fora: aplicar Card, Field e Table nos blocos antigos das telas; Fluxos e Editor de fluxo seguem como desenho futuro; contraste só conferido a olho.
- O frame "Guia - Pencil para o app" resume rotas, componentes, tokens e regras para quem for reconstruir no app.

## Atualização 4: retrofit das telas antigas no Pencil
- 23 cartões e painéis viraram instâncias de `Card` (cabeçalho com título, subtítulo e ações; conteúdo em encaixe). O `Card` foi redefinido para o padrão das telas antigas: sem espaçamento interno, linhas com divisória.
- 21 campos viraram instâncias de `Field`; 4 indicadores dos Relatórios viraram `Stat`.
- Tabelas: cabeçalho, linhas e rodapé viraram instâncias (5 cabeçalhos, 32 linhas, 3 rodapés). As células ficam dentro do encaixe `Cells`, então cada tabela mantém suas colunas.
- Ainda soltos: textareas, seletores de período e toggles isolados; Fluxos e Editor de fluxo; variantes de erro e de carregamento por tela; contraste só conferido a olho.
