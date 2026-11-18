# Redesenho, fatia 0: submenu que pula e vão nas bolhas — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Corrigir os dois bugs visuais que o dono viu antes do redesenho: o submenu das Configurações que se mexe ao navegar e
o vão à direita dentro das bolhas da Inbox.

**Architecture:** Duas correções independentes, cada uma na causa confirmada (depuração de 17/11/2026). (1) Telas com layout
de página reservam o espaço da barra de rolagem da janela, para o bloco centralizado não se recentralizar quando a página
passa a rolar. (2) A bolha volta a ter folga igual dos dois lados; o botão do menu (chevron) aparece por cima do canto de
cima da bolha só com o mouse, como no WhatsApp Web, e a reserva de 36 px fica só nas telas de toque.

**Tech Stack:** Laravel 13, Livewire 4.4 (build CSP), Flux 2 free, Tailwind 4, Alpine (subconjunto CSP), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-17-redesenho-ui-pencil-design.md` (fatia 0).

## Causas confirmadas

- **Submenu:** `x-settings-page` (`resources/views/components/settings-page.blade.php`) centraliza com
  `mx-auto max-w-7xl`. Em `/configuracoes/horario` a página rola (`scrollHeight` 1741 > 911) e a barra da janela ocupa
  15 px; em `/configuracoes/setores` não rola. O nav vai de x=472 para x=465 a cada troca. `scrollbar-gutter` está `auto`.
- **Bolhas:** a leva 2 (U10, `f49c7c7`) pôs `ps-3 pe-9` em toda bolha com menu
  (`resources/views/livewire/inbox/transcript.blade.php:60`), 36 px fixos à direita para o chevron (`absolute end-1 top-1`,
  linha 145). A spec do U10 pedia só largura mínima nas bolhas curtas. Resultado no staging: foto e PDF com 12 px à
  esquerda e 36 px à direita; texto curto com vão depois do horário.

## Global Constraints

- Build CSP: nenhuma expressão nova em atributo Alpine; estado em `Alpine.data` (`resources/js/message-menu.js` já existe).
- Tokens de `resources/css/app.css`; nenhuma cor nova fora de `color-mix` sobre tokens existentes.
- Animação com `--duration-base` (200 ms) e `--ease-out`; com `prefers-reduced-motion: reduce`, só opacidade.
- Piso de 12 px de texto e alvo mínimo de 24 px (o botão segue `min-h-6 min-w-6`, conferido em `ViewConventionsTest`).
- A Inbox (`resources/views/layouts/inbox.blade.php`, altura fixa `h-dvh`, sem rolagem da janela) não ganha faixa vazia à
  direita.
- Referência visual do dono: print do WhatsApp Web em `C:\Projetos\chatbot\Captura de tela 2026-11-17 153055.png` (chevron
  dentro da bolha, canto de cima à direita, só com o mouse; reação ☺ fora, ao lado).

## Review Focus

- Bolha do bot (`bg-bot/15`, fundo translúcido): o fundo do botão precisa ter exatamente a cor que se vê na bolha, nos dois
  temas, senão aparece um retângulo de outra cor sobre o texto. Teste na Task 2 confere a variável única usada pela bolha e
  pelo botão.
- Telas de toque (`hover: none`): o chevron fica sempre visível; sem a reserva ele cobriria o horário. Teste na Task 2.
- Transcrição somente leitura (Histórico): sem menu, sem reserva e sem botão. Teste na Task 2.
- Focar o botão pelo teclado (Tab) mostra o chevron mesmo sem mouse (`focus-visible:opacity-100` continua). Teste na Task 2.
- Páginas que não usam `x-settings-page` mas têm layout de página (Contatos, Relatórios, Auditoria, Histórico) também
  centralizam: a correção vale para todo o layout de página, não só Configurações. Teste na Task 1 por uma página de cada
  layout.

---

### Task 1: Layout de página reserva a barra de rolagem

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (o `<flux:main>` ganha `data-page-layout`)
- Modify: `resources/css/app.css` (regra para o `html` que contém esse main)
- Test: `tests/Feature/Layout/SettingsPageTest.php` (ou um `tests/Feature/Layout/PageLayoutGutterTest.php` novo, se ficar
  mais claro), `tests/Unit/ThemeTokensTest.php` (já lê o `app.css`)

**Interfaces:**
- Produces: atributo `data-page-layout` no `<main>` do layout de página; regra
  `html:has(main[data-page-layout]) { scrollbar-gutter: stable; }` em `app.css`.

- [ ] **Step 1: Testes que falham**
  - `test_layout_de_pagina_marca_o_main`: GET `/configuracoes/setores` e `/contatos` como admin; o HTML tem
    `<main` com `data-page-layout`.
  - `test_inbox_nao_marca_o_main`: GET `/inbox`; o HTML não contém `data-page-layout`.
  - Em `ThemeTokensTest`: `test_layout_de_pagina_reserva_a_barra_de_rolagem`: o `app.css` contém
    `html:has(main[data-page-layout])` com `scrollbar-gutter: stable`.

- [ ] **Step 2: Rodar e ver falhar**
  Run: `php artisan test --filter="layout_de_pagina|inbox_nao_marca"`
  Expected: FAIL (atributo e regra ausentes).

- [ ] **Step 3: Implementar**
  `data-page-layout` no `<flux:main role="main">` de `layouts/app.blade.php` (não no `layouts/inbox.blade.php`); a regra
  em `app.css` com um comentário de uma linha dizendo o porquê (barra da janela aparece/some e o bloco centralizado pulava).

- [ ] **Step 4: Rodar e ver passar**
  Run: `php artisan test --filter="layout_de_pagina|inbox_nao_marca|SettingsPageTest|ThemeTokensTest"`
  Expected: PASS.

- [ ] **Step 5: Conferência no navegador** (servidor da worktree, `127.0.0.1:8124`): navegar por
  `/configuracoes/setores` → `/configuracoes/horario` → `/configuracoes/canais` e medir a posição x do
  `nav[aria-label="Configuração"]`: a mesma nas três. Na `/inbox`, nenhuma faixa vazia à direita.

- [ ] **Step 6: Commit**
  `fix: layout de página reserva a barra de rolagem e o submenu das Configurações para de pular`

### Task 2: Bolha com folga igual e chevron por cima, como no WhatsApp Web

**Files:**
- Modify: `resources/views/livewire/inbox/transcript.blade.php:52-67` (classes da bolha, comentário da linha 52) e
  `:141-149` (contêiner e botão do menu)
- Modify: `resources/css/app.css` (só se a animação não couber em utilitários Tailwind)
- Test: `tests/Feature/Livewire/InboxTranscriptTest.php` (substituir `test_bolha_com_menu_reserva_espaco_para_o_botao`),
  `tests/Feature/Livewire/InboxComposerLayoutTest.php:133` (manter verde)

**Interfaces:**
- Consumes: nada da Task 1.
- Produces: variável CSS `--bubble-bg` em cada bolha, usada pelo fundo da bolha e pelo degradê do botão.

Comportamento:
- Bolha: `px-3` sempre (com ou sem menu). Com menu: `[@media(hover:none)]:pe-9` (reserva só no toque).
- Fundo da bolha por variável: cliente `--bubble-bg: var(--color-surface)`, nossa `var(--color-accent-soft)`, celular fora
  do painel `var(--color-surface)`, bot `color-mix(in oklab, var(--color-bot) 15%, var(--color-surface))`; a bolha usa
  `bg-(--bubble-bg)`. Conferir no navegador que a bolha do bot fica visualmente igual à de hoje nos dois temas.
- Botão do chevron: fica em `absolute end-1 top-1`, fundo em degradê da esquerda transparente para `var(--bubble-bg)`
  (cobre o texto embaixo, como no WhatsApp), sem `bg-surface/80`. Escondido (`opacity-0`, deslocado 4 px para a direita)
  e aparece com `group-hover` ou `focus-visible`: opacidade 1 e deslocamento 0, `--duration-base` e `--ease-out`. Com
  `motion-reduce`, sem deslocamento. Em `hover: none`, sempre visível, como hoje. Continua `min-h-6 min-w-6`.

- [ ] **Step 1: Testes que falham** (em `InboxTranscriptTest`, no lugar do teste antigo)
  - `test_bolha_tem_folga_igual_dos_dois_lados`: bolha com menu e bolha apagada (sem menu); nenhuma contém
    `ps-3 pe-9`; a com menu contém `[@media(hover:none)]:pe-9`; `substr_count($html, '[@media(hover:none)]:pe-9') === 1`.
  - `test_fundo_da_bolha_e_do_botao_vem_da_mesma_variavel`: bolha do bot (`origin` BOT) contém `--bubble-bg` com
    `color-mix(in oklab, var(--color-bot) 15%, var(--color-surface))` e `bg-(--bubble-bg)`; o botão
    `data-testid="message-menu-button"` contém `var(--bubble-bg)` e não contém `bg-surface/80`.
  - `test_chevron_aparece_no_hover_e_no_foco`: o botão contém `opacity-0`, `group-hover:opacity-100`,
    `focus-visible:opacity-100` e `[@media(hover:none)]:opacity-100`.
  - Somente leitura (`view('livewire.inbox.transcript', [..., 'readonly' => true])`): sem `message-menu-button` e sem
    `[@media(hover:none)]:pe-9`.

- [ ] **Step 2: Rodar e ver falhar**
  Run: `php artisan test --filter=InboxTranscriptTest`
  Expected: FAIL nos três testes novos.

- [ ] **Step 3: Implementar** as classes e a variável descritas acima; atualizar o comentário da linha 52 (a reserva agora
  é só no toque; no mouse o botão cobre o canto, como no WhatsApp Web).

- [ ] **Step 4: Rodar e ver passar**
  Run: `php artisan test --filter="InboxTranscriptTest|InboxComposerLayoutTest|ViewConventionsTest"`
  Expected: PASS.

- [ ] **Step 5: Conferência no navegador** (`127.0.0.1:8124`, cenários do seed com bolhas de tamanhos variados, foto e
  documento se houver; claro e escuro): folga igual dos dois lados em texto, foto e PDF; com o mouse, o chevron surge
  deslizando no canto de cima e o degradê não mostra emenda (inclusive na bolha do bot); emulando toque (`hover: none`)
  o chevron fica visível e não cobre o horário.

- [ ] **Step 6: Commit**
  `fix: bolha com folga igual e chevron por cima ao passar o mouse, como no WhatsApp Web`

### Task 3: Verificação da fatia

- [ ] Portão 7 do `CLAUDE.md` (Pint, PHPStan, PHPMD, `node --test tests/js`, `npm run build`, suíte com Redis), lendo o
  código de saída de cada um.
- [ ] Conferência visual pelo agente `conferente-visual` em 720p, 1080p, 2K e celular, claro e escuro, nas Configurações
  (posição do submenu) e na Inbox (bolhas e chevron).
