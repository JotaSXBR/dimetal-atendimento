# Roteamento e ciclo de vida do ticket — fatia 2

Continua `2026-10-28-inbox-fatia-1-design.md`. Fonte das regras de negócio: seções 7, 9 e 13 de
`2026-10-27-whatsapp-atendimento-design.md`. O Pencil é só referência visual; o que está aqui é o domínio.

## 1. Por que esta fatia vem agora

Hoje todo ticket nasce na "fila geral", sem setor, e só admite assumir e encerrar. Três peças futuras
dependem de o ticket ter setor, protocolo e motivo de encerramento:

- o **bot** termina sempre com a ação "transferir para o setor X" e usa `{{protocolo}}`;
- o **SLA** e os **relatórios** agregam por setor e por motivo de encerramento;
- a **permissão do atendente** (fila do seu setor) depende do setor do ticket.

## 2. Escopo

1. Setor no ticket e **transferência** entre setores.
2. **Fila por setor**: visibilidade e `assumir` respeitam os setores do atendente.
3. **Reatribuição** pelo supervisor/admin (ticket sem dono presente não pode ficar parado).
4. **Protocolo** `AAAA-NNNNNN`, único, sequencial por ano.
5. **Motivo de encerramento** (`closed_reason`) e **encerramento automático** por inatividade.

Fora: estado `bot` e motor de fluxo, distribuição automática, SLA, presença por setor, mídia, horário
de funcionamento, tela de gestão de setores.

## 3. Regras

**Visibilidade** (`Ticket::visibleTo`)
- Supervisor e admin: todos. Usuário inativo: nenhum.
- Atendente: tickets `aguardando` **sem setor** ou em um dos **seus setores**, mais os que ele assumiu.
- Atendente sem nenhum setor vê só os sem setor e os próprios.

**Assumir** (`claim`): só em ticket visível, sem responsável e não encerrado (regra atual) e, agora, de um
setor do atendente ou sem setor.

**Transferir** (`transfer`)
- Quem pode: o responsável, o supervisor e o admin. Ticket encerrado não transfere.
- Destino: setor que recebe tickets (`SectorStatus::receivesTickets()`: `ativo` ou `fora_do_menu`).
  `desativado` é recusado.
- Efeito, numa transação e com `UPDATE` condicional (não vence quem perdeu a corrida): `sector_id` = destino,
  `assigned_user_id` = nulo, `status` = `aguardando`. Evento `transferred` com ator e payload
  `{from_sector, to_sector, note}`. Transferir para o mesmo setor sem responsável é recusado.
- Nota opcional do atendente, gravada só no evento.

**Reatribuir** (`reassign`): supervisor/admin escolhem um usuário ativo que pertença ao setor do ticket (ou
qualquer, se o ticket não tem setor). Ticket vai a `em_atendimento` com o novo responsável. Evento
`reassigned` com `{from_user, to_user}`.

**Protocolo**
- Formato `AAAA-NNNNNN`; o ano é o de `opened_at` no fuso de exibição (`America/Sao_Paulo`), não em UTC,
  senão um ticket aberto às 22h de 31/12 pegaria o ano seguinte.
- Atribuído na criação do ticket, dentro da mesma transação, por incremento atômico de uma linha por ano
  (`insert ... on conflict (year) do update set last = last + 1 returning last`). Transação desfeita desfaz o
  número, então a sequência não tem buracos.
- Tickets existentes recebem protocolo por migração, em ordem de `id`, no ano de `opened_at`.
- Coluna única e obrigatória depois da migração.

**Encerramento**
- `closed_reason`: `manual` (botão) e `inatividade` (automático). `bot_erro` e `bot_timeout` entram na fase do bot.
- **Automático**: ticket aberto, sem movimento há mais de 24 h corridas **e** cuja última mensagem é nossa
  (`last_outbound_at` maior que `last_inbound_at`). Ticket cuja última mensagem é do cliente espera resposta
  humana e **nunca** fecha sozinho. Movimento = o maior entre `last_inbound_at`, `last_outbound_at` e
  `opened_at`.
- Comando agendado a cada 10 minutos, um `UPDATE` condicional que também grava o evento `closed` com ator
  nulo e `{reason: inatividade}`. Idempotente: rodar duas vezes não duplica nem reabre.

## 4. Dados

- `tickets`: `protocol` (string, único), `closed_reason` (string, nulo).
- `ticket_sequences`: `year` (pk), `last` (int).
- Sem mudança em `ticket_events`; novos tipos: `transferred`, `reassigned`.

## 5. Tela (mínima, a serviço do domínio)

Na conversa: protocolo e setor visíveis; botão **Transferir** (escolhe setor e nota); botão **Reatribuir**
só para supervisor/admin. A fila continua como está, filtrada pela nova visibilidade.

## 6. Tempo real

`TicketUpdated` em toda transferência, reatribuição e encerramento automático. O canal `inbox` já cobre
quem perdeu o ticket (ele some da lista) e quem passou a poder vê-lo.

## 7. Critérios de aceite

- Atendente do setor A não vê nem assume ticket do setor B; supervisor vê e opera ambos.
- Transferir devolve o ticket à fila do setor de destino sem responsável e registra o evento; setor
  `desativado` é recusado; duas transferências simultâneas não corrompem o ticket.
- Todo ticket tem protocolo; dois tickets criados ao mesmo tempo nunca repetem número; virada de ano usa o
  fuso de São Paulo.
- Ticket com última mensagem nossa e 24 h parado fecha com `inatividade`; com última mensagem do cliente
  não fecha, por mais velho que seja.
- Suíte, Larastan `max`, PHPMD e cobertura de 80% continuam passando.

## 8. Decisões do builder (podem ser contestadas)

- **Reatribuir entra nesta fatia**, porque é a saída para ticket de atendente ausente e usa a mesma
  primitiva da transferência.
- **Encerramento automático vale para qualquer ticket aberto** que satisfaça a regra, inclusive `aguardando`
  (só acontece se a última mensagem for nossa).
- **Nota na transferência é opcional** e fica só no evento, não vira mensagem para o cliente.
- **Sem tela de gestão de setores nesta fatia**: os cinco setores vêm do seeder, como hoje.

## 9. Antes de implementar

`AGENTS.md`: pesquisar projetos open source para a **numeração sequencial por ano** e para o **job de
encerramento por inatividade** antes de escrever o código.
