# Transcript da conversa como componente Livewire próprio

## Intenção
Hoje o transcript (as até 200 mensagens do ticket aberto) é desenhado dentro do componente `Inbox`. Toda atualização do
`Inbox` (evento do Echo, envio, troca de painel, respostas rápidas pela `/`) consulta e renderiza as 200 mensagens de novo.
Medido no local (ticket com 300 mensagens, 200 exibidas): ~155 a 250 ms por atualização, 260 KB de HTML, 1303 elementos
no DOM. O objetivo é que só o que mudou seja renderizado.

## Metas
- Uma interação que não muda mensagens (digitar `/`, abrir painel de transferir, evento da lista) não consulta nem
  renderiza o transcript.
- Evento `message.status` (tique de entrega) atualiza só o transcript, não a lista de tickets.
- Mesmo HTML, mesma aparência, mesma regra de reenvio e mesmo "cola no fim" da conversa (`flex-col-reverse`).

## Não metas
- Paginar ou mudar o limite de 200 mensagens; miniaturas e dimensões de mídia (item 4 da auditoria); mexer na lista de tickets.
- Mudar o Histórico: ele continua incluindo o parcial `livewire.inbox.transcript` em modo somente leitura.

## Abordagens
1. **Componente filho `Inbox\Transcript` (escolhida pelo dono).** O Livewire só re-renderiza um filho quando as props dele
   mudam ou ele mesmo recebe evento. Reaproveita o parcial atual. Custo: reenvio e `notice` ficam no pai.
2. Paginar com "carregar anteriores" (50 mensagens). Reduz o tamanho, mas muda a experiência e não isola o custo; fica
   para depois, se a medição pedir.

## Desenho
- `App\Livewire\Inbox\Transcript` recebe `int $ticketId`, com `wire:key="transcript-{id}"` no pai. A cada render busca o
  ticket com `Ticket::readableBy($user)` (o filho não confia no pai) e as últimas 200 mensagens, em ordem.
- Escuta `echo-private:inbox,.message.created` e `.message.status` (a lista de tickets segue com `ticket.updated` e
  `message.created`, sem `message.status`). Filtra por `ticket_id` do payload: só re-renderiza se for o ticket aberto.
- A view do filho é um envoltório do parcial existente. O botão Reenviar passa a chamar `$parent.resend(id, true)`;
  `Inbox::resend` e `$notice` não mudam. No Histórico não há botão, então nada muda.
- `Inbox::render` deixa de montar `messages`; `Inbox` troca o include por `<livewire:inbox.transcript>`.

## Riscos e decisões minhas
- Testes do `Inbox` que fazem `assertSee` em texto de mensagem depois de uma ação podem precisar apontar para o
  `Livewire::test(Transcript::class)`. Ajusto o que o RED mostrar. (Minha decisão.)
- Mensagem nova com o pai parado: o filho recebe o evento sozinho. Se o Echo cair, o pai e o filho ficam iguais: sem
  tempo real nos dois. (Sem mudança de comportamento.)
- Aceite: repetir a medição local (mesmo ticket de 300 mensagens). Esperado: `/` e eventos de lista deixam de trazer as
  200 mensagens no payload.

## Fica com quem constrói
Nome exato do envoltório e da view; como filtrar o evento por ticket (parâmetro do listener ou checagem no método).
