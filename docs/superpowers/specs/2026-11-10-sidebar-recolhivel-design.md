# Sidebar que fecha para ícones e lembra a escolha

Data: 2026-11-10. Só o beta está em escopo. Mudança pequena: desenho curto. Segue a fatia 1 (`2026-11-06-ux-fundacao-e-menu-hub-design.md`).

## Por que

O dono quer ganhar largura no Inbox sem perder a navegação, e quer que a sidebar siga o padrão oficial do Flux. Pediu também que a escolha
(aberta ou só ícones) seja lembrada entre as visitas. Hoje a sidebar é `collapsible="mobile"`: só some no celular, e o botão de
colapso fica escondido no desktop (`lg:hidden`).

## Metas

- No desktop a sidebar fecha para só ícones (largura `w-14` do Flux) e reabre pelo botão `flux:sidebar.collapse`, visível no desktop.
- O estado fica guardado no navegador (propriedade `persist` do Flux, ligada por padrão; o Flux guarda em `localStorage`).
- Fechada, nada que o atendente precisa ver some sem aviso:
  - **Fila**: o ícone mostra um ponto, vermelho com SLA estourado, amarelo com ticket aguardando (hoje isso vive no badge e no selo
    de SLA, que o Flux esconde fechado). Vermelho continua sendo só SLA estourado.
  - **Itens**: tooltip com o nome (o `flux:sidebar.item` já faz com o texto do item).
  - **Sino de alertas** (`livewire:alerts.bell`) e **botão de colapso**: continuam acessíveis no cabeçalho fechado (pode empilhar).
  - **Aviso "WhatsApp desconectado"**: o texto some e um ícone de aviso com `title` e `aria-label` fica no lugar.
  - **Menu do usuário** (`x-desktop-user-menu`): só o avatar, abrindo o mesmo menu.
- Sem pulo visual ao carregar a página nem ao navegar com `wire:navigate` com a sidebar fechada.
- Layouts alinhados em 720p (1280x720), 1080p e 2K, nos temas claro e escuro, com a sidebar aberta e fechada (as telas Fila, Histórico,
  Contatos e Relatórios usam altura de tela cheia e colunas de largura fixa: a largura que a sidebar devolve tem de ir para a coluna
  de conversa, sem desalinhar).
- Celular: nada muda (gaveta como hoje).

## Não metas

- Trocar a sidebar por header (avaliado e adiado: gasta ~56px de altura, o recurso mais escasso em 720p, obriga a refazer a altura
  de 4 telas e a gaveta de celular continua existindo).
- Grupos expansíveis (`flux:sidebar.group expandable`): o menu tem 6 itens e Configuração é um hub.
- Mudar itens, ordem, rotas ou portões `can:*` do menu (`Navigation`), nem o `SidebarState`.
- Logo novo, ícones novos, `flux:icon` publicados.

## Anti-metas

- Perder o aviso de SLA estourado ou de fila com ticket quando a sidebar está fechada.
- Item do menu sem nome acessível quando fechado (leitor de tela: o texto continua no DOM ou há `aria-label`).
- Alvo de toque/clique abaixo de 44px nos ícones fechados.
- Cor nova fora dos tokens; texto cortado ou coluna desalinhada em qualquer das três resoluções.

## Restrições

- Flux 2.21 grátis (`flux:sidebar`, `.header`, `.collapse`, `.nav`, `.item`, `flux:spacer`); sem Flux Pro e sem dependência nova.
  `stashable` não existe no código (já é `collapsible="mobile"`).
- O Flux não documenta como badge, `icon-trailing` e elementos próprios se comportam fechados: a variante `in-data-flux-sidebar-collapsed-desktop:`
  existe no código do Flux e é a ferramenta, mas **tem de ser verificada no navegador**. O mesmo vale para o pulo ao carregar.
- Buscas em `resources/views` por `grep` no Bash (CodeGraph não indexa Blade).
- Testes: `SidebarTest` e afins para o markup novo (`data-*` estáveis); verificação visual no navegador nas três resoluções e nos dois temas.

## Abordagens consideradas

- **A. Manter a sidebar e deixá-la recolhível com `collapsible` + `persist`** (escolhida pelo dono em 10/11). Mudança pequena, sem
  gastar altura, devolve ~190px de largura à conversa.
- **B. Trocar por `flux:header` no topo**: mais trabalho e pior em 720p; fica como comparação para depois.

## Deixado ao builder

- Classes e tamanhos exatos; como empilhar sino e botão no cabeçalho fechado; se o ponto da Fila é um `span` próprio ou um `badge` do Flux.
- Como evitar o pulo ao carregar, se o Flux ainda o tiver (script no `head`, atributo no `html` ou o que o Flux já fizer).
