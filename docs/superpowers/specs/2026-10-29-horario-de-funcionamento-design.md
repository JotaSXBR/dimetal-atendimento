# Horário de funcionamento, feriados e resposta fora do horário — design curto

Data: 2026-10-29. Status: aguardando aprovação do dono. Segunda spec curta da "leva de anotações" do inbox. Base:
desenho original, seções 7 (horário editável com feriados) e 10 (aviso fora do horário).

## Por que

A operação atende em horário comercial, com pausa de almoço. Quem escreve fora dele não recebe nada e fica no vazio. O horário
também é a base do relógio de SLA e do aviso do bot, que vêm depois.

## Objetivos

1. Cadastro do horário por dia da semana, com várias faixas por dia (pausa de almoço), e de feriados (data avulsa, fechado o dia todo).
2. Resposta automática quando o cliente escreve fora do horário, uma vez por ticket, avisando o horário e a próxima abertura.
3. Uma função única "está aberto agora?" e "quando abre de novo?" para o SLA e o bot reaproveitarem.

## Não-objetivos

Importar feriados nacionais, estaduais e municipais (funcionalidade futura, fora da v1); horário por canal, por setor ou por pessoa
(um horário único, como o fluxo único do desenho); faixa que atravessa a meia-noite; resposta automática por palavra-chave; o
relógio de SLA em si.

## Decisões do dono

- **Várias faixas por dia** da semana (almoço fecha) e **cadastro manual de feriados**.
- **Resposta automática**, uma vez por ticket e só quando o cliente escreve primeiro.
- **Texto padrão** (editável): "Olá! Recebemos sua mensagem. Nosso atendimento funciona {{horario}}. Assim que estivermos de
  volta, um de nossos atendentes responde você." Entra também o **próximo horário de abertura** (variável `{{proximo_horario}}`,
  ex.: "hoje às 13h", "amanhã às 8h", "segunda-feira às 8h"); o texto padrão passa a terminar com "Voltamos {{proximo_horario}}."
- O mesmo texto vale em feriado e na pausa de almoço.

## Decisões do builder (contestáveis)

- **Dados:** `business_hours` (`weekday` 0–6, `opens_at`, `closes_at`; várias linhas por dia, até 4 faixas, sem sobreposição,
  abre < fecha) e `holidays` (`date` único, `name`). Texto e liga/desliga da resposta ficam em `settings`
  (`after_hours_enabled`, `after_hours_message`). Fuso: `config('app.display_timezone')`.
- **Sem faixa nenhuma cadastrada** conta como "sempre aberto": nada é respondido e nada fica marcado (falha segura).
- **Serviço `BusinessHours`:** `isOpen(CarbonInterface $at): bool`, `nextOpening(CarbonInterface $at): ?CarbonImmutable`,
  `summary(): string` (o texto de `{{horario}}`, agrupando dias iguais: "de segunda a sexta, das 8h às 12h e das 13h às 18h;
  sábado, das 8h às 12h"). Feriado fecha o dia todo e vence a faixa.
- **Quando responde** (tudo precisa ser verdade): mensagem recebida do cliente (não `fromMe`), texto ou mídia; recebida
  agora (diferença de até 5 minutos do `sent_at`, para não reagir a histórico reprocessado); fora do horário; resposta
  ligada; ticket aberto; nenhum atendente humano respondeu ainda; este ticket ainda não recebeu a resposta automática (marca
  por `ticket_events` do tipo `after_hours_reply`, gravada na mesma transação, para duas mensagens simultâneas não gerarem duas).
- **Como envia:** uma mensagem de saída com `origin = bot` (novo valor de `MessageOrigin`, já previsto no desenho), sem autor,
  pela mesma fila `outbound`, com o mesmo envio humanizado e a mesma caixa de saída. Não conta como resposta humana
  (`first_human_reply_at` não muda). No painel aparece com o rótulo "BOT · resposta automática".
- **Tela** (`configuracoes/horario`, só admin): grade de segunda a domingo com faixas adicionáveis e removíveis, lista de
  feriados (data + nome, adicionar e remover), liga/desliga, texto da mensagem com as variáveis e uma prévia do texto já
  preenchido. Validação com mensagens em pt-BR.
- **Atalho conhecido:** faixa só dentro do mesmo dia; `shortcut: sem faixa que cruza a meia-noite, subir quando houver turno noturno`.

## Comportamentos que não podem quebrar

- Cliente que manda 5 mensagens seguidas fora do horário recebe **uma** resposta.
- Ticket com atendente já respondendo não recebe resposta automática.
- A resposta automática nunca é enviada em reprocessamento de histórico nem a mensagem enviada pelo celular.
- Alterar o horário vale para as próximas mensagens; nada é reenviado.

## Testes

`BusinessHours`: aberto/fechado dentro, fora, na pausa, no limite exato de abertura e de fechamento, em feriado, sem faixas
(sempre aberto), `nextOpening` hoje/amanhã/segunda e pulando feriado; `summary` agrupando dias iguais. Ingestão: fora do horário
cria uma mensagem `bot` para o ticket; 5 mensagens seguidas criam uma; dentro do horário não cria; com humano já respondendo
não cria; mensagem velha (mais de 5 min) não cria; resposta desligada não cria; as variáveis são preenchidas. Tela: admin
salva faixas válidas, recusa sobreposição e faixa invertida, cadastra e remove feriado; não admin recebe 403.
