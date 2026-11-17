# Redesenho visual pelo design do Pencil

Data: 17/11/2026. Branch base: `feat/ui-redesign-pencil` (worktree `.claude/worktrees/ui-redesign-pencil`). Fonte visual:
`Dimetal.pen` (Pencil, telas de 30/10/2026, conferidas com componentes do Flux free).

## Intenção

O dono gostou do layout geral do design no Pencil: a organização, os componentes e o lugar dos botões e dos alertas.
Exemplo citado: no painel do ticket, os atendimentos anteriores aparecem em cartões com seta para a direita que levam ao
ticket. O app funciona, mas parece menos organizado. Objetivo: deixar o app apresentável seguindo o design, **sem função
nova**. É o "redesenho visual do dono" que a auditoria `docs/ux-audit/2026-11-14-ux-audit.md` deixou para a fase 3.

## Metas

- Cada tela do app segue o layout do Pencil: cabeçalho de página com título à esquerda e ações à direita, cartões,
  tabelas largas, estados (vazio, erro, carregando) e a posição de botões e alertas.
- Visual monocromático do design: primário preto (zinc), avatares cinza com iniciais, cor só para estado (SLA, conectado,
  erro, bot, aviso).
- Telas de gestão (Contatos, Relatórios, Auditoria, Histórico, Configurações) em largura toda, com limite em 2K para não
  esticar demais.
- Tema claro e escuro pelos mesmos tokens; o escuro também aprovado no Pencil.
- O Pencil vira o retrato fiel do app: o que o app não faz sai do desenho, o que o app tem e o desenho não ganha tela.

## Fora do escopo (não-metas)

- Nenhuma função, dado ou regra nova. Onde o design mostra o que o app não tem (exportar CSV, "Personalizado" no período,
  tags e "abrir vinculado" no Encerrar, CNPJ/razão social/LGPD em Empresa, sessões ativas e telefone no Perfil, "Importar
  CSV" em Contatos, coluna IP na Auditoria, "Esqueci a senha", filtros extras, contagens que o app não calcula), o elemento
  **sai do desenho** e não entra no app.
- Juntar telas que hoje são separadas (ex.: Usuários + Setores em "Equipe e setores", Perfil em página única no lugar das
  abas): só se não exigir mudar rota, componente Livewire ou regra; senão fica como está, com o visual novo.
- Flux Pro.
- Mobile estilo WhatsApp (`docs/ux-audit/2026-11-15-ux-audit-mobile.md`): fora; a fatia 6 só aplica o visual da base no
  celular sem estouro.
- Canvas do editor do bot: segue como está; muda só o cabeçalho, as cores dos blocos e os painéis laterais.

## Anti-metas (falha mesmo se "funcionar")

- Perder informação ou ação que o app tem hoje (ex.: "Assuma o ticket para responder", aviso do verificador de SLA,
  Recuperar mensagens, Importar histórico). O desenho se adapta ao app, não o contrário.
- Voltar atrás nas decisões da auditoria de 14/11 já entregues: piso de 12 px, alvos de 24 px (40 px no menu), confirmação
  em diálogo próprio, Desfazer, busca Ctrl+K, Automação na barra lateral.
- Tema escuro com contraste pior que o de hoje.
- Estouro ou rolagem lateral em 720p, 1080p, 2K ou 360 px.

## Restrições

- Laravel 13, Livewire 4.4, Flux 2 free, Tailwind 4. Tokens em `resources/css/app.css` (`--color-ink`, `-line`,
  `-canvas`, `-surface`, `-accent`, `-warn`, `-danger`, `-ok`, `-bot`, `-read`, com bloco `.dark`). Esses tokens já têm os
  mesmos valores das variáveis do Pencil.
- CSP sem `unsafe-eval` (`docs/superpowers/specs/2026-11-12-csp-sem-unsafe-eval-design.md`): nada de Alpine com expressão
  nova que dependa de eval; seguir o padrão atual.
- Testes de Blade/Livewire existentes que conferem `data-*`, textos e estrutura continuam passando; quando o markup muda,
  o teste muda junto, sem perder a asserção de comportamento.
- Cores das etiquetas (`.tag-*`, enum `TagColor`) são dado do usuário: ficam coloridas.
- Conferência visual em 720p, 1080p, 2K e celular, claro e escuro (agente `conferente-visual`).

## Abordagens

1. **Componentes Blade da base + telas que os usam (escolhida pelo dono).** Os componentes que o Pencil já tem viram
   componentes Blade sobre o Flux free; cada fatia troca o markup das telas por eles. Uma mudança, um lugar; o escuro sai
   dos tokens.
2. Classes Tailwind direto em cada tela, sem componentes novos. Mais rápido no começo, mas o mesmo cartão e o mesmo
   cabeçalho copiados em ~15 telas, e o escuro acertado tela a tela. Descartada.
3. Flux Pro (tabela, gráfico, seletor de data prontos). Dependência paga, e o design foi conferido com o free. Descartada.

## Mapa Pencil → app

| Componente do Pencil | No app |
|---|---|
| Sidebar, Nav Item, Nav Item Active, Nav Group Label, Sino | `layouts/app/sidebar.blade.php` (Flux sidebar), itens de 40 px |
| Page Header (+ Tab) | `x-page-header` (existe; ganha o arranjo do design: título 28 px, subtítulo, ações à direita) |
| Settings Nav Item / Active | nav de `x-settings-page` |
| Button Primário/Secundário/Ghost/Destrutivo | `flux:button` primary / outline / ghost / danger (destrutivo no tom suave do design onde a ação não é a principal da tela) |
| Badge Neutro/Conectado/Perto de estourar/SLA estourado/Bot | `x-status-badge` / `flux:badge` |
| Banner Atenção/Erro/Info, Faixas crítica/grave/confirmação/ativar som | `flux:callout` e as faixas de alerta existentes |
| Empty State | `x-empty-state` (existe) |
| Card | `x-card` (novo: cabeçalho opcional com título e ação, conteúdo) |
| Field, Toggle Row | `flux:field` / `flux:switch` com texto (padronizar o arranjo) |
| Skeleton Row | `x-skeleton-rows` (existe) |
| Stat | `x-stat` (novo, cartões dos Relatórios) |
| Table Header Row, Table Row, Table Footer | `x-table` (novo: cabeçalho, linhas, rodapé com informação e paginação quando a tela já pagina) |
| Cartão com seta (atendimentos anteriores) | `x-link-card` (novo: título, linha secundária, chevron; link para o ticket) |

Nomes dos componentes novos são sugestão; o plano pode ajustar, sem duplicar o que já existe em `resources/views/components`.

**Padrão dos componentes próprios (pedido do dono):** onde o Flux free não tem o componente (tabela, card, stat, cartão com
seta), ele é feito do zero imitando o Flux Pro, como já foi feito com `x-popover`, `x-bar-chart`, `x-tag-picker` e
`x-confirm-dialog`: visual e API inspirados no componente equivalente do Flux Pro (consultar a doc do Flux pelo Context7,
`/websites/fluxui_dev`), código próprio, comentário de cabeçalho dizendo isso, e o estado interativo, quando houver, em
`Alpine.data` num arquivo de `resources/js/` (o build CSP não aceita expressão no atributo). Nenhum código do Flux Pro é
copiado.

## Limpeza e organização do Pencil (pedido do dono)

O arquivo `Dimetal.pen` tem 36 frames no topo, espalhados sem ordem, com componentes em quatro lugares e textos defasados.
Antes da fatia 1:

- **Organizar o canvas:** uma faixa de componentes no topo e as telas abaixo, agrupadas por área na ordem das fatias
  (Inbox e variantes; Histórico e Contatos; Relatórios e Auditoria; Configurações e Perfil; Login e celular; Automação),
  cada tela em claro com a versão escura ao lado.
- **Consolidar os componentes:** `Componentes` (itens do menu e Sidebar), `Componentes - base`, `Componentes - alertas`,
  `Estados do sistema` e os dois `Settings Nav Item` soltos num quadro só de componentes, sem duplicata.
- **Atualizar o `Design System`:** os textos e códigos de cor estão defasados (fala em "verde de sinal" e mostra hex que não
  são os das variáveis); passa a mostrar as variáveis reais, claro e escuro.
- **Eliminar o desnecessário:** notas superadas (`Notas - correções futuras do frontend`, que fala de "o dono refaz o
  layout depois") e o `Guia - Pencil para o app` (reescrito curto com o mapa desta spec, ou apagado se a spec bastar),
  frames de função fora do app depois de ajustados, nós ocultos ou vazios e variáveis sem uso.
- Antes de apagar qualquer frame, a lista do que sai vai ao dono numa pergunta; o resto da organização é minha.

## Tema escuro

As variáveis do Pencil ganham o modo escuro com os valores do bloco `.dark` de `resources/css/app.css` (zinc-900 de fundo,
zinc-800 de superfície, ink zinc-50, line zinc-700, warn/danger/ok/bot/read nas versões 300–400). Cada tela desenhada é
conferida nos dois modos no Pencil antes do código, e no navegador depois.

## Fatias

Cada fatia: Pencil atualizado → plano → código com TDD onde houver comportamento → revisão → conferência visual → PR
(só quando o dono pedir). A aprovação de cada tela é minha, depois de conferir no Pencil e no navegador (delegado pelo dono
em 17/11/2026).

0. **Bugs** (depuração sistemática antes de corrigir, teste com RED):
   - Submenu das Configurações se mexe ao navegar entre itens. Hipótese a confirmar: o contêiner `mx-auto max-w-7xl` de
     `x-settings-page` se recentraliza quando a barra de rolagem aparece ou some conforme a altura da página.
   - Bolhas da Inbox desalinhadas, com espaço vazio à direita. Reproduzir e achar a causa no layout das bolhas.
1. **Base**: variáveis do Pencil com tema escuro; componentes da tabela acima; avatares monocromáticos (`x-avatar` sem
   `color="auto"`); sidebar, cabeçalho de página e larguras de página como no design; tela "Estados do sistema" aplicada aos
   componentes de estado.
2. **Inbox**: fila (grupos, linha do ticket com prévia, selo de status e protocolo quando o app já tem o dado), conversa
   (cabeçalho com ações à direita, compositor), painel de detalhes no arranjo do design (Ticket, Cliente, Atendimentos
   anteriores em `x-link-card`, Etiquetas, Observações, ação Encerrar no rodapé), faixas e sino, variantes (canal
   desconectado, fila vazia, painel recolhido). O Encerrar segue inline com os campos de hoje, com o visual do design.
3. **Histórico + Contatos**: lista do Histórico e ticket encerrado; Contatos em tabela larga com as colunas que o app já
   tem; a gaveta da ficha com o visual do design.
4. **Relatórios + Auditoria**: Stat, gráfico de barras existente com o visual do design, tabelas largas, estados vazios.
5. **Configurações + Perfil**: todas as abas, incluindo as que o design não tem (Backup, Sistema, Etiquetas, Aparência,
   assinatura da Empresa), desenhadas antes no Pencil com os componentes da base.
6. **Login + celular**: login no visual do design (sem "Esqueci a senha"); fila e detalhes em 390 px com a base aplicada.
7. **Editor do bot** (por último): cabeçalho, cores dos blocos (monocromáticas com sinal por tipo), painéis laterais.

## Decidido

- Opção 1 (componentes Blade da base), e o que o Flux free não tem é feito do zero imitando o Flux Pro.
- Só visual; elementos sem função saem do desenho e do app.
- Telas só do app são desenhadas no Pencil com os mesmos componentes.
- Monocromático (sai cor de avatar e de destaque); etiquetas seguem coloridas.
- Largura toda nas telas de gestão.
- Escuro no Pencil pelos tokens do app.
- Fatia 0 de bugs antes do visual; editor do bot por último.

## Decisões minhas (da revisão do construtor)

- **Um plano por fatia**, escrito quando o Pencil daquela fatia estiver pronto. O primeiro plano cobre só a fatia 0; o
  seguinte, a fatia 1.
- **Limpeza do Pencil e desenho das telas são trabalho do controlador** (sessão principal, MCP do Pencil), fora do plano e
  sem TDD, com a pergunta ao dono antes de apagar frames. Os planos de código partem do Pencil já pronto.
- **Medidas no plano:** os implementadores não abrem o Pencil. Cada plano traz uma tabela com as medidas e os tokens
  extraídos do Pencil (tamanhos de texto, alturas, espaçamentos, raios, larguras, limite em 2K) e os PNGs exportados de
  cada tela como referência.
- **Fatia 0:** começa por depuração (`systematic-debugging`); o plano da correção só é escrito depois da causa confirmada.
  O RED é um teste de markup/CSS que falha antes (ex.: a regra que estabiliza a largura ausente) quando der; quando o bug
  só aparece no navegador, a prova de antes e depois é a conferência visual registrada no ledger.
- **Escuro:** o primário segue o token `accent`, que no escuro já é claro (`#ffffff` com texto zinc-800 em
  `resources/css/app.css`); o destrutivo suave usa `danger` a ~10% de fundo com texto `danger`, que no escuro também já tem
  par. Nenhum valor novo de cor; o contraste é medido como hoje (`ThemeTokensTest`).

## Para o construtor

- Limite de largura em 2K, espaçamentos e tamanhos exatos: seguir o Pencil, ajustando ao que o Tailwind oferece.
- Quais telas separadas dá para juntar sem mudar rota ou regra (ver não-metas).
- Divisão de cada fatia em tarefas e quais testes de markup precisam acompanhar.
