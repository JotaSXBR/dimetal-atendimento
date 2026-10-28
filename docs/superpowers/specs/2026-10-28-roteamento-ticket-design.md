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
3. **Reatribuição e tomada de ticket**: supervisor/admin reatribuem; o atendente também pode assumir o ticket
   que está com colega do mesmo setor, salvo se a opção "pode transferir" dele estiver desligada.
4. **Ordem dos setores** (menu do bot e listas): Vendas, Financeiro, Comercial, SAC, Diretoria. O seeder passa a criar
nessa ordem, e uma migração de dados reordena o beta, que já tem os setores na ordem antiga. A migração só mexe se a
ordem ainda for a original (assim não desfaz uma reordenação feita depois pela gestão de setores).

**Protocolo** `AAAA-NNNNNN`, único, sequencial por ano.
5. **Motivo de encerramento** (`closed_reason`) e **encerramento automático** por inatividade.

Fora: estado `bot` e motor de fluxo, distribuição automática, SLA, presença por setor, mídia, horário
de funcionamento, tela de gestão de setores.

## 3. Regras

**Visibilidade** (`Ticket::visibleTo`)
- Supervisor e admin: todos. Usuário inativo: nenhum.
- Atendente: os que ele assumiu; os `aguardando` **sem setor** ou de um dos **seus setores**; e os
  `em_atendimento` de um dos seus setores. Ticket `em_atendimento` sem setor só é visto por quem o assumiu. Ele vê a conversa dos colegas do seu setor, mas só o responsável (ou supervisor/admin) responde.
- Atendente sem nenhum setor vê só os sem setor e os próprios.
- Isso amplia a regra da fatia 1 (que mostrava só a fila e os próprios) porque tomar o ticket de um colega exige
  vê-lo. Ver "Decisões do builder".

**Assumir** (`claim`): ticket sem responsável e não encerrado, de um setor do atendente ou sem setor. Não
depende da opção "pode transferir".

**Tomar** (`takeover`): o atendente assume um ticket `em_atendimento` que está com **outro responsável**, de um setor
dele. Só vale se `users.can_transfer` for verdadeiro. É uma troca condicional (`UPDATE ... WHERE assigned_user_id =
<responsável que ele viu>`): se o ticket mudou de dono no meio do caminho, quem perdeu recebe aviso. Evento
`taken_over` com `{from_user, to_user}`; o responsável anterior perde o ticket da lista em tempo real.

**Transferir** (`transfer`)
- Quem pode: o responsável (se `can_transfer`), o supervisor e o admin. Ticket encerrado não transfere.
- Destino: setor que recebe tickets (`SectorStatus::receivesTickets()`: `ativo` ou `fora_do_menu`).
  `desativado` é recusado.
- Efeito, numa transação e com `UPDATE` condicional (não vence quem perdeu a corrida): `sector_id` = destino,
  `assigned_user_id` = nulo, `status` = `aguardando`. Evento `transferred` com ator e payload
  `{from_sector, to_sector, note}`. Transferir para o mesmo setor sem responsável é recusado.
- Nota opcional do atendente, gravada só no evento.

**Reatribuir** (`reassign`): supervisor/admin escolhem um usuário ativo que pertença ao setor do ticket (ou
qualquer, se o ticket não tem setor). Ticket vai a `em_atendimento` com o novo responsável. Evento
`reassigned` com `{from_user, to_user}`. O atendente não escolhe o destino; ele só pode tomar para si (`takeover`).

**Opção "pode transferir"** (`users.can_transfer`, padrão verdadeiro): quando desligada para um atendente, ele deixa
de **transferir** e de **tomar** tickets. Continua assumindo tickets livres, respondendo e encerrando os seus.
Supervisor e admin não são afetados. Enquanto não há tela de usuários, liga-se e desliga-se por
`php artisan app:user-can-transfer {email} {on|off}`; a opção na tela chega com a gestão de usuários.

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
- Comando `app:close-inactive-tickets`, agendado a cada 10 minutos, um `UPDATE` condicional que também grava o evento `closed` com ator
  nulo e `{reason: inatividade}`. Idempotente: rodar duas vezes não duplica nem reabre.

## 4. Dados

- `tickets`: `protocol` (string, único, **nulo na expansão**), `closed_reason` (string, nulo).
- `ticket_sequences`: `year` (pk), `last` (int).
- `users`: `can_transfer` (boolean, padrão verdadeiro).
- Sem mudança em `ticket_events`; novos tipos: `transferred`, `reassigned`, `taken_over`.
- **Compatibilidade no deploy** (regra de migrações do `docs/deploy.md`): o app sobe antes do worker, então o worker
  antigo ainda cria tickets sem protocolo por alguns minutos. Por isso `protocol` entra como coluna nula, o código
  novo a preenche e a migração preenche os antigos; o `NOT NULL` vem num PR seguinte, depois de a versão nova estar
  em todos os recursos (expand/contract).

## 5. Tela (mínima, a serviço do domínio)

Na conversa: protocolo e setor visíveis; botão **Transferir** (escolhe setor e nota); botão **Reatribuir** só para
supervisor/admin; botão **Tomar** para o atendente num ticket de colega. Quem não pode, não vê o botão. A fila continua
como está, filtrada pela nova visibilidade.

## 6. Tempo real

`TicketUpdated` em toda transferência, reatribuição e encerramento automático. O canal `inbox` já cobre
quem perdeu o ticket (ele some da lista) e quem passou a poder vê-lo.

## 7. Critérios de aceite

- Atendente do setor A não vê nem assume ticket do setor B; supervisor vê e opera ambos.
- Atendente do setor A toma um ticket em atendimento de colega do setor A; com `can_transfer` desligado, não toma
  nem transfere. Dois atendentes tomando o mesmo ticket ao mesmo tempo: um vence, o outro recebe aviso.
- O menu do bot e as listas de setor saem na ordem Vendas, Financeiro, Comercial, SAC, Diretoria, inclusive no beta.
- Transferir devolve o ticket à fila do setor de destino sem responsável e registra o evento; setor
  `desativado` é recusado; duas transferências simultâneas não corrompem o ticket.
- Todo ticket tem protocolo; dois tickets criados ao mesmo tempo nunca repetem número; virada de ano usa o
  fuso de São Paulo.
- Ticket com última mensagem nossa e 24 h parado fecha com `inatividade`; com última mensagem do cliente
  não fecha, por mais velho que seja.
- Suíte, Larastan `max`, PHPMD e cobertura de 80% continuam passando.

## 8. Decisões do builder (podem ser contestadas)

- **Reatribuir entra nesta fatia** (decisão do dono), porque é a saída para ticket de atendente ausente e usa a
  mesma primitiva da transferência.
- **O atendente também pode tomar o ticket de um colega** (decisão do dono), limitado a tickets do seu setor.
  Consequência que a spec registra: o atendente passa a **ler** conversas de colegas do mesmo setor. Se isso for
  indesejado, a alternativa é uma lista "em atendimento no setor" só com contato e responsável, sem as mensagens.
- **A opção "pode transferir" cobre transferir e tomar** (um único interruptor), e não afeta assumir ticket livre.
- **Sem tela de gestão de setores nem de usuários nesta fatia** (decisão do dono): vêm em fatias próprias.
- **Encerramento automático vale para qualquer ticket aberto** que satisfaça a regra, inclusive `aguardando`
  (só acontece se a última mensagem for nossa).
- **Nota na transferência é opcional** e fica só no evento, não vira mensagem para o cliente.

## 9. Antes de implementar

`AGENTS.md`: pesquisar projetos open source para a **numeração sequencial por ano** e para o **job de
encerramento por inatividade** antes de escrever o código.
