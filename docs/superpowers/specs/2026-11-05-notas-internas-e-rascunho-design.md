# Fatia 4: notas internas e rascunho (design)

Parte da spec `2026-11-03-inbox-completa-design.md` (fatia 4). Aprovado em conversa com o dono em 05/11; este arquivo registra.

## Intenção

O atendente precisa anotar algo para a equipe (combinado, contexto, dado do cliente) dentro do ticket, sem risco de o cliente
ver, e não perder o que já digitou ao trocar de ticket ou recarregar a página.

## Metas

- Seletor no campo de resposta alterna **Responder** e **Nota privada**; no modo nota a barra inteira muda de cor (âmbar), o botão
  vira "Salvar nota" e o texto de apoio diz que só a equipe vê. Alt+P alterna (padrão do Chatwoot).
- A nota aparece como **cartão âmbar no fluxo da conversa**, na ordem do horário, com cadeado, "Nota interna", autor e hora, sem tiques.
- **Só o autor apaga** a própria nota (decisão do dono, igual a editar e apagar mensagem).
- Item **"Adicionar texto às notas"** no menu da bolha: troca para o modo nota e põe o texto da mensagem no campo, editável; **não grava**.
- **Rascunho** do campo guardado só no navegador (decisão do dono de 04/11), **um por modo** (resposta e nota) por (usuário, ticket):
  restaurado ao abrir o ticket ou recarregar; limpo ao enviar ou salvar.

## Não metas

Editar nota, @menção, notificação de nota, anexo ou áudio em nota, busca dentro de notas, rascunho no servidor, nota fora de ticket.

## Anti-metas (é fracasso mesmo que funcione)

- Nota chegar ao WhatsApp ou à fila de saída. Nota fica em `ticket_notes`, nunca em `messages`; nenhum caminho de código liga as duas.
- **"Digitando…" ser enviado ao cliente enquanto o atendente escreve nota** (o campo hoje anuncia presença ao digitar).
- Nota contar como resposta ao cliente, mexer em `last_outbound_at`, no SLA, no bot ou na marca "humano ativo".
- Trocar de modo apagar o que estava digitado.

## Abordagens consideradas

1. **Seletor no campo + cartão âmbar no fluxo (escolhida pelo dono).** Padrão de Chatwoot, Intercom e Agorapulse. Custo: o transcript
   junta duas listas por horário.
2. Aba "Notas" no painel de detalhes: sem mistura e sem risco de erro de campo, mas longe do ponto da conversa e não é o que o dono quer.
Notas em `messages` (tipo próprio) foi descartada: todo filtro de SLA, relatório, fila de saída e histórico teria de excluí-las, e um
esquecimento vira a anti-meta acima.

## Desenho

**Dados.** `ticket_notes`: `id`, `ticket_id` (FK, apaga com o ticket), `user_id` (autor), `body` (texto, até 4096), `created_at`.
Sem `updated_at`/edição. Apagar remove a linha e grava `TicketEvent` `note_deleted` com o autor (rastro mínimo). *Minha escolha.*

**Quem vê e escreve.** Vê e escreve quem pode ver o ticket (`readableBy`) e tem o campo na tela; no modo somente leitura não há campo.
Apaga só o autor (política `TicketNotePolicy::delete`, sem exceção para supervisor/admin). *Minha escolha: escrever não exige poder responder.*

**Servidor.** Ação `saveNote()` no `Inbox` (ou concern próprio), validação `required|string|max:4096`, mesma trava de ritmo do envio
(`reserveSends(1)`), grava a nota e dispara `transcript-refresh`. Aviso em tempo real aos outros atendentes: reusar o evento
`TicketUpdated` que o `Transcript` já escuta. Nenhum job, nenhuma chamada ao WAHA. `Transcript::render` junta mensagens e notas por
horário (as 200 mensagens mais recentes e as notas desde a mais antiga delas). Ação `deleteNote($id)` no `Transcript`, com autorização.

**Tela.** Estado `mode` ('reply'|'note') no Alpine, padrão Responder, volta a Responder ao trocar de ticket (não persiste). Em modo nota:
anexos, microfone, assinatura e a barra "Respondendo a…" ficam ocultos (a citação não é consumida); o campo **não chama `typing`**
nem `recording`. Cartão da nota: âmbar, cadeado, autor e hora `d/m H:i`, menu só com Copiar e (se autor) Apagar com confirmação.

**Rascunho.** `localStorage`, chave `rascunho:{user_id}:{ticket_id}:{reply|note}`. Ao alternar o modo, o texto atual é guardado na chave do
modo que sai e o do modo que entra é carregado. Apagado ao enviar/salvar com sucesso. O texto que o `/pre` expande continua valendo.
Sem limpeza de rascunhos antigos neste pacote. *Minha escolha.*

**Menu da bolha.** Item "Adicionar texto às notas" (ícone de nota) em toda mensagem com texto, fora do modo somente leitura: muda para
nota, acrescenta o texto ao rascunho de nota (em linha nova se já houver texto) e foca o campo.

**Relatório.** Se `AttendanceReport` já tem contagem de atividade por atendente, somar "notas"; se não houver ponto natural, deixar de
fora e avisar o dono. Nota não conta como resposta nem para o SLA.

## Para o construtor decidir

Nome do concern/componente, forma exata do cartão, textos finos, ícones do Flux, e como o Alpine do campo (`typing-presence.js`) é
silenciado no modo nota.

## Testes que provam o essencial

Nota não cria `Message` nem `SendTicketMessageJob`, não chama o WAHA (`Http::assertNothingSent`), não muda `last_outbound_at`/SLA; só o
autor apaga (403 para outro, inclusive admin); nota aparece no transcript na ordem do horário; modo nota não anuncia presença; item do
menu preenche o campo sem gravar; rascunhos por modo não se misturam (teste de view + JS verificado no navegador).
