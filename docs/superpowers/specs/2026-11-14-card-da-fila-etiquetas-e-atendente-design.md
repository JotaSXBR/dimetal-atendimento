# Card da Fila: mais etiquetas e o atendente na coluna da direita (design curto)

Pedido do dono em 14/11/2026, com as escolhas feitas no chat (abaixo). Não há `docs/superpowers/menores-pendentes.md`.

## Intenção e porquê

No card de cada ticket da Fila, quando o contato tem várias etiquetas só 3 aparecem e o resto vira "+2". O dono quer ver mais etiquetas sem abrir o ticket, e quer o colaborador que está atendendo em outro lugar do card (hoje fica na linha de baixo, junto do canal).

## Objetivos

1. Mostrar até **6** etiquetas (as do contato e as da conversa, na ordem de hoje); acima disso, "+N" com a lista completa no tooltip, como já é.
2. Mover o atendente (avatar pequeno e primeiro nome) da linha de baixo para a **coluna da direita**, sob a hora e o setor e acima do contador de não lidas.

## Fora do escopo

Mudar a ordem das etiquetas, o tamanho do chip, a Fila em outras telas (`x-contact-chips` do Contatos e do Histórico seguem com 3), ou o card em tela estreita (a auditoria 720p/1080p/2K/360 é do dono).

## Abordagens consideradas

- **Etiquetas:** (a) todas, quebrando linha; (b) **até 6 e "+N"** (escolhida); (c) todas em no máximo 2 linhas. Escolhida a (b): altura limitada e nada some sem aviso.
- **Atendente:** (a) **coluna da direita** (escolhida); (b) avatar sobre a foto do contato; (c) na linha do nome.

## Design

`resources/views/livewire/inbox/inbox.blade.php`, no card:
- `take(3)`/`slice(3)` passam a `take(6)`/`slice(6)` (uma variável `$maxChips = 6`).
- O bloco do atendente sai do `card-meta` e entra na coluna da direita (`flex-col items-end`), entre o setor e as não lidas: `<x-avatar size="xs" decorative>` e o primeiro nome em `data-testid="card-assignee-name"` com `max-w-16 truncate` e o nome completo em `title`. `$hasMeta` passa a depender só de `$showChannel`.
- O resto do card (nome, prévia, canal, SLA, hora, setor, não lidas) não muda.

## Decisões minhas (o dono corrige)

- **Avatar mais o primeiro nome, truncado em 64 px**, e não só o avatar. Custo se errado: em coluna estreita o conteúdo da esquerda perde cerca de 60 px nos tickets atribuídos, e as etiquetas quebram mais; a saída é só o avatar com o nome no tooltip.
- Ticket sem atendente (pendente, aguardando): nada aparece, como hoje.
- Esta mudança fica num branch próprio, separado do P1.

## O que fica para o construtor

Nomes de testes e o ajuste fino das classes de largura.
