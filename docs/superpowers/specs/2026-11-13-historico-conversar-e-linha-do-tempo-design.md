# Histórico: botão "Conversar" e linha do tempo legível (design curto)

Pedido do dono em 13/11/2026, com as escolhas feitas no chat (abaixo). Não há `docs/superpowers/menores-pendentes.md`.

## Intenção e porquê

Quem olha um ticket encerrado no Histórico quer (1) voltar a falar com aquele contato e (2) ler o que aconteceu no ticket sem dado interno. Hoje a barra da direita não tem como conversar e a linha do tempo mostra coisas como `bot_started ({"workflow_version_id":2})` e `bot_abandoned`.

## Objetivos

1. Botão **Conversar** no painel de detalhes do Histórico. Contato com ticket aberto: vai direto para o ticket no Inbox. Sem ticket aberto: abre a janela **Nova conversa** com o contato escolhido (canal, setor e primeira mensagem, como na Fila e em Contatos).
2. Linha do tempo sem JSON bruto e sem nome técnico de tipo, com os eventos do bot em português.

## Fora do escopo

- Mudar `StartConversation` (a primeira mensagem continua obrigatória).
- Mudar a conversa (transcrição) ou os filtros do Histórico.

## Abordagens consideradas

**Botão**
- **A. Centralizar em `NewConversation::open($contactId)`** (escolhida pelo dono): a regra "ticket aberto vai direto ao ticket" fica num lugar só e vale em todas as entradas (Fila, Contatos, ficha, cartão de contato recebido, Histórico). Custo: o "Conversar" de Contatos também passa a ir direto ao ticket aberto, em vez de abrir a janela e avisar depois de o usuário digitar a mensagem.
- B. Só no Histórico, em `History\Index`: não mexe nas outras telas, mas duplica a regra.

**Linha do tempo:** um caminho só (abaixo); o dono escolheu mostrar **todos** os eventos do bot, em português, e corrigir também a Auditoria (sem JSON bruto).

## Design

**Botão Conversar**
- `NewConversation::open(?int $contactId)`: com `$contactId`, busca o ticket aberto mais recente do contato que o usuário pode ver (`Ticket::open()->readableBy($user)`, por `opened_at` desc). Se achar, `redirectRoute('inbox', ['ticket' => $id])` e não abre a janela. Sem ticket legível, abre a janela como hoje.
- `history/index.blade.php` passa a carregar `<livewire:inbox.new-conversation />` (hoje só o Inbox e Contatos carregam; sem ele o evento `start-conversation` não chega a ninguém).
- O cartão de contato compacto (`livewire/contacts/contact-card.blade.php`) mostra o botão `chat-button` também em modo somente leitura (conversar não edita o contato), com o mesmo `canChat` de hoje (ticket atual encerrado ou ausente).

**Linha do tempo** (`TicketEventText`, `TicketTimeline`, `livewire/history`, `livewire/audit`)
- Rótulos e frases novos:
  - `bot_started` → "Bot iniciou o atendimento".
  - `bot_abandoned` → "Equipe respondeu pelo celular; a triagem do bot acabou" (é o que `BotEntry::humanTookOver` faz).
  - `bot_handoff` → "Bot encaminhou para {setor}" (ou "Fila geral" sem setor), com o motivo do payload quando conhecido: `fluxo` sem sufixo; `sair` "cliente pediu atendente"; `erros` "respostas inválidas"; `timeout` "sem resposta do cliente"; `sem_workflow` "sem automação ativa". Motivo desconhecido: sem sufixo.
- `TicketTimeline::detail` deixa de devolver o payload em JSON para tipos sem formato próprio (devolve vazio).
- Histórico: mostra só tipos com rótulo conhecido (tipo desconhecido fica escondido). Auditoria: continua listando todos; tipo desconhecido aparece pelo nome do tipo, sem JSON.

## Decisões minhas (o dono corrige)

- Vários tickets abertos do mesmo contato (canais diferentes): vai ao mais recente legível. Custo se errado: cai num ticket de outro canal.
- Ticket aberto que o usuário não pode ver (outro setor): abre a janela como hoje; a ação `StartConversation` já devolve o ticket existente. Custo se errado: o usuário digita a mensagem e recebe o aviso de que já existe conversa.
- O texto do `bot_abandoned` descreve o código atual (alguém respondeu pelo celular); se o evento ganhar outros usos, o texto muda.

## O que fica para o construtor

Posição exata do botão no cartão compacto, nomes dos testes e das constantes de motivo.
