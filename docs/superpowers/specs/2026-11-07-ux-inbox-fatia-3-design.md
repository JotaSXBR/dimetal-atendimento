# UX fatia 3: Inbox (lista, coluna de detalhes, bolhas)

Data: 2026-11-07. Só o beta está em escopo. Segue a fatia 1 (`2026-11-06-ux-fundacao-e-menu-hub-design.md`): tokens
`ink/canvas/surface/accent/warn/danger/ok/bot`, marca amarela, tema escuro.

## Por que

O dono opera o Inbox o dia todo. Três incômodos concretos:

1. **Coluna da direita cresce sem fim**: o cartão do contato lista os 10 tickets anteriores (`ContactCard::HISTORY_LIMIT`) e a
   linha do tempo vem logo abaixo, tudo aberto.
2. **Item da fila diz pouco**: avatar, nome, hora, prévia e etiquetas do ticket. Não mostra quem atende, o setor, nem há
   quanto tempo o cliente espera.
3. **Bolhas sem padrão**: o horário fica dentro do texto (flutuando) nas mensagens de texto e numa linha à parte em mídia e
   áudio; os ticks acompanham o horário, então mudam de lugar junto.

## Referências (só inspiração visual, não citar em commit/PR)

- Flux Pro (site fluxui.dev): Accordion e Timeline são Pro e não estão instalados; o visual deles é feito à mão com o que temos
  (grátis: `avatar` com selo, `badge`, `skeleton`, `tooltip`). Flux não tem exemplo de chat/inbox.
- CRMs de WhatsApp (Helena, Synthor, Selliq): card com avatar, nome + hora, prévia e uma linha de selos (atendente com avatar
  e primeiro nome, setor, etiquetas); destaque para o tempo de espera; coluna da direita em blocos recolhíveis.
- Chats em geral (WhatsApp, blocos de chat em Tailwind/shadcn): hora e ticks juntos no canto inferior direito da bolha.

## Metas

### 1. Coluna da direita (`components/ticket-details`, `livewire/contacts/contact-card`)

Blocos recolhíveis com `<details>`/`<summary>` nativos, estilizados como o accordion do Flux (título pequeno em caixa alta,
chevron que gira, separador `border-line`). Teclado e leitor de tela vêm do HTML.

| Bloco | Estado inicial | Conteúdo |
|---|---|---|
| Ticket | aberto | o que já existe (canal, setor, responsável, SLA, datas) |
| Contato | aberto | a ficha compacta atual, sem a lista de anteriores |
| Anteriores (N) | aberto se houver algum | os **5** últimos tickets do contato (era 10) + link "Ver todos no Histórico" filtrado pelo contato |
| Linha do tempo | **fechado** | a linha do tempo atual |

- O estado aberto/fechado escolhido pelo usuário vale enquanto a página estiver aberta (Alpine; sem gravar no servidor).
  Deve sobreviver ao re-render do Livewire (`wire:ignore.self` no `<details>` ou estado em Alpine).
- "Ver todos no Histórico": aparece só se o contato tiver mais de 5 tickets anteriores. Leva a `/history?contactId=ID`, um
  filtro novo no Histórico (`#[Url]` ao lado de `sectorId`/`userId`, aplicado no `HistorySearch` como `where contact_id`),
  que mostra só os encerrados daquele contato que o usuário pode ver (`inHistoryOf`). Aprovado pelo dono (07/11), em vez do
  `?q=`, que traria tickets de outros contatos que citam o nome ou telefone. O id vem da URL: validar inteiro e não vazar nada
  além do que `inHistoryOf` já permite.
- Os blocos recolhíveis valem só no modo compacto (Inbox). A ficha completa em Contatos fica sem `<details>`; o limite de 5 vale nos dois (mesma constante). *Minha decisão.*

### 2. Item da fila (`livewire/inbox/inbox.blade.php`, grupos Pendente/Aguardando/Em atendimento)

```
┌───────────────────────────────────────────┐
│ (JP)  João Pereira                  14:32 │
│  ●    Oi, qual o valor do frete?          │
│       (M) Marina · Vendas · [Orçam.] +1  há 12 min │
└───────────────────────────────────────────┘
```

- Linha 1: nome (truncado) e hora da última mensagem (como hoje).
- Linha 2: prévia com o "digitando…" (como hoje).
- Linha 3 (selos, `text-xs`, cores de token):
  - atendente: avatar `xs` com iniciais + primeiro nome; **só se houver atendente**;
  - setor: texto discreto; **some se só existe um setor que recebe tickets no sistema todo** (contagem global, como o `showChannel`). *Minha decisão.*
  - etiquetas do ticket: no máximo 2 e "+N" (com `title` listando as demais); a cor vem da etiqueta, com texto legível nos
    dois temas (não usar `text-white` cru; respeitar o guarda de `ThemeTokensTest`);
  - à direita, "há X min/h" para ticket com `waiting_since` não nulo (cliente esperando resposta, inclusive em atendimento). *Minha decisão.*
- Ponto no avatar (selo do `flux:avatar`) só para SLA: `warn` quando perto, `danger` quando estourado (`slaState()`).
  Vermelho continua sendo só SLA estourado.
- Linha 3 inteira some quando não há nada a mostrar (card de 2 linhas).
- Carregar `assignee` e `sector` no `with()` da lista (sem N+1). A espera é renderizada no servidor e atualiza no próximo
  render (sem relógio em JS nesta fatia).
- A aba "Encerrados" mantém o card simples atual.

### 3. Bolhas (`transcript`, `message-time`, `media`, `audio-player`)

- Hora + ticks sempre juntos, no canto inferior direito da bolha, em todos os tipos (texto, mídia com legenda, documento,
  áudio, localização, contato, citação, apagada).
- Foto/vídeo **sem legenda**: hora + ticks sobre a imagem, no canto inferior direito, com fundo escuro translúcido e texto
  claro (legível nos dois temas).
- Texto e mídia com legenda: no fim da última linha, como hoje no texto (`float` à direita), sem quebrar o "editada".
- Áudio e documento: na mesma linha do controle, alinhado à direita, na base.
- Recebidas: só a hora (sem ticks), mesma posição.
- Um único partial (`message-time`) decide a posição por um parâmetro (`inline`, `overlay`, `below`), sem cópias.

## Não metas

- Etiqueta de **contato** (não existe; só ticket tem) — feature futura.
- Contador de não lidas (o app não guarda leitura por usuário) — feature futura.
- Ícone de canal no avatar (hoje só WhatsApp).
- `x-status-badge`, botão unificado, `x-empty-state`, `x-skeleton`: ficam para a fatia 4, quando forem usados em várias telas.
  Esta fatia só usa o que o Flux grátis já tem.
- Resposta otimista ao assumir/trocar status.
- Mudar regras de visibilidade, SLA, portões `can:*` ou eventos de tempo real.

## Anti-metas

- Card da fila poluído: mais de 3 linhas, ou selos que se repetem (setor quando só existe um setor).
- Cor nova fora dos tokens; vermelho fora de SLA estourado/erro.
- Quebrar o "digitando…", a seleção do ticket, o tempo real (Reverb) ou o layout no celular (lista 340px, coluna em gaveta).

## Restrições

- Livewire 4 + Flux grátis + Tailwind 4 + Alpine; sem dependência nova.
- CodeGraph não indexa Blade: buscas em `resources/views` por `grep` no Bash.
- Testes de Livewire para o que muda de comportamento: limite de 5 e link do Histórico; filtro `contactId` (só o contato, só o que o usuário vê, id inválido ignorado); selos do card (atendente, setor
  escondido com um setor, +N, espera só com `waiting_since`, ponto de SLA); posição da hora por tipo (`data-testid`).
- Portão 7 inteiro (suíte com Redis, Pint, Larastan, PHPMD) e verificação visual nos dois temas e em 340px.

## Abordagens consideradas

- **Coluna da direita.** A: blocos recolhíveis (accordion à mão), 5 anteriores + link ao Histórico. B: 5 + "mostrar mais"
  carregando ali mesmo. **Escolhida A pelo dono**: para o crescimento da coluna sem tela nova; B deixava a linha do tempo
  empurrando tudo.
- **Accordion.** `flux:accordion` é Pro (não instalado). `<details>` nativo dá teclado/ARIA de graça; Alpine puro exigiria
  refazer isso. **Escolha minha**: `<details>`.
- **Atendente no card.** Só iniciais (Helena) ou avatar + primeiro nome (Synthor). **Escolhido** avatar + primeiro nome, após
  o dono mostrar o Synthor: mais legível para quem opera.
- **Bolhas.** Padrão WhatsApp (hora e ticks juntos no canto). Aprovado pelo dono.

## Deixado ao builder

- Classes exatas, tamanhos e espaçamentos dentro da escala do Tailwind e dos tokens.
- Como preservar o estado do `<details>` no re-render.
- Formato do "há X" (min até 59, depois h, depois d).
