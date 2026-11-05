# Fatia 5: iniciar conversa pelo app e adicionar contato (design)

Parte da spec `2026-11-03-inbox-completa-design.md` (fatia 5), detalhada em conversa com o dono em 05/11. Regras já decididas por ele
em 03 e 04/11 estão marcadas como **(dono)**; o que entrou só por proposta minha está marcado como **(minha escolha)**.

## Intenção

Hoje só o cliente abre ticket. O atendente precisa começar uma conversa com quem ainda não escreveu (retorno de orçamento, cobrança,
contato recebido de um colega) sem sair do app e sem perder o controle: dono, setor, canal, auditoria e SLA corretos desde o primeiro
envio. É a única fatia que muda a regra "só o cliente abre ticket", então leva o maior cuidado com permissão e rastro.

## Metas

- Botão **"Nova conversa"** no topo da Fila (**dono pediu o ponto de entrada**) e **"Conversar"** na lista e na ficha de Contatos e no
  cartão de contato recebido. Todos abrem **a mesma janela**.
- No cartão de contato recebido, **"Adicionar aos contatos"** (cria ou reaproveita, sem duplicar) junto de **"Conversar"**, no lugar do
  link `wa.me`.
- A janela pede: **contato** (escolher da lista ou **digitar um telefone novo**, que cadastra o contato; **minha escolha**, o dono não
  respondeu e aceitou o padrão), **canal**, **setor** (o atendente que cobre mais de um setor escolhe; **dono**) e a **primeira mensagem**.
- O ticket só nasce **junto com o envio** da primeira mensagem (**dono**): nada de ticket vazio se o atendente desistir ou o envio for
  recusado antes de começar.

## Não metas

Modelos de mensagem e janela de 24 h da API oficial do WhatsApp (referência futura: o projeto `wacrm`, ver memória
`projetos-de-referencia`), transmissão em massa, foto do contato, iniciar conversa por e-mail ou outro canal, anexo na primeira mensagem
(só texto; anexos seguem pelo campo de resposta depois).

## Anti-metas (é fracasso mesmo que funcione)

- Ticket sem dono, sem setor ou sem rastro (**dono**: evento `opened_by_agent` com o autor).
- Mais de um ticket aberto para o mesmo contato e canal (o índice único `tickets_one_open_per_contact_channel` já protege; a ação deve
  reaproveitar o aberto em vez de estourar o índice).
- O SLA contar "cliente esperando" só porque a equipe escreveu primeiro (**dono**).
- O bot (workflow do canal) assumir a conversa iniciada pela equipe (**dono**: não passa pelo bot).
- Mandar mensagem a número que não tem WhatsApp, ou em rajada que arrisque o número da empresa.

## Abordagens consideradas

1. **Uma janela só, reaproveitada pelos pontos de entrada; valida o número no WhatsApp e cria ticket e mensagem juntos (escolhida pelo dono).**
2. Criar o ticket vazio e abrir o campo de resposta: reaproveita mais código, mas deixa ticket sem mensagem se o atendente desistir,
   o que o dono vetou.
3. Só a partir de Contatos e do cartão, sem botão na Fila nem telefone avulso: mais barata, mas não resolve "onde começo uma conversa nova".

Referência de mercado (sem citar em commit): o Chatwoot põe "New Conversation" na ficha do contato (v1.15.0); o WAHA oferece
`GET /api/contacts/check-exists` para saber se o número tem WhatsApp.

## Desenho

**Ação de servidor** `App\Actions\Inbox\StartConversation` (padrão de `ClaimTicket`/`TransferTicket`): recebe usuário, contato, canal,
setor e texto; devolve o ticket (novo ou o já aberto). Ordem: (1) permissões e regras abaixo; (2) trava consultiva do Postgres por
contato e canal, como `IngestMessage::openTicketFor`; (3) se já há ticket aberto do contato naquele canal, devolve-o **sem criar nem
enviar** (o texto digitado vai ao campo de resposta dele quando o usuário pode responder; senão só o aviso de quem é o ticket);
(4) senão, numa transação: `Ticket` em `em_atendimento`, `assigned_user_id` = autor, `sector_id` escolhido, `opened_at` = agora, evento
`opened_by_agent` (payload: autor, canal, setor); `SendTicketMessage::create` (origem painel, com assinatura como no envio normal) e o
`SendTicketMessageJob`; `TicketUpdated`. O protocolo sai do modelo, como hoje.

**Regras de permissão e validade**
- Qualquer usuário ativo pode iniciar (**minha escolha**); o atendente só nos setores que cobre; supervisor e admin em qualquer setor que
  receba tickets. Setor é obrigatório quando há opção (**dono**).
- Canal ativo e conectado (`isConnected`); canal fora do ar fica desabilitado na lista, com o motivo.
- Contato precisa de telefone válido (`wa_id` `@c.us`); contato só com `lid` não pode ser iniciado (botão desabilitado, com o motivo).
- **Número tem WhatsApp:** novo método `WahaClient::numberExists(string $phone): bool` chamando `GET /api/contacts/check-exists`; o
  formato exato da resposta se confirma no beta antes de liberar. Se o WAHA falhar, a ação recusa com aviso e **não cria nada**.
- **Ritmo (minha escolha, a pedido do dono de tratar as regras do WAHA):** só vale para conversa iniciada pelo app. No máximo 1 conversa
  iniciada por canal a cada 30 s e 4 por hora por contato (`RateLimiter`, mesma infra do envio); passou do limite, aviso com o tempo de
  espera e nada é criado. O envio normal de resposta fica como está.
- Sem bloqueio por horário comercial (**dono**).

**Canal e setor pré-selecionados (dono):** canal do ticket mais recente do contato, ou o único ativo; setor único do atendente já
vem preenchido.

**Ticket já aberto (dono):** abre o existente; se for de outro atendente, a janela mostra de quem é e a conversa abre só para leitura
quando a permissão não deixar responder.

**SLA (dono):** o ticket nasce sem `waiting_since` e sem `last_inbound_at`, então `SlaClock` não o conta; passa a contar quando o
cliente responder (o ingest do cliente já registra a entrada no ticket aberto, sem passar pelo bot).

**Tela.** Componente Livewire da janela (um só, aberto por evento a partir dos botões), com busca de contato por nome/telefone ou campo
de telefone, seletores de canal e setor e o campo de mensagem (limite de 4096, como o envio). Depois de iniciar, o Inbox abre o ticket.
"Adicionar aos contatos" usa `CreateContact` e mostra "Já está nos contatos" quando reaproveitou.

**Auditoria:** `opened_by_agent` entra em `TicketEventText` e `TicketTimeline`.

## Para o construtor decidir

Nome e posição do componente da janela, busca de contato (reaproveitar o que a tela Contatos já usa), tratamento de cartão com vários
telefones (ação no primeiro válido), nome em branco ao digitar telefone novo (gravar nulo; o app já mostra o telefone), textos finos.

## Testes que provam o essencial

Ticket e mensagem nascem juntos e nada sobra quando o número não existe, o canal está fora do ar ou o WAHA falha; reaproveita o ticket
aberto sem criar outro (inclusive em corrida); atendente não inicia em setor que não cobre; dono, status, evento `opened_by_agent` e
sem bot; SLA não conta até o cliente responder; limites de ritmo; "Adicionar aos contatos" não duplica; botões só nos lugares certos.
