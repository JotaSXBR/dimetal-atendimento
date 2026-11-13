# Etiquetas do contato e novo card da Fila: design

Data: 2026-11-12 (revisada em 13/11 com marca neutra e selo do setor). Estado: **spec escrita, aguardando a aprovação do dono** (sem plano nem código).
Escopo: só o beta. Referência visual: print do Helena CRM salvo pelo dono na raiz (`Captura de tela 2026-11-12 173008.png`).

## Intenção e o porquê

Hoje só a **conversa** (ticket) tem etiqueta. O ticket nasce a cada atendimento, então etiqueta de **cliente** (VIP, revenda,
inadimplente) se perde. O dono quer etiqueta também no **contato**, como no Chatwoot, e quer reorganizar o display do contato
na Fila para mostrar o que o atendente precisa ver de relance, no estilo do Helena. Os canais do futuro (Instagram, Messenger,
como o Helena) não podem ficar presos ao WhatsApp no card.

## O que a pesquisa mostrou (12/11)

- A página pública do Helena (rheis.com.br/helenaapp) é de marketing: cita WhatsApp, Instagram e Messenger e "filas", mas **não
  descreve o card**; a referência do card é só o print do dono.
- Front mantém a lista enxuta, mostra etiquetas só quando a caixa ativa e tem horário relativo; Intercom põe canal, etiqueta e SLA
  mais em filtros; Chatwoot e BotSpace separam **etiqueta do contato** (quem é; segmentação) de **etiqueta da conversa** (assunto; filtro
  da caixa). Guia de design: não mover a conversa de lugar abruptamente enquanto a pessoa age.

## Metas

- Etiquetas do **contato** (catálogo com escopo), aplicáveis na ficha do contato, com filtro em Contatos e na Fila.
- **Etiqueta do contato também** no Histórico (lista e painel), na lista de Contatos e em Nova conversa.
- **Card novo da Fila** estilo Helena: avatar com ícone do canal, nome, chips (contato primeiro), prévia, e coluna da direita com selo
  do setor, tempo relativo e contador de não lidas.
- **Tipo de canal** (`whatsapp` por enquanto) lido pelo card, para outro canal entrar depois sem refazer o card.
- **Marca neutra:** troca dos tokens de cor da marca (amarelo → stone/branco) e do aviso (laranja → âmbar), incluindo o amarelo
  direto em `sidebar.blade.php` e `welcome.blade.php`.
- **Automação:** Etiquetar e Condição "tem a etiqueta X" passam a valer para etiqueta de contato e de conversa.

## Não metas

- Criar canais novos (Instagram, Messenger): só o tipo e o ícone.
- Sincronizar etiqueta do contato com grupos do Google Contatos.
- Contador de não lidas por usuário (decidido: um por ticket).
- Relatórios por etiqueta, etiqueta em massa, etiqueta automática por IA.

## Anti-metas

- Aplicar etiqueta no contato disparar a sincronia com o Google (não pode tocar `edited_at`/`updated_at` do contato).
- Perder etiqueta de contato ao mesclar contatos (lid → telefone) ou ao apagar o contato deixar linha órfã.
- O card estourar a largura em 360 px ou a altura em 720p, ou chip com texto ilegível (contraste AA).

## O que existe hoje (verificado)

- `tags` (`name` único, `color` `#RRGGBB`), pivô `ticket_tag`, `Tag::sortedByName`, tela `Settings\Tags` (supervisor), `Ticket::tags()`.
- Fila: `Inbox::render` monta a lista (`PREVIEW_SQL` por subconsulta, até 2 etiquetas + N no card, filtro `$tagId` só por etiqueta do ticket).
- Ficha: `Contacts\ContactCard` (compacta/completa/só leitura). Mesclagem: `MergeLidContact`. Google: `SaveContact`/`scopePendingGoogle`.
- Automação: nós `EtiquetarNode` e `CondicaoNode`, efeito `LiveEffects::tag(Tag)` (hoje só `ticket->tags()`).
- "Visto": `MarkTicketSeenJob` sai ao abrir o ticket (envia o visto ao WhatsApp). `messages.read_at` é a leitura **do cliente** das
  nossas mensagens, não serve para "não lidas". `channels` não tem tipo (só `waha_session`).
- Regras já existentes do card: selo do número só com mais de um número ativo; setor só com mais de um setor recebendo tickets.

## Decisões do dono (12/11)

1. **Catálogo com escopo (A2):** cada etiqueta é *do contato* ou *da conversa*; a tela de Etiquetas mostra duas listas; as atuais viram "da conversa".
2. **Card estilo Helena (A):** avatar + ícone do canal, chips sob o nome, prévia, coluna da direita (setor, tempo, não lidas).
3. **Chips:** do contato em chip sólido e primeiro; da conversa com contorno; até 3 + "+N" (a lista completa no `title`).
4. **Na coluna da direita e no avatar:** contador de não lidas, selo do setor, ícone do canal, tempo relativo ("há 3 min").
5. **Não lidas calculadas:** `tickets.agent_seen_at`; não lidas = mensagens do cliente depois dele (subconsulta como a da prévia).
6. **Entra tudo numa spec só:** etiquetas do contato (ficha e Contatos), card novo, tipo de canal e automação.
7. **Selo do setor só com mais de um setor recebendo tickets** (a regra de hoje fica).
8. **Quem edita o contato** aplica e remove etiqueta de contato; o catálogo continua do supervisor para cima.
9. **Cor por paleta fixa:** acaba a cor livre (#RRGGBB). A paleta é de tons que não se confundam com as cores de estado do app (verde de ok, âmbar de aviso, vermelho de perigo) nem briguem com os temas claro e escuro, todas com texto legível (AA). Já que a marca vira neutra (decisão 11), ela também não compete com os chips.
10. **A etiqueta do contato aparece também** no Histórico (lista e painel), na lista de Contatos (chips na linha, além do filtro) e em Nova conversa (escolher contato).

11. **Marca neutra (13/11), entra nesta spec:** o amarelo da marca sai. Tokens `accent*` passam a: claro `accent` e `accent-content` = stone-800, `accent-foreground` = branco; escuro `accent` e `accent-content` = branco, `accent-foreground` = stone-800 (o trecho `@theme` que o dono mandou, só que em `stone`, porque o projeto já remapeia `zinc` para `stone`); `accent-soft` vira stone-100 no claro e stone-800 no escuro (do plano, conferir o contraste). Verde, amarelo e vermelho ficam só para estado e alerta. O mesmo tom sólido vale para botão primário, item ativo do menu e contador de não lidas.
12. **Âmbar volta a ser o aviso (13/11):** `warn` deixa o laranja-escuro (que existia para se afastar do amarelo da marca) e vira âmbar, com os pares de texto medidos no `ThemeTokensTest`.
13. **Selo do setor (13/11):** opção A, só com mais de um setor recebendo tickets (a regra de hoje), conforme os mockups.

14. **Forma dos chips (13/11, pedido do dono):** etiqueta do contato é **quadrada e cheia**; etiqueta da conversa é **redonda e translúcida** (fundo do tom a 15% e contorno a 50%). Substitui o "cheio contra contorno" da decisão 3; o texto de cada tom é medido sobre o fundo translúcido no `ThemeTokensTest`.

## Minhas decisões (conferir)

1. **Nome único global** em `tags` (mantém o índice atual): a mesma palavra não existe nos dois escopos, para filtros e chips não confundirem. Coluna nova `tags.scope` (`contato`|`conversa`, padrão `conversa`).
2. **Pivô `contact_tag`** (`contact_id`, `tag_id`, sem timestamps no contato). Aplicar ou tirar etiqueta **não toca** o contato (nada de `touch`), para não reenviar ao Google.
3. **Quem aplica:** quem pode editar o contato (ficha não somente leitura) aplica e remove etiqueta de contato; criar/editar/apagar do catálogo segue supervisor ou mais. Escopo da etiqueta **não muda** depois de criada se estiver em uso (evita virar etiqueta de contato com tickets ligados); apagar remove os dois pivôs.
4. **Automação sem campo de destino:** o escopo da etiqueta decide onde aplica. Etiquetar de escopo *contato* grava em `contact_tag`, de escopo *conversa* em `ticket_tag` (nós antigos continuam valendo: suas etiquetas são "da conversa"). Condição "tem a etiqueta X" checa o contato ou o ticket conforme o escopo da etiqueta X. **Atenção (verificado em 13/11):** essa condição não existe hoje (o bloco Condição só testa horário, nome, retorno e variável); esta fatia a cria.
5. **Fila:** o filtro por etiqueta passa a casar etiqueta de contato (via ticket → contato) ou de conversa, conforme o escopo escolhida. Contatos ganha o mesmo filtro (só etiquetas do contato).
6. **Mesclagem de contatos:** `MergeLidContact` faz a união das etiquetas do contato antes de apagar o lid.
7. **Não lidas:** `agent_seen_at` é preenchido quando **o responsável pelo ticket** abre ou responde (junto do agendamento do `MarkTicketSeenJob`); em ticket sem responsável, quando qualquer pessoa abre. Quem só espia (supervisor, admin) não zera o contador do atendente. Ticket sem valor conta todas as mensagens do cliente. Ao mudar, dispara `TicketUpdated` para a Fila de todos se atualizar. Sem contador gravado para dessincronizar.
8. **Tipo de canal:** `channels.kind` (`whatsapp` padrão) com enum `ChannelKind` (`label()`, ícone); o ícone vai sobre o avatar em todo card. A regra "selo do número só com mais de um número" continua; **setor** continua só com mais de um setor recebendo (regra atual), (o dono confirmou, decisão 13).
9. **Tempo relativo** recalculado no navegador a partir do instante ISO (como o sino), sem congelar entre as releituras do servidor; ponto de SLA continua.
10. **Cor dos chips:** a cor da etiqueta (da paleta fixa) é o fundo (sólido) ou a borda (contorno); cada cor da paleta define o seu texto e o teste confere o contraste AA nos dois temas. A paleta (uns 12 tons, sem verde, âmbar nem vermelho de estado) é desenhada na execução e as etiquetas atuais viram o tom mais próximo; `tags.color` passa a guardar o nome do tom.

## Abordagens consideradas

- **Catálogo:** A1 um catálogo e duas ligações (Chatwoot) vs **A2 catálogo com escopo (escolhida)**: mais claro, custa migrar as atuais.
- **Não lidas:** **calculado (escolhido)** vs contador no ticket (pode dessincronizar) vs por usuário (pesado; o ticket tem um atendente).
- **Escopo:** tudo numa spec; o plano decide a ordem de execução (sugestão: dados e ficha; depois card e canal; depois automação).

## O que fica para o construtor/plano

- Textos, layout exato dos chips e da coluna da direita; verificar em 360 px, 720p, 1080p e 2K, tema claro e escuro (ver `resolucoes-720p-1080p-2k`), e a guarda `ThemeTokensTest`/`AlpineCspSubsetTest`.
- Migração (`tags.scope`, `contact_tag`, `tickets.agent_seen_at`, `channels.kind`) e o que ela faz com dados existentes; índice para a subconsulta de não lidas.
- Como a ficha escolhe a etiqueta (menu com busca) e o limite de etiquetas por contato.
- Impacto nos testes existentes do card (`InboxQueueCardTest`) e do `MergeLidContact`.
- A paleta concreta (nomes e valores dos tons, claro e escuro) e a migração das cores atuais para o tom mais próximo.

## Pendente

Aprovação desta spec pelo dono. Depois: plano (`writing-plans`), execução inline e revisão por Sonnet 5.5 medium, como no backup.
