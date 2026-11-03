# Inbox completa: bot humanizado, presença do cliente e menu da mensagem — design

Data: 2026-11-03
Status: **aguardando aprovação do dono** (nada foi construído). Próximo passo depois de aprovado: `superpowers:writing-plans`.

## 1. Por que

O inbox já recebe, responde, transfere e audita. Faltam as pontas que o atendente sente todo dia e que o WhatsApp Web
resolve: o cliente não vê "visto/digitando" do bot, o atendente não vê o cliente digitando, não dá para citar, reagir,
editar nem apagar o que enviou, e não há nota interna, busca na conversa nem como iniciar conversa com um contato.
O dono quer fechar o inbox de uma vez, no estilo do WhatsApp Web, sem enfraquecer a auditoria.

## 2. Metas, não-metas e anti-metas

**Metas.** Seis fatias independentes (cada uma mergeável sozinha, na ordem da seção 6). Comportamento igual ao do
WhatsApp Web onde o WAHA permite. Histórico nunca perdido: editar e apagar enviam ao WhatsApp, mas o painel guarda o
original, como já faz com as mensagens do cliente.

**Não-metas.** Grupos. Botões interativos. Encaminhar mensagem. Campanhas e envio em massa. Salvar presença no banco.
Limpeza da memória e do disco do WAHA (fica para o RustFS/S3, antes do v1).

**Anti-metas (é fracasso mesmo que funcione).** Editar ou apagar sumir do histórico do painel. O bot atrasar as
respostas humanas. Uma assinatura de presença derrubar o limite do webhook. Nota interna ser enviada ao cliente por
engano. Iniciar conversa criar ticket sem dono nem rastro.

## 3. O que o código faz hoje (verificado)

- `BotEngine::say` grava a mensagem e despacha `SendTicketMessageJob` direto. `SendTicketMessage::deliver` só faz
  visto e "digitando" quando `origin != BOT` (decisão anterior, revertida aqui).
- `Presence::before` já faz visto (com ids completos), "digitando" com `TypingDelay` (1 a 6 s) e falha mole (`attempt`).
- O webhook assina `presence.update` (`WahaClient`), mas `HandleWahaEvent` não trata o evento. Nunca assinamos presença
  por conversa. **Sondagem de 03/11 no beta:** digitar e gravar no celular do cliente não gerou nenhum `presence.update`.
  Sem assinatura o WAHA não entrega presença.
- `matchPending` casa o eco do bot por 600 s; `freshAt` usa `received_at` ao vivo, mas `BotEntry::isFresh` mede contra
  `now()`, e fila `inbound` atrasada mais de 5 min pula o bot.
- Recebemos reações, edições e apagamentos do cliente (`ApplyMessageChange`); não guardamos citação (`reply_to` não
  existe) e o painel não envia reação, edição, apagamento nem citação.
- Ticket é por contato e canal (`openTicketFor`): conversa aberta em um número não esconde a do outro.
- Fila `outbound` tem um processo só, que preserva a ordem por conversa.

## 4. Abordagens consideradas (decididas com o dono)

**Presença do cliente.** (A, escolhida) assinar todo ticket aberto e entregar pelo Reverb no canal `private-inbox`, sem
banco, com expiração no navegador (~10 s). (B) assinar só a conversa aberta: descartada, a lista da fila não mostraria
nada. (C) Redis com polling: descartada, já temos Reverb.

**Bot humanizado.** (A, escolhida) o bot usa o mesmo caminho e a mesma fila `outbound` do humano, com teto de
"digitando" menor (~3 s) e visto uma vez por rodada. (B) fila e processo próprios: só se o volume do bot crescer.

**Menu da mensagem e extras.** Decididos pelo dono em 03/11: citação completa (receber e enviar, como no WhatsApp Web),
menu por mensagem com Responder, Reagir, Editar e Apagar (original mantido), notas internas, adicionar aos contatos com
**iniciar conversa pelo app**, busca na conversa e rascunho por ticket.

## 5. Desenho por fatia

### Fatia 1: bot humanizado, frescor e prévia

- `deliver` deixa de excluir `BOT` da presença. Para o bot: `sendSeen` **uma vez por rodada** (antes da 1ª mensagem do
  lote, não por mensagem), "digitando" antes de cada mensagem, tempo = `TypingDelay` com teto de ~3 s. Falha de presença
  nunca impede o envio (já é `attempt`). Chamada de visto com teto de tempo curto, para um WAHA lento não segurar a fila.
- A resposta de fora do horário (canal sem workflow) segue o mesmo caminho.
- Eco `fromMe` do bot, inclusive de contato `@lid`, chegando durante o "digitando" **não** pode chamar `humanTookOver`.
- Frescor: `BotEntry::isFresh` passa a comparar a hora de **chegada do webhook** (`received_at`) com o relógio do job, não
  `now()` com a hora de envio; um atraso da fila `inbound` não derruba mais o bot.
- Prévia da fila: converter a formatação do WhatsApp (`*negrito*`, `_itálico_`, `~riscado~`, crases) com o mesmo
  `WhatsAppText` do transcript, sem HTML cru.

### Fatia 2: presença do cliente (lista e conversa)

- Ao abrir ticket e na reconexão do canal, assinar `POST /api/{sessão}/presence/{chatId}/subscribe` para cada ticket
  aberto (job idempotente, fila `realtime`, falha mole, nova tentativa na reconexão).
- `HandleWahaEvent` trata `presence.update`: normaliza para `{ticket_id, estado: digitando|gravando|parou}` e transmite
  por evento `ClientPresence` no `private-inbox`, **sem gravar** `WahaEvent` desse tipo além do necessário. A primeira
  tarefa do plano assina e registra o payload real no beta (inclusive `@lid`); o parser tolera as variações de formato.
- Webhook: `presence.update` não conta no limite de 300/min do webhook (ou tem limite próprio), para não sufocar mensagens.
- Tela: "digitando…" ou "gravando áudio…" na linha do ticket da lista e no cabeçalho da conversa. O estado **não tem
  duração fixa**: segue os eventos, como o WhatsApp Web. Entra com `typing`/`recording` e sai com `paused`, `online`,
  `offline` ou quando chega mensagem daquele contato; um novo `typing` renova. Só existe um tempo de segurança para o caso de
  o `paused` nunca chegar, e ele **vem de medição, não de palpite** (ver abaixo). O contato que não compartilha presença
  simplesmente não aparece.
- **Pesquisa que embasa isto (03/11):** a doc do WAHA define o payload (`payload.id` = chat; `payload.presences[]` com
  `participant`, `lastKnownPresence` ∈ `online|offline|typing|recording|paused`, `lastSeen`), manda assinar por conversa
  (`POST /presence/{chat}/subscribe`), diz que `paused` encerra o "digitando" e **não documenta nenhuma duração do lado do
  WhatsApp**; marca GOWS como suportado. Bibliotecas de referência (Baileys) citam expiração de ~10 s sem renovação, fonte
  secundária e não confirmada. A issue pública devlikeapro/waha#1973 (GOWS, WAHA 2026.3.2) relata `presence.update` sem
  disparar; foi fechada sem correção de código, com a orientação de assinar a conversa antes e usar presença "online" global.
- **Risco real ligado à nossa configuração:** `WAHA_PRESENCE_AUTO_ONLINE=False` (decisão para o celular do atendente continuar
  recebendo push) mantém a sessão "offline", e o WhatsApp só entrega a presença dos outros a quem está online. Isso é inferência
  da issue e do protocolo, **não provada aqui**. A 1ª tarefa da fatia mede no beta: assinar, deixar o cliente digitar e gravar, e
  registrar (a) se o evento chega com a sessão offline, (b) se só chega com `online` e qual o custo para o push do celular,
  (c) o intervalo real entre eventos `typing` e entre `typing` e `paused`.
- **Tempo de segurança dinâmico:** `ttl = max(piso, 2 × maior intervalo de renovação medido)`, guardado como configuração
  (não constante no código), com valor provisório de 15 s **só até a medição**. Se a medição mostrar que o WhatsApp renova
  sozinho a cada N s, o tempo segue N. Sem medição que o sustente, não se fixa número.

### Fatia 3: menu da mensagem (citação, reação, edição, apagar)

- **Citação recebida:** gravar `quoted_wa_message_id` e um instantâneo do trecho (autor, tipo, até 200 caracteres) na
  mensagem; o transcript mostra o bloco citado; clicar rola até a original e a destaca (se carregada).
- **Citação enviada:** "Responder" no menu da bolha; barra de citação sobre o campo (autor, trecho, ✕); envio com o
  `reply_to` do WAHA; o eco é adotado como hoje.
- **Reagir:** emoji escolhido (`PUT /api/reaction`); grava em `MessageReaction` com origem painel; remover reação também.
- **Editar:** só mensagem de texto enviada pelo painel e dentro da janela que o WhatsApp permite (~15 min); `PUT
  /api/{sessão}/chats/{chat}/messages/{id}`; grava a versão anterior em `MessageEdit` ("Antes:"), como no cliente.
- **Apagar para todos:** `DELETE` no WAHA; marca `revoked_at` e mantém o texto original visível como "apagada pelo
  atendente", igual ao que já fazemos com as do cliente. Evento de auditoria `message_revoked_by_agent` com o autor.
- Menu respeita papel: atendente age só nas mensagens do próprio ticket; mensagem do cliente só aceita Responder e Reagir.
- Falha do WAHA (fora da janela, sessão caída) mostra o motivo e não altera o painel.

### Fatia 4: notas internas e rascunho por ticket

- `ticket_notes` (ticket, autor, texto, hora), só da equipe, na linha do tempo do ticket com marca visual própria e
  **fora** do fluxo de envio (não existe caminho de código que mande nota ao WhatsApp). Conta no relatório de atividade
  mas não como resposta ao cliente nem para o SLA.
- Rascunho: o texto do campo de resposta por (usuário, ticket) é guardado (Livewire + armazenamento local do navegador
  com chave por ticket e usuário) e restaurado ao trocar de ticket ou recarregar; limpo ao enviar.

### Fatia 5: adicionar aos contatos e iniciar conversa pelo app

- Cartão de contato recebido (`SpecialContent::contacts`): botão "Adicionar aos contatos" (cria ou reaproveita via
  `ResolveContact`; sem duplicar) e botão "Conversar".
- Na tela Contatos e na ficha: "Conversar" / "Iniciar conversa". **Decidido pelo dono (03/11), após pesquisa de mercado
  (há plataformas que escolhem o dono do contato, quem criou ou ninguém; conversa iniciada sem dono é um buraco conhecido):**
  - **Dono:** o atendente que iniciou (ticket nasce `em_atendimento` atribuído a ele; pode transferir depois).
  - **Canal:** pré-seleciona o canal do ticket mais recente do contato (ou o único ativo) e permite trocar.
  - **Setor:** o do atendente; se cobre mais de um, ele escolhe qual.
  - **Ticket já aberto naquele canal:** abre o existente (nunca cria outro, mantém um aberto por contato e canal); se é de
    outra pessoa, mostra de quem é e só deixa responder se a permissão deixar.
  - **Primeira mensagem:** obrigatória no ato; a janela traz contato, canal, setor e o campo de mensagem, e o ticket só
    nasce junto com o envio (não sobram tickets vazios).
  - **SLA:** só passa a contar depois que o cliente responder (o relógio mede cliente esperando; ticket iniciado pela equipe
    não tem "cliente esperando" até a resposta).
  - Fixo: só contato com telefone WhatsApp válido; evento `opened_by_agent` com o autor; não passa pelo bot; ritmo e horário
    como qualquer envio humano.

### Fatia 6: busca dentro da conversa

- Campo de busca no cabeçalho do ticket aberto; pesquisa no texto e legendas do ticket (`pg_trgm` já existe); setas
  próximo/anterior e destaque; ao escolher, rola até a mensagem (reaproveita o rolar da citação, fatia 3) carregando o
  trecho se o transcript estiver paginado.

## 6. Ordem e dependências

1 (bot) → 2 (presença) → 3 (menu da mensagem) → 4 (notas e rascunho) → 5 (contato) → 6 (busca; depende do rolar até a
mensagem da fatia 3). Cada fatia: branch próprio, plano, TDD, revisão e merge separados. A fatia 5 é a única que muda a
regra "só o cliente abre ticket" e leva o maior cuidado de permissão e auditoria.

## 7. Restrições

Laravel 13, Livewire 4, Flux, Reverb, PostgreSQL 18. WAHA 2026.9.2 com GOWS. Textos em português, sem avisos novos de
infra. Sem novo processo de worker. Nenhuma fatia grava presença no banco. Toda ação nova do atendente gera evento de
auditoria. Cobertura mínima de 80% e Pint, Larastan e PHPMD verdes.

## 8. Riscos e o que fica para o construtor

- **Payload de presença no GOWS é desconhecido** (nenhuma implementação de referência trata o evento): a fatia 2 começa
  assinando e registrando o payload real no beta; o parser é defensivo. Se `@lid` não aceitar assinatura, a lista mostra
  presença só de quem tem `@c.us`.
- **Edição e apagar** dependem do que o GOWS aceita (janela, ids com o chat `@lid`); verificar no beta com o número de
  teste antes de liberar a todos. Quebrar o ID completo (`false_{chat}_{id}`) é a armadilha já conhecida.
- **Citação:** o transcript é paginado; citar mensagem fora da página mostra o bloco sem rolar.
- **Escolhas minhas (podem ser contestadas):** teto de 3 s no "digitando" do bot; janela de 15 min para editar; rascunho no
  navegador, não no banco. A duração da presença e as regras do "Conversar" não são escolhas minhas: a primeira segue eventos e
  medição (fatia 2), as outras foram decididas pelo dono em rodada de perguntas (fatia 5).
- **Fora deste pacote, anotado:** limpeza do store do WAHA e LGPD do acervo (com o RustFS/S3); regressão de dois números
  e chat de tipo desconhecido (`@newsletter`, `@broadcast`) viram testes na fatia 1.
