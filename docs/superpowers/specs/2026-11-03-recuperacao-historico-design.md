# Recuperação de mensagens, importação inicial e eventos de mensagem

Data: 2026-11-03. Status: rascunho para aprovação do dono.

## Por que

1. Quando a sessão do WhatsApp fica fora por um tempo e volta, o que foi trocado nesse intervalo não chega ao app: o WAHA
   não reentrega o que passou durante a queda (`waha-config.md`, "Mensagem que chega com o WAHA fora do ar"). O cliente fica
   sem resposta e ninguém sabe.
2. A caixa "importar histórico (N dias)" ao conectar um canal grava `channels.backfill_days`, mas nenhuma rotina a usa.
   Quem conecta um número pela primeira vez começa sem ver os contatos que atendeu nos dias anteriores.
3. O app assina `message.reaction` e `message.revoked` no WAHA mas descarta os dois; `message.edited` nem é assinado.

Medição de outro projeto que usa WAHA (DeskcommCRM, 30 dias): de 147 saídas de `WORKING`, 142 duraram menos de 10 s e
nenhuma mensagem se perdeu nelas. A perda real está nas quedas longas. Nos logs do beta, as quedas vistas até agora foram
todas de cerca de 1 s.

## Objetivos

- **A. Recuperação automática:** ao voltar de uma queda de 5 min ou mais, buscar no WAHA as mensagens do período e
  entregá-las ao fluxo normal do app.
- **B. Recuperação manual:** opção no card do canal (Configurações) para puxar de novo uma janela escolhida.
- **C. Varredura diária:** a mesma recuperação, todo dia, sobre as últimas 24 h, para cobrir buracos que a detecção de
  queda não vê.
- **D. Importação inicial:** a caixa "importar histórico" passa a trazer as conversas dos últimos N dias como histórico
  encerrado, com os contatos criados, sem encher a fila.
- **E. Reações, edições e mensagens apagadas:** o app passa a tratar os três eventos ao vivo e, quando a API os expuser, na
  recuperação.
- **F. Estado de entrega:** as mensagens enviadas pelo celular e recuperadas entram com o estado real (entregue, lida).
- Tudo roda quantas vezes for preciso sem duplicar nada.

## Fora do escopo

- Grupos, status e newsletter (o `IncomingMessage` já os ignora).
- Importar mensagens de mais de N dias ou re-importar um canal já importado.
- Botão manual de importação (a importação roda uma vez, na primeira conexão).

## O que não pode acontecer

- Mensagem duplicada, em qualquer combinação de recuperação, importação e webhook ao vivo.
- Resposta automática (bot ou fora do horário) a uma mensagem antiga.
- Dezenas de sons de fila ou de eventos em tempo real por causa de recuperação ou importação.
- Importação criando fila, SLA ou números nos relatórios de atendimento.
- Recuperar ou importar o histórico de **outro número** se o aparelho foi trocado durante a queda.

## Abordagens consideradas

1. **Busca única e reaproveitamento do `IngestMessage` (escolhida).** Um serviço chama
   `GET /api/{sessão}/chats/all/messages` na janela e transforma cada mensagem num `WahaEvent` sintético, que passa pelo
   mesmo `IngestMessage` dos webhooks. Contato, `@lid`, mídia e encaixe no ticket são o código que já funciona.
2. **Varredura por chat** (listar chats com atividade e buscar cada um). Mais chamadas. Fica como reserva se o `chats/all`
   não funcionar neste GOWS.
3. **Importador que grava `Message` direto.** Duplicaria contato, ticket e mídia. Descartada.

## Desenho

### Um serviço de busca, três modos

`IngestMessage` ganha um modo, carregado pelo `IncomingMessage` (marca `mode` no payload do `WahaEvent` sintético):

| Modo | Origem | Ticket | Bot e resposta automática | Som e tempo real | SLA |
|------|--------|--------|---------------------------|------------------|-----|
| `live` | webhook | normal | normal | normal | normal |
| `recovered` | recuperação (A, B, C) | normal (ver abaixo) | só se a mensagem for recente (regra de 5 min que já existe) | sem som de fila | desde a hora real |
| `imported` | importação (D) | ticket encerrado de importação | nunca | nenhum | nenhum |

- `waha_event_id` sintético: `recovered:{canal}:{wa_message_id}` / `imported:{canal}:{wa_message_id}` (a coluna já é única).
- A deduplicação tem duas camadas: o id sintético e o `wa_message_id` por canal, que já existe.
- O job chama `IngestMessage` direto, uma mensagem por vez, com tratamento de erro por mensagem, e conta as realmente criadas.

### A. Detectar a queda e recuperar

- Nova coluna `channels.down_since` (timestamp, nulo). No `ChannelObserver`, ao sair de `WORKING` grava `down_since` se
  estiver vazio; ao voltar a `WORKING`, lê e limpa.
- Se `agora - down_since >= 5 min` e o canal está ativo, agenda `RecoverMessagesJob` com janela
  `[down_since - 10 min, agora]`, **60 s depois** da volta (a caixa de saída esvazia antes e o eco das mensagens do painel
  não vira duplicata). Queda menor só limpa `down_since`.
- Se o número mudou durante a queda (`ChannelPhone` já detecta a troca), não recupera e registra em log.
- **Com ticket aberto:** a mensagem entra no ticket com a hora real; `recordInbound`/`recordOutbound` já tratam mensagem
  atrasada. Cliente sem resposta: o SLA conta desde a hora real.
- **Sem ticket aberto:** caminho normal (ticket novo). Como a mensagem tem mais de 5 min, `BotEntry::shouldStart` e
  `AfterHoursReply` já não agem, e o ticket nasce em `aguardando`.
- `BotEntry::handle` passa a aplicar a mesma regra de frescor (hoje só `shouldStart` aplica); sem isso, uma mensagem antiga
  num ticket já `pendente` avançaria o bot. No modo `recovered`, `openTicketFor` não chama `AlertDispatcher::queued`.
- Janela máxima de 7 dias (limite do histórico do GOWS configurado no Coolify).

### Buscar

- Novo método em `WahaClient`: mensagens de todos os chats com `filter.timestamp.gte`/`lte` (segundos Unix), `limit` fixo e
  paginação por `offset` até a página vir menor que o limite, com teto de páginas. `downloadMedia=false`: a mídia
  continua sendo resolvida por `ResolveMessageMediaJob`.
- Antes de implementar, uma chamada real ao beta confirma o formato da resposta e se `chats/all` funciona; o teste do
  cliente nasce de uma resposta real. Se falhar, vale a abordagem 2.

### B. Opção manual

- No `ChannelCard`, visível para canal ativo e conectado: seletor de janela (1 h, 6 h, 24 h, 72 h; padrão 24 h) e botão
  "Recuperar mensagens". Dispara o mesmo job; o resultado chega pelo sino.

### C. Varredura diária

- Comando agendado `app:recover-messages` às 04:15, para cada canal ativo e conectado, janela das últimas 24 h. Roda a
  mesma recuperação; só avisa no sino se recuperou alguma coisa.

### D. Importação inicial

- Dispara na primeira vez que o canal chega a `WORKING` com `backfill_days > 0` e `backfill_last_run_at` vazio; grava
  `backfill_last_run_at` ao concluir. Janela: os últimos `backfill_days` dias.
- Por chat 1:1 com mensagens na janela: cria o contato (nome e telefone) pelo `ResolveContact` existente. Contato
  importado não é "editado", então não vai para o Google Contacts.
- **Um ticket encerrado por chat**, com todas as mensagens da janela: `opened_at` na primeira mensagem, `closed_at` na
  última, `closed_reason = importado` (valor novo do `ClosedReason`), sem `waiting_since`, sem SLA, sem bot, sem eventos em
  tempo real. Consultável pelo Histórico e pela ficha do contato.
- **Conversas que estavam abertas:** se a última mensagem do chat é do cliente, não tem resposta depois e tem até 48 h, o
  chat inteiro entra pelo caminho normal (ticket aberto em `aguardando`, sem bot). Todo o resto entra encerrado.
- **Já existe ticket aberto para o contato** (canal reaproveitado): mensagens a partir do `opened_at` desse ticket seguem o
  caminho normal e entram nele; as anteriores vão para o ticket de importação.
- Tickets `importado` ficam fora dos relatórios de atendimento (`AttendanceReport`) e da aba "encerrados recentemente" da
  caixa de entrada. O plano varre todo lugar que lê tickets encerrados.
- **Mídia:** a importação baixa toda a mídia. Cada `ResolveMessageMediaJob` (timeout de 90 s, 4 tentativas) hoje roda na fila
  `inbound`, a das mensagens ao vivo; mídia importada ou recuperada vai para uma **fila própria `history`**, com um worker
  dedicado de baixa concorrência em `docker/worker.sh` (o padrão `start --queue=...`), para nunca atrasar o recebimento ao
  vivo. O ticket e o texto aparecem logo; a mídia chega depois.
- Um aviso único no sino: "N conversas importadas, M aguardando resposta".

### E. Reações, edições e mensagens apagadas

- `sessionConfig` passa a assinar também `message.edited`. Sessões existentes recebem a nova lista na próxima vez que a
  configuração for reaplicada (`WahaClient::start()`); o plano inclui reaplicá-la nos canais já conectados.
- `HandleWahaEvent` ganha três tratadores. O id do evento chega só com a cauda do id do WhatsApp (`editedMessageId`,
  `revokedMessageId`, `reaction.messageId`): casa-se pelo `Message::waKey` no canal. Se a mensagem de destino ainda não
  existe, o evento tenta de novo como o ack (5 s, até 6 vezes) e depois é descartado com log.
  - `message.reaction`: nova tabela `message_reactions` (`message_id`, quem reagiu, emoji, hora). Uma reação por pessoa e
    mensagem; texto vazio remove. Aparece como emoji sob a mensagem. **Reação não é atividade:** não muda última
    mensagem, não lidas, espera de resposta, SLA nem o frescor do ticket, e não reabre ticket.
  - `message.edited` (`editedMessageId`, `body`): atualiza o corpo e grava `messages.edited_at`; a tela mostra "editada".
  - `message.revoked` (`revokedMessageId`): grava `messages.revoked_at`. A tela **continua mostrando o texto**, com a marca
    "apagada pelo cliente".
- **Recuperação desses três:** a documentação do WAHA não diz como a listagem de mensagens expõe reação, edição e
  apagamento. Se o beta mostrar que não expõe, eles valem só ao vivo (decisão do dono), sem chamadas extras por mensagem, e
  o spec registra o limite: uma edição ou apagamento ocorrido durante uma queda longa não é refletido.

### F. Estado de entrega na recuperação

- Mensagem enviada pelo celular e recuperada usa o `ack` da listagem, pela mesma tabela de estados do `ApplyAck`, sem nunca
  rebaixar. Mensagem recebida não tem estado de entrega.

### Aviso e execução

- Nova notificação `MessagesRecovered` (sino, sem som): mensagens e conversas recuperadas. Execução manual sempre avisa,
  inclusive "nenhuma mensagem nova". Destinatários: os de `ChannelDown` (`AlertRecipients::everyone`).
- `RecoverMessagesJob` implementa `ShouldBeUnique` por canal: manual, automático, varredura e importação não rodam juntos.

## Decisões do dono

- Recuperação automática ao voltar da queda, mais opção manual no menu do WAHA, sempre com deduplicação.
- Mensagem recuperada conta como pendente de resposta (SLA desde a hora real), mas sem efeitos de mensagem nova.
- Sem ticket aberto: segue o caminho normal.
- Abordagem 1 (busca única e `IngestMessage`), com a 2 como reserva.
- Importação como histórico encerrado, trazendo contatos e mensagens; sem fila desnecessária.
- Conversa sem resposta recente na importação abre ticket normal; um ticket encerrado por chat.
- Reações, edições e apagadas, estado de entrega e varredura diária entram no escopo, tudo no mesmo plano.
- Reação não conta como atividade do cliente; só aparece sobre a mensagem.
- Mensagem apagada continua visível, com a marca "apagada pelo cliente".
- Importação baixa toda a mídia, em fila separada.
- Se a listagem do WAHA não trouxer reação, edição e apagamento, eles valem só ao vivo.

## Decisões minhas (custo se erradas)

- Limiar de 5 min, margem de 10 min e atraso de 60 s: copiados do DeskcommCRM. Se errados, perde-se ou repete-se um pouco;
  repetir é inofensivo pela deduplicação. O atraso evita duplicata do eco do painel.
- Sem som de fila na recuperação. Se errado, a equipe vê as conversas novas só pelo sino e pela fila.
- Janelas do seletor manual e a varredura às 04:15: trocáveis sem custo.
- Aviso para todos os usuários ativos, como o de canal caído.
- Corte de 48 h para "conversa aberta" na importação. Se errado, uma conversa pendente some no histórico (maior) ou a fila
  recebe uma antiga (menor).
- `ClosedReason::importado` e a exclusão dos relatórios: se errado, os números de atendimento ficam inflados.
- Uma reação por pessoa e mensagem (como no WhatsApp).
- Fila `history` com um worker de concorrência 1 e `backfill_days` limitado a 30 dias na tela (hoje aceita até 365). Se
  errado, a importação demora mais (concorrência) ou o dono precisa de janela maior (limite, trocável em uma linha).
- A fila `history` exige alterar `docker/worker.sh` e redeploy do `worker-beta`; sem isso a mídia importada fica parada.

## Ordem sugerida no plano

1. `WahaClient` (mensagens) com o contrato confirmado por chamada real ao beta.
2. Modo no `IngestMessage`/`IncomingMessage` e ajuste do `BotEntry::handle`.
3. `down_since`, `RecoverMessagesJob`, aviso e testes de deduplicação.
4. Botão manual e varredura diária.
5. Reações, edições e apagadas (dados, tratadores, tela, assinatura do `message.edited`).
6. Fila `history` (worker) e importação inicial.
7. Estado de entrega na recuperação.

## Limites conhecidos

- O app não detecta quedas em que o canal continuou `WORKING` (por exemplo, o app fora do ar além da janela de reenvio do
  webhook, cerca de 12 min); a varredura diária e o botão manual cobrem.
- Mídia muito antiga pode não estar mais disponível no WAHA; `ResolveMessageMediaJob` já lida com falha de download.
- Importar mídia de N dias enche o armazenamento do WAHA. O Coolify mostra `WHATSAPP_FILES_LIFETIME` com dois valores
  (`7776000` e `0`); antes de importar convém conferir qual vale no container e o espaço em disco.
- O quanto de histórico o GOWS devolve vem dos servidores do WhatsApp e não está documentado; a chamada de teste no beta
  responde. Se a janela de `backfill_days` (hoje até 365) passar do que ele devolve, a importação traz o que existir.
- Este plano é grande (um PR). A ordem acima permite parar e revisar entre as etapas.
