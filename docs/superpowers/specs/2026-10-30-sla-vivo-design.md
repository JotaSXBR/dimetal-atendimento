# SLA vivo: alerta de resposta no painel

Data: 2026-10-30. Origem: spec original, seção 13 ("ninguém abandonado": alerta vivo no painel e evento `sla_breached`, relógio só
no horário de funcionamento, a direção vê a lista). Hoje o SLA só existe no relatório (mediana da primeira resposta, percentual
dentro do limite e uma contagem de "atrasados" em horas corridas). Não há alerta, nem o evento, nem relógio de expediente.

## Intenção

O cliente que escreveu e ficou sem resposta humana além do limite precisa ser visto por quem atende e por quem coordena, antes e
depois de estourar, sem falso alarme fora do expediente.

## Objetivos

1. **Relógio de "próxima resposta"**: começa na primeira mensagem do cliente sem resposta humana e para na resposta humana (painel
   ou celular). A primeira resposta do ticket é o caso particular em que não houve nenhuma antes.
2. **Só conta o expediente** (horário de funcionamento e feriados já cadastrados). A mensagem das 22h começa a contar na abertura
   seguinte. Sem nenhuma faixa cadastrada, o atendimento conta como sempre aberto e o relógio corre direto.
3. **Dois níveis**: "perto de estourar" (75% do limite) e "estourou" (100%). O limite é o `company.sla_minutes` que já existe.
4. **No painel**: selo por ticket na lista do Inbox e, na conversa, o horário-limite ("Responder até hoje às 10h20"), não uma
   contagem em minutos.
5. **Para a direção** (supervisor e admin): na tela Relatórios, a lista dos tickets aguardando resposta perto de estourar ou
   estourados agora, no lugar da contagem de "atrasados".
6. **Evento `sla_breached`** na linha do tempo do ticket (e na Auditoria), uma única vez por espera.

## Fora do escopo

Notificação push, som ou e-mail (a spec original deixa push para depois da v1); várias políticas de SLA por setor, canal ou
cliente; meta de resolução; escalonamento automático (reatribuir ou avisar chefia); pausa manual do relógio.

## Anti-objetivos (falha mesmo que "funcione")

- Alerta fora do expediente, ou ticket estourando porque a empresa estava fechada.
- Resposta automática do bot ou fora do horário parar o relógio: só resposta **humana** para.
- O mesmo `sla_breached` gravado duas vezes para a mesma espera (a rotina roda a cada minuto).
- Atendente ver selo ou lista de ticket que não pode ver.
- Ticket encerrado aparecer como atrasado.
- Cliente que manda várias mensagens seguidas reiniciar o relógio e esconder a espera real.

## Como funciona

**Dados (colunas novas em `tickets`, todas `timestamp` nulas):**
- `waiting_since`: quando o cliente começou a esperar. `Ticket::recordInbound` grava `coalesce(waiting_since, instante)`, ou seja,
  só a **primeira** mensagem sem resposta conta. `Ticket::recordOutbound` (o ponto único das respostas humanas, pelo painel e pelo
  celular; a resposta do bot não passa por ele) zera `waiting_since` e os quatro campos abaixo.
- `sla_warn_at` e `sla_due_at`: o instante de 75% e de 100% do limite, em **minutos de expediente** a partir de `waiting_since`.
- `sla_warned_at` e `sla_breached_at`: marcas de "já avisei", para a rotina não repetir.

**Rotina `app:check-sla`, a cada minuto** (agendada como as demais rotinas):
1. Adota quem está esperando e ainda não tem `waiting_since` (tickets de antes da implantação): usa `last_inbound_at`.
2. Calcula `sla_warn_at` e `sla_due_at` de quem tem `waiting_since` e ainda não os tem.
3. Para os abertos (`aguardando` e `em_atendimento`) com `sla_warn_at <= agora` e sem `sla_warned_at`: grava a marca e dispara
   `TicketUpdated` (a tela se atualiza pelo tempo real que já existe).
4. Com `sla_due_at <= agora` e sem `sla_breached_at`: grava a marca, cria o evento `sla_breached` (payload com `waiting_since` e
   `due_at`) e dispara `TicketUpdated`. Cada passo é atômico (`UPDATE ... WHERE marca IS NULL`), então duas rodadas simultâneas
   não duplicam.

**Expediente:** novo `BusinessHours::addBusinessSeconds(CarbonInterface $from, int $seconds): CarbonImmutable`. Percorre as faixas
do dia (e os dias seguintes, até o limite de 60 dias que o serviço já usa), pula feriados, e se `$from` cai fora do expediente começa
na próxima abertura. Sem faixa cadastrada soma os segundos direto.

**Estado exibido** (calculado na hora da tela, a partir das colunas): `ok` antes de `sla_warn_at`, `perto` entre `sla_warn_at` e
`sla_due_at`, `estourou` a partir de `sla_due_at`; ticket sem `waiting_since` não tem selo.

**Lista do Inbox:** selo âmbar "Perto de estourar" e vermelho "SLA estourado" em cada linha. **Conversa:** o horário-limite com o dia
("Responder até hoje às 10h20", "amanhã às 8h10"), reaproveitando o formato de `BusinessHours::nextOpeningLabel`. **Relatórios:** a
lista dos que estão `perto` ou `estourou`, do mais atrasado para o menos: contato, setor, responsável, esperando desde, limite;
o número "atrasados" passa a contar os `estourou` (mesma regra, expediente).

## Riscos medidos e mitigação

Medido num PostgreSQL 18.6 em Docker, com 1 milhão de tickets (cerca de 3 anos de 2 números) e 300 abertos; tabela de 81 MB, tudo
em memória, dados sintéticos, sem concorrência. Detalhes em `docs/superpowers/notes/2026-10-30-sla-risco-benchmark.md`.

| Risco | Medida | Mitigação |
|---|---|---|
| A rotina de um minuto ler a tabela inteira | As 4 consultas da rotina levam 28 a 45 ms cada com o índice atual (`status, opened_at`), pois o filtro `status <> 'encerrado'` não o usa: ~150 ms por minuto e crescendo em linha reta com os tickets. Com um índice parcial só sobre os abertos caem para 0,05 a 0,07 ms (índice de 16 kB). | **Índices parciais** `tickets (id) where status <> 'encerrado'` e `tickets (sla_due_at) where status <> 'encerrado' and sla_due_at is not null`. |
| A lista do Inbox (já existente) com o mesmo problema | A consulta dos abertos ordenada por `last_inbound_at` leva 38,9 ms contra 0,37 ms com o índice parcial. Ela roda a cada atualização de tela de cada usuário. | O mesmo índice parcial resolve (efeito colateral bom, sem código novo). |
| Custo do cálculo de expediente por ticket | `BusinessHours` faz 2 consultas por cálculo (faixas e feriados). Mas o cálculo roda **uma vez por espera**, quando `sla_due_at` está vazio, então o trabalho por minuto é proporcional às esperas **novas**, não ao total. A adoção inicial dos abertos no deploy (centenas) é pontual. | Sem cache nem refatoração do `BusinessHours` (YAGNI); **limite de 200 tickets por passo e por rodada** (o resto fica para o minuto seguinte), para uma rajada (volta de uma pane, importação) não alongar a rodada. |
| Bloqueio preso por 24 h | Pela documentação do Laravel, `withoutOverlapping()` sem argumento segura o bloqueio por 24 horas se o processo morrer no meio (um deploy mata o container). O `routes/console.php` atual usa assim, inclusive no `drain-outbox` (a cada minuto). | A rotina nova usa `withoutOverlapping(5)`; as quatro tarefas existentes passam a `withoutOverlapping(10)` (um teste garante que nenhuma segura mais de 60 min). |
| Uma tarefa lenta atrasar as outras do minuto | As tarefas do mesmo minuto rodam em sequência, a menos que usem `runInBackground`. | A rotina nova usa `runInBackground()`. |
| Rotina parada sem ninguém perceber | Se o agendador cair, o alerta some em silêncio (a antítese do objetivo). | A rotina grava uma batida (`Cache`, `sla:last-run`); Relatórios mostra um aviso quando ela não roda há mais de 3 minutos. |
| Atraso do aviso | A rotina é de 1 minuto, então a marca e a atualização de tela chegam até 60 s depois do instante. | Aceito: o limite padrão é de 10 minutos. O selo é calculado na hora de desenhar a tela, então quem abre o Inbox vê o estado certo. |

**Não medido:** a busca por `closed_at` (aba Encerrados e Histórico) também não tem índice próprio e deve ler a tabela com 1 milhão
de linhas; ficou fora desta fatia e vale uma medição à parte.

## Abordagens consideradas

- **A. Calcular só na leitura** a partir de `last_inbound_at` e `last_outbound_at`: não grava nada, mas não há marca de "já avisei",
  o evento `sla_breached` não tem onde ancorar e a lista da direção vira consulta pesada.
- **B. Gravar o vencimento no ticket (escolhida pelo dono)**: colunas de vencimento e marcas, rotina de um minuto. Barato de
  consultar e comporta o evento. É o padrão de quem grava o instante da escalada no próprio ticket.
- **C. Tabelas de SLA com políticas e várias metas**: completo demais para uma política só.

## Chamadas do construtor (minhas, para o dono conferir)

1. **Aviso em 75% do limite** (7min30 de 10). Vale a regra de tempo fixo antes do estouro em outros produtos, mas com limite padrão
   de 10 minutos uma antecedência fixa ficaria quase igual ao limite.
2. **O relógio conta a partir da primeira mensagem sem resposta**, não da última (a spec original diz "última mensagem"). Assim o
   cliente que insiste ("oi?") não reinicia a espera nem esconde o abandono.
3. **Só o estouro vira evento** (`sla_breached`); o aviso de 75% é só uma marca e um selo, para não poluir a Auditoria.
4. **Mudar o limite de SLA ou o horário de funcionamento vale só para esperas novas**: os vencimentos já calculados não são
   refeitos. Comentário `shortcut:` no código.
5. **Estados contados**: só `aguardando` e `em_atendimento`. Ticket encerrado não entra, mesmo com marca antiga.
6. **Mudar de setor ou de responsável não reinicia o relógio** (não é resposta).
7. **A lista da direção fica em Relatórios** (supervisor e admin), sem link para abrir o ticket (o Inbox ainda não abre ticket por
   URL).
8. **Máximo de 200 tickets por passo e por rodada**, e a rotina com `withoutOverlapping(5)` e `runInBackground()`.
9. **Aviso em Relatórios quando a rotina para** (batida em cache, limite de 3 minutos).
10. **Endurecer as quatro tarefas agendadas existentes** com `withoutOverlapping(10)`: é uma mudança fora do SLA, achada na pesquisa,
    de uma linha cada, num commit separado.

## Testes (TDD, RED primeiro)

`BusinessHours::addBusinessSeconds`: dentro da faixa; atravessa o fechamento e continua na abertura seguinte; várias faixas no
dia; atravessa fim de semana; pula feriado; começa fora do expediente; sem faixa cadastrada; fronteiras exatas de abertura e
fechamento. `recordInbound`/`recordOutbound`: só a primeira mensagem sem resposta fixa `waiting_since`; resposta humana (painel e
celular) zera tudo; resposta do bot não zera. `app:check-sla`: adota tickets de antes da implantação; calcula os dois instantes;
estados `ok`, `perto` e `estourou` pelas marcas; um único `sla_breached` mesmo com duas rodadas; encerrado é ignorado; mensagem
das 22h só vence depois da abertura. Tela: selo na lista, horário-limite na conversa, atendente não vê selo de ticket alheio, lista
em Relatórios só para supervisor e admin, contagem de "atrasados" igual à lista.

## Fica para o construtor

Nomes das classes (a rotina, o serviço do relógio, o parcial do selo), se `waiting_since` e `sla_warn_at` podem ser fundidos sem
perder a idempotência, o índice (`sla_due_at` parcial para abertos) e a cor e o texto exato dos selos, que seguem o Design System
do Pencil.
