# UX fatia 4b: componentes de base (selo, botão, estado vazio, esqueleto) e as demais telas

Data: 2026-11-10. Só o beta está em escopo. Vem depois da 4a (`2026-11-10-ux-inbox-720p-design.md`). Laravel 13, Livewire 4, Flux 2.21 (grátis),
Tailwind 4.

## Por que

O mesmo estado aparece com formas e cores diferentes conforme a tela, os botões não têm regra e a espera por dados é silenciosa. Levantado no código:

- **Status**: o Inbox usa `flux:badge` com cores cruas (sky/amber/green) de uma função local (`$statusColor`); Histórico, Usuários, Canais, Relatórios,
  Contatos/Google e o sino desenham os seus selos à mão, cada um com classes próprias. Nada reaproveitado.
- **Botões**: 116 `flux:button` (44 `subtle`, 32 `primary`, 4 `danger`), 14 `x-ui.button` (`channel-card` 14, `channels` 2, `login` 2) e 55 `<button>` crus
  (avisos do topo, gatilhos Alpine, menus). Sem regra de quando usar qual nem de quantas ações primárias por tela.
- **Estados vazios**: cerca de uma dúzia de textos soltos ("Nenhum…") sem ícone nem orientação, cada um do seu jeito.
- **Carregamento**: 11 `wire:loading` e **nenhum esqueleto**; trocar de ticket, filtrar o Histórico ou buscar contato não dá retorno visual.

## Metas

1. **`x-status-badge`** (componente Blade anônimo em `resources/views/components/`): recebe um estado e mostra o selo com o **tom** certo do app inteiro.
   - O tom sai de um método no próprio enum (`tone(): string`, fonte única) em `TicketStatus`, `ChannelStatus`, `SectorStatus`, `GoogleConnectionStatus`,
     `CloseOutcome` e `UserRole`; estados que não são enum (SLA `perto|estourou`, usuário ativo/inativo, workflow publicado/rascunho) usam os tons por nome.
   - Tons: `neutral`, `info`, `ok`, `warn`, `danger`, `accent` mapeados para os **tokens** do app (`ok`, `warn`, `danger`, `accent`, `bot`, `ink-*`), com
     texto legível no claro e no escuro (AA, `ThemeTokensTest` continua verde). Vermelho só para SLA estourado/erro; verde só `ok`.
   - Aplicado em todos os pontos acima (Inbox, Histórico, Usuários, Canais, Contatos/Google, Relatórios, Automação, Auditoria); some `$statusColor` do Inbox.
2. **Regra de botões**, escrita em `docs/ui-botoes.md` e **aplicada**:
   - Só `flux:button`: os 14 `x-ui.button` migram (`primary` → `variant="primary"`, `secondary` → `variant="filled"`, `ghost` → `variant="ghost"`).
   - Uma ação **primária** por tela ou seção; `subtle` para o secundário; `danger` só para destrutivo; ícone sem texto sempre com `aria-label` e `tooltip`.
   - Os `<button>` crus que são **ação** (ex.: silenciar som, ativar som, reenviar) viram `flux:button size="xs"`; gatilhos Alpine internos e itens de menu ficam.
3. **`x-empty-state`**: ícone, título, descrição curta e slot opcional de ação (`$action->isEmpty()`); substitui os textos soltos. Texto em português, diz o que fazer.
4. **Esqueleto de carregamento** com `flux:skeleton` (grátis): linhas de lista (avatar + duas linhas) e blocos, `animate="pulse"` só com `motion-safe`.
   Usado onde o usuário espera: lista do Histórico e de Contatos ao filtrar/buscar (`wire:loading` com `wire:target` nos filtros), tabelas de Relatórios,
   Auditoria e Automação ao mudar o período/filtro, e a conversa do Inbox ao trocar de ticket (`wire:target="select"`). Sem esqueleto na primeira pintura
   (a página já vem renderizada do servidor).
5. **Demais telas** (Contatos, Histórico, Relatórios, Auditoria, Automação) revisadas por inteiro com os componentes e alinhadas em 720p, 1080p e 2K.

## Não metas

- Mudar regras de negócio, dados, rotas, portões `can:*`, tempo real ou a lista da Fila.
- Novas telas, novas cores fora dos tokens, biblioteca de ícones nova, Flux Pro.
- Mexer na tela de login além de trocar `x-ui.button` por `flux:button` (visual igual).

## Anti-metas

- O mesmo estado com dois tons em telas diferentes; cor sem texto (selo só com cor); `aria-label` faltando em botão de ícone.
- Esqueleto que causa pulo de layout, animação sem `motion-safe`, ou que fica preso por falha na requisição.
- Selo, botão ou estado vazio novo que quebre um teste existente sem ter o teste atualizado com o motivo.

## Restrições e pesquisa

- **Blade (Laravel 13)**: componente anônimo em `components/`, `@props` primeiro, enum passado como `:status="$x"`; `class` mescla por `$attributes->class(...)`;
  slot nomeado vazio por `$action->isEmpty()` (`hasActualContent()` ignora comentário). Sem mudança de API da 12 para a 13 nos docs lidos.
- **Flux 2.21 grátis**: `flux:badge` (sem token de cor própria, só paleta nomeada) por isso o selo é um `span` com classes de token e **não** `flux:badge color=`;
  `flux:button` carrega spinner sozinho com `wire:click`/`submit`; `flux:skeleton` (+ `.group`, `.line`) tem `animate="shimmer|pulse"`, sem tratamento de
  `prefers-reduced-motion` (nós aplicamos). Flux não tem componente de estado vazio: construímos.
- **Livewire 4**: `wire:loading`/`wire:target` com `.delay` para não piscar; `data-loading` com variante `data-loading:` do Tailwind; `#[Lazy]`/`placeholder()` ficam
  para componentes filhos (não para a página roteada) e só se um caso real aparecer.
- Context7 do projeto estava com a chave antiga; usar o global (OAuth) nas próximas sessões.
- Testes: um teste por mapeamento do enum (tom esperado), por componente (renderiza o tom, o texto e o `aria`), e os testes existentes das telas atualizados;
  proibição em `ThemeTokensTest` estendida para `statusColor`/cor crua em selos. Verificação no **Claude in Chrome**, três resoluções, dois temas.

## Abordagens consideradas

- **`tone()` no enum + `x-status-badge` com tokens** (escolhida): uma tabela de verdade por enum, testável, e o selo respeita o tema.
- **`flux:badge` com `color=`**: reaproveita o Flux, mas a paleta é nomeada (amber/sky), fora do sistema de tokens e fixa no escuro.
- **Um componente por tipo de estado** (`x-ticket-badge`, `x-channel-badge`…): mais código repetido e fácil de divergir.
- **Esqueleto com `#[Lazy]` em cada página**: páginas roteadas não carregam em lazy; o ganho real está em filtros e troca de ticket.

## Deixado ao builder

Os tons exatos de cada estado (dentro das regras acima); o nome dos métodos auxiliares; a granularidade dos esqueletos por tela; quais `<button>` crus
são ação e quais são gatilho interno; o texto e o ícone de cada estado vazio.

## Entrega

PR 2 = branch novo a partir da `main` depois do merge do PR 1. Todo achado de revisão, inclusive menor, é corrigido antes do PR. Merge, `deploy-beta` e teste
no beta com o Claude in Chrome nas três resoluções.
