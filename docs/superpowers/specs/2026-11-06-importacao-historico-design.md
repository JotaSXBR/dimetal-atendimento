# Importação de histórico: vários tickets por contato, telefone do `@lid` e resumo do que foi ignorado

Data: 2026-11-06. Escopo: só o beta. Abordagem escolhida pelo dono: **A** (corrigir a importação atual, sem reescrever).

## Intenção

A importação inicial de 05/11 trouxe o histórico, mas de forma fraca: cada contato virou **um ticket só** (duração de
6780 min), os contatos `@lid` ficaram como "Número oculto pelo WhatsApp" e não há como saber o que foi descartado. O dono quer
o histórico lido do WhatsApp virando **vários tickets encerrados por contato**, com o telefone resolvido sempre que o WAHA
souber, e um resumo honesto do que entrou e do que ficou de fora.

## Objetivos

1. Cada contato importado tem **vários tickets encerrados**; uma **pausa de 6 h sem mensagem** abre um ticket novo.
2. Contato `@lid` sem telefone ganha o telefone por `GET /api/{session}/lids/{lid}` e é fundido pelo `MergeLidContact`.
3. A notificação de fim da importação mostra, além de mensagens e conversas, **quantas mensagens foram ignoradas e por quê**
   (grupo, broadcast, sem conteúdo, outro motivo de `IncomingMessage::fromEvent`).
4. Quem mandou **uma única** mensagem (texto, foto, figurinha etc.) na janela é importado como qualquer outro.
5. Ligar o full sync do GOWS no beta para o WAHA entregar mais histórico (ver "Operação no beta").

## Não objetivos

- Tela ou tabela para consultar depois o resumo do que foi ignorado: **só a notificação** (decisão do dono).
- Mais de 30 dias de histórico. O teto continua `ImportHistory::MAX_DAYS = 30` e as 15 mil mensagens em memória
  (`MAX_MESSAGES`). **Nota para versão futura:** o WAHA/GOWS não documenta profundidade máxima (o padrão é o histórico completo
  que o WhatsApp mandar, só no pareamento) e as variáveis de limite são pouco confiáveis; testar um teto maior numa data futura,
  medindo no beta quanto o WhatsApp realmente entrega. Isso exigiria ler chat a chat, não em memória.
- Reescrever o importador, mudar a importação ao vivo/recuperação, produção.

## Anti-objetivos

- Perder mensagem por causa da divisão em tickets (a gravação continua idempotente e retomável entre rodadas).
- Deixar a importação cair por falha do WAHA em `/lids`.
- Aumentar o `IngestMessage` (já perto dos limites do PHPMD): a lógica nova **sai** dele.

## Restrições

Laravel 13, Livewire 4, PostgreSQL. Fluxo em portões do `CLAUDE.md`; TDD; Pint, Larastan e PHPMD limpos. Sem PR sem pedido.
Mensagem importada não abre espera de resposta, não gera evento de linha do tempo nem tempo real (comportamento atual de
`IngestMode::IMPORTED`, mantido).

## Abordagens consideradas

- **A (escolhida): a pausa decide o ticket na hora de gravar.** Para uma mensagem importada, procura-se o ticket importado do
  contato no canal cuja janela `[opened_at, closed_at]` esteja a menos de 6 h da mensagem; se achar, a mensagem entra nele e a
  janela se estende; senão abre outro. Idempotente e retomável, sem mudar o fluxo do `ImportHistory`.
- **B: o `ImportHistory` calcula os blocos e passa o número do bloco.** Regra num lugar só, mas uma rodada interrompida no meio
  de um chat pode partir um bloco, e o parâmetro atravessaria `RecoverMessages` e `IngestMessage`.
- Reescrever com leitura chat a chat: descartada, o teto de 30 dias a torna desnecessária.

Custo da A (o do dono: não engordar o `IngestMessage`) resolvido assim: a regra mora numa **classe nova**
`App\Services\Inbox\ImportedTicket` (invocável, recebe canal, contato e `IncomingMessage`, devolve o `Ticket`), e o
`IngestMessage::importedTicketFor` é **removido** — `ticketFor` passa a chamar a classe nova. O `IngestMessage` encolhe.

## Decisões do dono (como decidiu)

- Vários tickets por contato, separados **por pausa**, não por dia do calendário.
- Resumo só na notificação.
- Teto de 30 dias agora; full sync ligado no beta; anotar o teste futuro de profundidade.
- Abordagem A, sem engordar o `IngestMessage`.

## Chamadas minhas (a conferir)

- **Limite da pausa: 6 h**, constante nomeada em `ImportedTicket`. Se errado: ajustar a constante e reimportar.
- **Mensagem em ordem, um ticket por vez:** o importador já ordena por horário dentro do chat; mensagem fora de ordem (rodada
  retomada) cai no ticket cuja janela a alcança, e se alcançar dois tickets (preenche o buraco entre eles) os **funde** no mais
  antigo. Se errado: ticket duplicado em caso raro; custo baixo, é histórico.
- **`@lid` resolvido depois de gravar cada chat**, só para contato que ainda tem `lid` e não tem `wa_id`, uma consulta por
  contato por importação. Falha ou 404 do WAHA: loga e segue; o contato continua "Número oculto". A fusão reaproveita
  `ResolveContact` (chamado com o `lid` e o telefone achado) → `MergeLidContact`, que já move tickets e mensagens.
- **Contagem de ignoradas por motivo** vem de uma classificação do motivo em `IncomingMessage` (hoje `fromEvent` devolve só
  `null`); o `RecoveryResult` ganha o mapa de motivos e o `ImportHistoryJob` o soma entre rodadas (cache, como já faz) para a
  notificação `MessagesRecovered`. A recuperação (`RecoverMessages`) mantém o comportamento atual.
- **Ticket aberto das últimas 48 h** (chat em que o cliente falou por último sem resposta): mantido como está.
- **Pausa vs. ticket já existente de importação antiga** (um ticket único de 05/11 no banco): não se migra; reimportar no beta
  é descartável (o dono confirmou que o beta é teste).

## Operação no beta (fora do código, passo manual/Coolify)

No container `waha-beta`: `WAHA_GOWS_DEVICE_REQUIRE_FULL_SYNC=true` (e limites de dias/mensagens por chat afrouxados, já que o
app só usa 30 dias). Só vale com **logout + novo QR** (`DELETE /api/sessions/{session}` e reconectar). Atualizar
`docs/superpowers/specs/waha-config.md` (§2 e "Por que os limites de histórico") com o valor novo e a nota de teste futuro. A
produção não é tocada.

## O que fica para quem constrói

Nomes de métodos, formato do mapa de motivos, texto da notificação, onde exatamente chamar a resolução do `@lid` dentro do loop
do `ImportHistory`, e os testes (TDD em cada tarefa; cobrir: pausa exatamente no limite, mensagem única, retomada de rodada,
fusão de dois tickets, `@lid` resolvido/404/erro, soma dos motivos entre rodadas).
