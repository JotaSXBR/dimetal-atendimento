# UX, fatia 0 e 1: fundação visual (amarelo, claro/escuro) e menu com Configuração em hub

Data: 2026-11-06. Branch: `feat/ux-fundacao-e-menu-hub`. Só o beta está em escopo.
Esta é a primeira de uma série de fatias do redesenho de UI; as demais (Inbox, demais telas, QA de acessibilidade) têm spec própria.

## Por que

O dono é hoje o único usuário (não há atendentes ainda) e sente o menu lateral poluído: o admin vê 15 itens em 3 seções, e o
título "Atendimento" aparece duas vezes (seção de trabalho e grupo da Configuração). As páginas de Configuração ainda repetem os
irmãos do grupo como pílulas no topo mais um breadcrumb, que o menu lateral já mostra. Além disso, o modo escuro "não funciona" e
as views misturam cores cruas (`zinc/red/green/amber-*`) com os tokens próprios do `app.css`. O dono quer, nesta ordem: menu enxuto,
padrão de cor/botão/animação, e informação melhor disposta. Esta fatia entrega o menu e a base visual que as próximas usam.

## Metas

- Menu lateral com 6 entradas para o admin (Fila, Contatos, Histórico, Relatórios, Auditoria, Configuração), sem títulos de seção.
- Configuração vira **um único item** que abre uma página com navegação própria (hub); some a duplicação (pílulas, breadcrumb).
- Identidade nova: **amarelo** (`yellow-400`) como marca; **o verde deixa de ser identidade** (fica só como cor de status "ok").
- Modo escuro funcional em todo o app, **opcional**, com seletor Claro / Escuro / Sistema no menu do nome do usuário (embaixo da sidebar).
- Tokens semânticos que se trocam sozinhos no escuro (as views que já usam `text-ink`, `bg-canvas`, `bg-surface` passam a funcionar sem `dark:`).

## Não metas

- Componentes `x-status-badge`, botão unificado, `x-empty-state`, `x-skeleton`: spec própria, junto da fatia do Inbox.
- Refazer o layout do Inbox, de Contatos, Histórico, Relatórios, Auditoria, Automação (fatias 3 e 4). Aqui só garantimos que **todas abrem legíveis no escuro**.
- Tokens novos de espaçamento, raio e sombra: a escala do Tailwind já serve; a convenção 4/8/24 fica documentada, sem código novo.
- Features que viram decisão de produto: respostas por IA, confete/resumo ao encerrar, checklist de encerramento, auto-save, exportar (fatias futuras, a avaliar com o dono).
- Mexer em portões (`can:*`), rotas, `SidebarState`, SLA ou realtime.

## Anti-metas (falha mesmo que "funcione")

- Quem hoje abre uma tela de Configuração perder acesso a ela, ou ver item que o portão da rota nega.
- Texto ilegível no escuro, ou amarelo como texto pequeno sobre branco (abaixo do contraste AA).
- Aviso (âmbar) indistinguível da marca, ou o vermelho aparecer fora de SLA estourado / erro / ação destrutiva.

## Abordagens consideradas

**Menu.** (A) Hub: um item "Configuração" leva à página com navegação própria na coluna da página. (B) Accordion fechado na
própria sidebar (aberto, volta aos 15 itens e mantém a duplicação). (C) Drill-down, que troca o conteúdo da sidebar (mais código
e estado, e mexe na sidebar compartilhada com o mobile). **Escolhida pelo dono: A**, por cortar a duplicação e reaproveitar
`Navigation::tree()` e `x-settings-page`.

**Escuro (decisão minha, não discutida).** (1) Sobrescrever os tokens (`--color-ink`, `--color-canvas`...) dentro de `.dark`,
de modo que as classes existentes se adaptem; (2) escrever `dark:` view a view. Escolhi (1): menos edição, uma só fonte da verdade.
Custo se errado: cores cruas que não passam por token precisam de `dark:` pontual (ver Critérios).

## Desenho

### Navigation (`app/Support/Navigation.php`, `NavItem.php`)

- A lista `items()` continua a fonte única; os itens de Configuração mantêm `section` e `group`. Os títulos de seção
  "Atendimento" e "Acompanhamento" deixam de ser renderizados (a sidebar mostra uma lista só).
- Novas leituras, todas filtradas por `Gate`/`can` como hoje:
  - `primary(User): list<NavItem>`: os itens fora de Configuração.
  - `settingsGroups(User): array<string, list<NavItem>>`: grupo → itens de Configuração que o usuário pode abrir.
  - `settingsEntry(User): ?NavItem`: o primeiro item de Configuração visível (destino do link do menu); `null` se nenhum.
- `siblings()` e a seção de pílulas saem (YAGNI); `tree()` fica se algo ainda o usar, senão sai também.
- O item "Configuração" fica ativo em qualquer rota de Configuração (`configuracoes.*` e `automacao*`).

### Sidebar (`layouts/app/sidebar.blade.php`)

- Lista plana: `primary()` e, se `settingsEntry()` não for nulo, "Configuração" (ícone `cog-6-tooth`) com um pequeno espaço antes.
- Fila: badge **amarelo** com a contagem de aguardando e, só se houver SLA estourado, um badge **vermelho** ao lado (com
  `aria-label`). O texto "N aguardando / N com SLA estourado" sai. `SidebarState` não muda.
- O aviso de WhatsApp desconectado continua, usando o token `warn`.
- O dropdown do usuário ganha o seletor de tema (ver abaixo), no desktop e no cabeçalho mobile.

### Página de Configuração (`components/settings-page.blade.php`)

- Duas colunas em telas largas: navegação vertical à esquerda (grupos Atendimento, Equipe, Conexões, Empresa, com título pequeno
  e o item atual com `aria-current="page"`), conteúdo à direita. Em telas estreitas a navegação vira uma faixa no topo, com
  rolagem horizontal.
- Se o usuário só pode abrir **um** item de Configuração, a navegação não aparece.
- A largura útil do conteúdo mantém os valores atuais (`3xl`, `5xl`, `6xl`); a coluna da navegação soma ao contêiner.
- Some o breadcrumb "Configuração › Grupo". As 10 views continuam passando `route`, `title`, `subtitle`, `width`.

### Identidade e tokens (`resources/css/app.css`)

- Marca: `--color-accent` = `yellow-400`, `--color-accent-foreground` = `yellow-950` (botão primário e foco). Texto/link de marca no
  claro usa `yellow-700` ou mais escuro (o `yellow-600` dá cerca de 3:1 sobre branco e reprova AA); no escuro, `yellow-400`.
  O contraste exato de cada par entra na verificação.
- Cinzas: continua o remapeamento `zinc → stone` já existente.
- `ink`, `ink-2`, `ink-3`, `line`, `canvas`, `surface`, `accent-soft`, `accent-deep`: mesmos nomes, **valores redefinidos em `.dark`**.
- `warn` passa a um laranja-queimado (`orange-700` no claro, `orange-400` no escuro), afastado do amarelo da marca; aviso sempre
  vem com ícone ou texto, nunca só cor. `danger` vermelho fica reservado a SLA estourado, erro e ação destrutiva. Novo `ok` (verde)
  só para estados de sucesso (conectado, resolvido). `bot` ganha par claro/escuro.
- Movimento: `--duration-base: 200ms` (faixa 150 a 250 ms), `--ease-out`, e um bloco `@media (prefers-reduced-motion: reduce)`
  que zera transições e animações. Aplicado às transições da sidebar e da navegação de Configuração; o resto vem nas fatias seguintes.
- Cores cruas (`zinc/red/green/amber/sky/orange/blue-*`) nas views: **trocar por token só onde falharem no escuro** (contraste
  abaixo de AA). A troca geral e o `x-status-badge` ficam para as fatias 3 e 4.

### Tema escuro

- Faltando hoje: `@fluxAppearance` no `partials/head.blade.php` (hipótese: é a causa de o escuro não funcionar; **confirmar no
  navegador antes de afirmar**). Conferir a sintaxe atual na documentação do Flux (Context7).
- Componente pequeno `x-appearance-switcher` (Claro / Escuro / Sistema) ligado a `$flux.appearance`, usado nos dois menus do usuário.
- Padrão **Sistema**. A escolha fica no navegador (padrão do Flux): sem migration. A página `/settings/appearance` continua e usa o
  mesmo controle.

## Decisões minhas (o dono não as discutiu; custo se erradas)

| Decisão | Custo se errada |
|---|---|
| Sem títulos de seção na sidebar | Voltar a um `<p>` por seção: pequeno |
| Escuro por sobrescrita de tokens | `dark:` pontual em algumas views |
| Tema salvo no navegador, não no usuário | Não acompanha o dono entre aparelhos; coluna `theme` depois |
| `warn` laranja-queimado, separado do amarelo | Ajuste de dois valores no `app.css` |
| Navegação oculta com 1 só item de Configuração | Mostrar sempre: trivial |
| Link do item "Configuração" vai ao primeiro item visível | Criar página-índice depois |

## Testes e verificação

- `tests/Feature/Layout/NavigationTest.php` reescrito para `primary`, `settingsGroups`, `settingsEntry` (atendente 3 itens e sem
  Configuração; supervisor com Relatórios e Configuração apontando a Usuários; admin com 5 + Configuração; portão de cada rota
  confere), no lugar de `siblings` e dos títulos de seção.
- `CompanyPageTest::test_o_menu_se_chama_atendimento` e testes que citam o menu ou as pílulas: ajustar ao novo menu (raio de impacto
  pelo CodeGraph; as views pelo `grep`).
- Testes de view: a página de Configuração tem navegação com `aria-current` e sem breadcrumb; a sidebar tem um só "Atendimento"
  ou nenhum título; Fila com badge amarelo e, com SLA estourado, badge vermelho; o seletor de tema existe nos dois menus.
- Verificação visual por screenshot no navegador, claro e escuro, de: Fila, Contatos, Histórico, Relatórios, Auditoria, Automação e
  as 10 de Configuração; contraste AA conferido nos pares de marca. Portão 7 inteiro (suíte com Redis, Pint, Larastan, PHPMD).

## Fica para o construtor

Ícone e espaçamento exatos, largura da coluna de navegação, escolha dos tons finais de cada token (sujeita ao contraste medido),
nome dos `data-test`, e se `tree()` ainda é usado.
