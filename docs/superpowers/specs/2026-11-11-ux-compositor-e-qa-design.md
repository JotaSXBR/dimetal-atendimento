# UX fatias 4 e 5: compositor do Inbox, correções medidas das telas e QA de acessibilidade

Data: 2026-11-11. Só o beta está em escopo. Vem depois da 4b (`2026-11-10-ux-componentes-design.md`). Laravel 13, Livewire 4.4, Flux 2.21 (grátis), Tailwind 4,
Alpine (já no app). Um PR só (decisão do dono).

## Por que

O dono olhou o beta e não gosta de onde ficam os botões da barra de mensagem do Inbox. Medido no app (1280 e 1920 px, escuro e claro): a barra é uma linha só com
`[Responder | Nota privada] [anexo] [áudio] [assinatura] [campo] [enviar]`; em 1280 px o campo de texto sobra com cerca de 190 px, o botão de assinatura parece um
"editar" e não diz o que faz, e não há botão de emoji nem de respostas rápidas (só o atalho `/`). Além disso a fatia 5 original (QA de acessibilidade, tema claro e
telas desalinhadas) nunca foi feita.

## O que a pesquisa e a auditoria mostraram

**Layout de botões em outros produtos** (só o que foi confirmado; Chatwoot, Kommo, respond.io e Wati não foram confirmados e não entram):
- Front (documentação oficial, com imagem): campo em cima; faixa de ferramentas abaixo (formatar, emoji, anexar, modelos, base de conhecimento, apagar) e, à
  direita, "Enter para enviar" e o botão de enviar na ponta.
- Zendesk: anexo, emoji e macros na barra de baixo (macro também por `/`). Intercom: Responder e Nota como abas no alto; ações frequentes fixáveis na barra.
- WhatsApp (Android, beta): dois desenhos testados, ambos com ferramentas à esquerda e só o enviar à direita.
- Regra recorrente: enviar sempre na ponta direita, ferramentas agrupadas à esquerda, margem entre eles (evita envio acidental).

**Auditoria das 16 telas** (Inbox, Contatos, Histórico, Relatórios, Auditoria, Automação lista e editor, as 9 de Configuração), em 360, 768, 1280, 1920 e 2560 px,
nos dois temas, por script no navegador mais inspeção visual:
- Sem rolagem horizontal em nenhuma, exceto o editor de automação em 360 px (143 px a mais; é uma ferramenta de desktop).
- O tema claro aplica e está coerente nas telas inspecionadas; falta o QA fino de contraste (Lighthouse), que esta fatia faz.
- Acessibilidade: 3 campos sem rótulo (busca de Contatos, busca de Histórico e "dias" da importação em Canais) e os botões B/I/S da assinatura em Empresa com 19 a 24 px.
- Alinhamento: as telas de Configuração usam coluna estreita com muito vazio à direita em 1280 px; o Histórico espreme os filtros numa coluna de cerca de 160 px, com a
  conversa vazia ao lado.

## Metas

1. **Compositor em uma linha, estilo WhatsApp Web** (decisão do dono): `[emoji] [anexo] [respostas rápidas] [campo] [mic ou Enviar]`, com as abas `[Responder | Nota privada]`
   no alto, como hoje.
   - O campo ocupa todo o espaço que sobra; em 1280 px fica com mais de 500 px.
   - **Mic vira Enviar** quando há texto no campo ou arquivo na bandeja; sem nenhum dos dois, é o mic. A gravação de áudio, a bandeja de anexos e o "digitando…" ao
     cliente continuam como estão (`voice-recorder.js`, `attach-tray.js`, `typing-presence.js`).
   - **Respostas rápidas por botão** (novo): abre a mesma lista do atalho `/` (`quickReplies()` e `useQuickReply()`); o texto entra no campo, editável. O `/` continua.
   - **Emoji** (novo): botão que abre o seletor e insere o emoji na posição do cursor do campo.
   - **Assinatura sempre ativa** (decisão do dono, para manter o processo da empresa): some o botão e a escolha por atendente. A assinatura vale quando a empresa a liga
     em Empresa e assinatura (um único interruptor, do admin). Não aparece rótulo no compositor.
   - **Modo Nota privada**: somem anexo e mic (como hoje) e ficam emoji e respostas rápidas; o botão final é Salvar nota.
   - **Celular (360 px)**: emoji e anexo visíveis; respostas rápidas vão para um menu "+" só em tela estreita (decisão do dono).
   - Todo botão só com ícone tem `aria-label` e tooltip; foco visível; alvos de pelo menos 32 px.
2. **Correções medidas das telas** (escopo escolhido pelo dono: só o que a auditoria achou, sem redesenho; o layout final ele refaz depois com outras skills):
   - Histórico: filtros num bloco legível (largura útil, sem coluna de 160 px) e a conversa ao lado sem vazio desproporcional.
   - Configuração e Automação: largura de conteúdo que não deixe vazio exagerado à direita em 1280 e 1920; sem mudar o hub nem os textos.
   - Rótulo acessível nos 3 campos sem rótulo e alvo mínimo nos botões B/I/S.
   - O builder reroda o script de auditoria (anexo desta spec, descrito abaixo) e corrige o que mais aparecer dentro desta regra, listando no ledger.
3. **QA de acessibilidade e tema claro** (fatia 5), contra **WCAG 2.2 AA** nas 16 telas, nos dois temas:
   - Lighthouse de acessibilidade em cada tela, nos dois temas; meta: sem falha de contraste, rótulo, nome acessível ou alvo, e pontuação de acessibilidade de pelo
     menos 95 nas telas onde o Lighthouse mede (o builder anota as que o Lighthouse não alcança, como o editor de automação).
   - Teclado: o compositor inteiro se usa sem mouse (Tab, Enter envia, Shift+Enter quebra linha, Esc fecha o seletor de emoji e a lista de respostas), e o foco
     volta ao campo depois de inserir um emoji ou uma resposta rápida.
   - Movimento reduzido: nada novo anima fora de `motion-safe`.
   - Os menores adiados das fatias anteriores entram: `aria-label` do botão "Mais" com o nome visível (WCAG 2.5.3), faixa do hub no celular sem rolagem horizontal, token
     `ink-foreground` sem uso, ícone do slot ignorando `icon:variant`, `ring-zinc` fixo do ponto, ordenação de etiquetas sensível a acento.

## Não metas

- Mudar regra de negócio, dados, rotas, portões `can:*`, tempo real, a lista da Fila ou o fluxo de envio (limites, fila de saída, citação, bandeja).
- Novo layout das telas, nova paleta, biblioteca de ícones nova, Flux Pro, formatação rica no compositor, GIF, base de conhecimento.
- Apagar a coluna `users.sign_messages` (a escolha por atendente sai do código e da tela, a coluna fica sem uso; remover coluna é migração destrutiva e fica para
  uma limpeza com pedido do dono).

## Anti-metas

- O campo de mensagem voltar a ficar espremido em 720p (1280 px) com o painel de contato aberto.
- Enviar sem querer: o botão Enviar fica na ponta, isolado, e Enter/Shift+Enter se comportam como hoje.
- A assinatura deixar de ser aplicada em algum envio (texto, legenda, mensagem iniciada por "Nova conversa").
- O seletor de emoji chamar um serviço de terceiros (CDN, fonte, imagem): tudo servido pelo próprio app.
- Corrigir contraste com cor fora dos tokens do app (`ThemeTokensTest` continua verde).

## Restrições e pesquisa

- **Emoji** (feature nova, pesquisa do `CLAUDE.md`): `emoji-picker-element` (Apache-2.0, v1.29.1, repositório com push em 08/06/2026, 1,7 mil estrelas, web component com
  busca, categorias, tons de pele e recentes, classes `dark`/`light`, evento `emoji-click`, `dataSource` configurável). Dados em português do pacote
  `emoji-picker-element-data` (v1.8.0, Apache-2.0; o pacote inteiro pesa 31 MB com todos os idiomas, então só o JSON `pt` vai para `public/`). Usa IndexedDB no navegador.
  Licença Apache-2.0 é aceita sem consulta (`CLAUDE.md`). Documentação lida pelo Context7 (`/nolanlawson/emoji-picker-element`).
- O seletor mora dentro de um componente Livewire que repinta: o elemento fica sob `wire:ignore` e o texto entra no campo pelo mesmo caminho do `wire:model="draft"`.
- `composer-mode.js` guarda o rascunho por modo; inserir emoji ou resposta rápida respeita o modo atual.
- `Signature::apply` deixa de consultar `User::sign_messages`; `ComposesMessages::toggleSign`, `$sign` e o botão saem. `StartConversation` também usa `Signature` e continua assinando.
- Verificação no **Claude in Chrome** (ou DevTools) em 360, 720p, 1080p e 2K, dois temas; Lighthouse por tela.

## Abordagens consideradas

**Disposição do compositor** (o dono escolheu a 2):
1. Faixa de ferramentas abaixo do campo (Front, Zendesk): campo largo, mas gasta altura em 720p.
2. **Uma linha, estilo WhatsApp Web** (escolhida): compacta e familiar ao atendente de WhatsApp; o campo só ganha largura se as ferramentas forem poucas e enxutas.
3. Manter a linha e só reordenar: mudança mínima, o campo continua estreito.

**Emoji** (o dono escolheu a 1):
1. **`emoji-picker-element` com dados `pt` locais** (escolhida): busca, tons de pele e recentes prontos; custo de 1 dependência npm e 1 arquivo de dados versionado.
2. Interno, cerca de 200 emojis sem busca: zero dependência, mas manutenção nossa e sem busca.

**Assinatura** (decisão do dono, por regra de negócio): chip na linha das abas, menu "+" e botão com rótulo foram descartados; a assinatura é sempre ativa e sem controle
no compositor.

## Deixado ao builder

Ordem exata e espaçamento dos botões dentro da linha; o desenho do menu "+" do celular e do popover do seletor e da lista de respostas (desde que respeitem as regras
acima); como copiar o JSON `pt` para `public/` (passo de build ou arquivo versionado; preferir o mais simples que sobreviva a `npm ci`); o texto dos `aria-label`; os
números de largura máxima do conteúdo das telas de Configuração e Automação; a lista final de correções que o script de auditoria revelar.

## O script de auditoria

O builder rerroda, antes e depois, o script de navegador usado nesta spec (iframes da mesma origem em 360, 768, 1280, 1920 e 2560 px, nos dois temas, para cada uma das
16 rotas): mede rolagem horizontal, controles sem nome acessível, alvos menores que 24 px, imagens sem `alt` e quantidade de `h1`. O resultado de "antes" e "depois"
vai para o ledger. Rotas: `/inbox`, `/contatos`, `/historico`, `/relatorios`, `/auditoria`, `/automacao`, `/automacao/{id}`, `/configuracoes/{setores, horario,
respostas-rapidas, etiquetas, usuarios, canais, google, empresa, retencao}`.

## Entrega

Um PR a partir da `main` (branch novo), com testes de comportamento (assinatura sempre aplicada, botão de respostas rápidas insere o texto, emoji insere na posição do
cursor, mic vira Enviar por estado, modo Nota privada esconde anexo e mic), `ThemeTokensTest` e `ViewConventionsTest` verdes, Lighthouse anexado ao ledger e verificação
no beta com o navegador depois do deploy.
