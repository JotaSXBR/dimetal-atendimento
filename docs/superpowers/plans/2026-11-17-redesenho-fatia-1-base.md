# Redesenho, fatia 1: base — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preparar a base visual do redesenho: corrigir a foto que passa da bolha no celular, tirar as cores zinc cruas do
layout em favor dos tokens, deixar os avatares monocromáticos e trazer o cabeçalho de página e o estado vazio para o
desenho do Pencil.

**Architecture:** Mudanças em componentes e layouts compartilhados (`resources/views/components`, `resources/views/layouts`,
`resources/css/app.css`), sem tocar no conteúdo de cada tela (isso é das fatias 2 a 7). Tudo visual; nenhum dado novo.

**Tech Stack:** Laravel 13, Livewire 4.4 (build CSP), Flux 2.21 free, Tailwind 4, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-17-redesenho-ui-pencil-design.md` (fatia 1).

## Global Constraints

- Só visual: nenhuma função, rota, propriedade Livewire ou regra nova.
- Antes de criar componente, conferir o Flux free 2.21 (`vendor/livewire/flux/stubs/resources/views/flux/`): `card` e
  `table` já existem no free e não se recriam.
- Tokens de `resources/css/app.css`; nenhuma cor crua (`zinc-*`, `white`) nova em view. Token novo só nasce junto com o
  primeiro uso (`ThemeTokensTest::test_todo_token_tem_algum_uso`).
- Piso de 12 px de texto (`text-xs`); alvo mínimo de 24 px.
- Testes conferem estrutura, slots e atributos `data-*`, não listas de classes, exceto quando a classe É o requisito
  (ex.: o token usado).
- Testes com `DB_DATABASE=fluxie_ui_test`. Implementadores rodam só os testes focados e o Pint nos arquivos tocados; a
  suíte completa é do controlador.

## Medidas do Pencil (fonte: `Dimetal.pen`, componentes em "Componentes")

| Componente | Medidas |
|---|---|
| Page Header | faixa com borda de baixo `line`; título 24 px peso 500 `ink` (`flux:heading size="xl"`); subtítulo 14 px `ink-2`, 4 px abaixo; ações à direita com 8 px entre elas; 14 px entre a linha do título e a borda |
| Empty State | coluna centralizada, 10 px entre itens, 32 px de folga; círculo de 44 px `canvas` com ícone de 20 px `ink-3`; título 13 px peso 600 `ink` (usar `text-sm font-semibold`); texto 12 px `ink-3` |
| Avatar (sem foto) | fundo `accent-soft`, iniciais `ink-2` (claro: #E4E4E7 / #52525B; escuro: #3F3F46 / #D4D4D8) |
| Fundos reais | página (`body`): `surface` (claro #FFF, escuro #27272A, hoje `bg-white dark:bg-zinc-800`); barra lateral: `canvas` (claro #FAFAFA, escuro #18181B, hoje `zinc-50`/`zinc-900`), borda `line` (hoje `zinc-200`/`zinc-700`); conversa e transcrição do Histórico: `canvas` |

Imagens de referência (exportadas do Pencil): `C:/Users/bkpdi/AppData/Local/Temp/claude/abad7b6d92ced975/77f07cf4-6a0c-4f97-94b4-13cbea4ab0cd/scratchpad/pen1/` (o controlador exporta antes de despachar).

## Review Focus

- Avatar com foto que falha ao carregar: o monograma de baixo aparece monocromático, sem cor (Task 3).
- Avatar `accent` (marca) continua com o fundo da marca, não vira cinza (Task 3).
- Estado vazio `compact` (usado dentro de painéis estreitos) continua compacto: sem o círculo de 44 px empurrando a altura
  (Task 4).
- Cabeçalho de página dentro de `x-settings-page` e nas telas com ações (botão à direita): as ações seguem alinhadas ao
  título e quebram para baixo em 360 px sem estouro (Task 4).
- Foto vertical alta e foto pequena (menor que a bolha) no celular: a alta não passa da bolha, a pequena não estica
  (Task 1).

---

### Task 1: Foto não passa da bolha no celular

Causa confirmada (DevTools, 390 px, toque): em `resources/views/livewire/inbox/media.blade.php` a imagem fica num botão
`block w-72 max-w-full` dentro de um contêiner `relative w-fit`. O `w-fit` encolhe até o conteúdo, então o `max-w-full`
da imagem não tem largura de referência e ela fica com 288 px numa bolha de ~270 px (a de saída chega a sair da tela:
direita em 406 px numa tela de 390).

**Files:**
- Modify: `resources/views/livewire/inbox/media.blade.php` (o contêiner `relative w-fit` da imagem/vídeo)
- Test: `tests/Feature/Livewire/InboxTranscriptTest.php` (ou o teste de mídia que já renderiza a foto, se houver um mais próximo)

- [ ] **Step 1: Teste que falha** `test_contener_da_foto_nao_passa_da_bolha`: renderiza uma mensagem `type=media`,
  `media_mime=image/png`; o contêiner da foto (o pai do botão `cursor-zoom-in`) tem `max-w-full` além de `w-fit`.
- [ ] **Step 2: Rodar e ver falhar** — `DB_DATABASE=fluxie_ui_test php artisan test --filter=test_contener_da_foto_nao_passa_da_bolha`
- [ ] **Step 3: Implementar** — `max-w-full` no contêiner (e o mesmo onde vídeo usar o mesmo contêiner).
- [ ] **Step 4: Rodar e ver passar** — o filtro acima e `--filter="InboxTranscriptTest|BubbleColorsTest"`.
- [ ] **Step 5: Commit** — `fix: foto não passa da bolha no celular`

### Task 2: Layout sem zinc cru

**Files:**
- Modify: `resources/views/layouts/app/sidebar.blade.php` (linhas 6 e 8: `body` e `flux:sidebar`)
- Modify: os outros layouts que repetem `bg-white dark:bg-zinc-800` no `body` (`layouts/auth/*.blade.php`,
  `layouts/setup.blade.php`), se repetirem
- Test: `tests/Unit/ThemeTokensTest.php`

- [ ] **Step 1: Teste que falha** `test_layouts_nao_usam_zinc_nem_branco_cru`: lê os arquivos de `resources/views/layouts/`
  e falha se algum contiver `bg-white`, `dark:bg-zinc-`, `border-zinc-` ou `var(--color-zinc-`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** — `body`: `bg-surface`; sidebar: `[--sidebar-bg:var(--color-canvas)]` sem a variante
  `dark:` (o token já muda no escuro) e `border-line`. Os valores resultantes são os mesmos de hoje (ver tabela).
- [ ] **Step 4: Rodar e ver passar** — `--filter=ThemeTokensTest`.
- [ ] **Step 5: Commit** — `refactor: layouts usam os tokens de fundo e borda`

### Task 3: Avatar monocromático

**Files:**
- Modify: `resources/views/components/avatar.blade.php:31` (`:color="$accent ? null : 'auto'"`)
- Test: `tests/Feature/Components/AvatarComponentTest.php` (`test_accent_usa_o_amarelo_da_marca_e_o_padrao_usa_cor_automatica`
  passa a provar o contrário: padrão monocromático)

- [ ] **Step 1: Teste que falha** — renomear e reescrever o teste: o avatar padrão (sem `accent`) não passa `color` ao
  `flux:avatar` (o HTML não tem `data-color` nem as classes de cor automáticas do Flux — conferir no stub
  `vendor/livewire/flux/stubs/resources/views/flux/avatar/index.blade.php` qual atributo/classe marca a cor) e tem
  `bg-accent-soft` e `text-ink-2`; o `accent` segue com `bg-accent text-accent-foreground`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** — sem `color`; `class` do `flux:avatar`: `bg-accent-soft text-ink-2` no padrão e
  `bg-accent text-accent-foreground` no `accent`.
- [ ] **Step 4: Rodar e ver passar** — `--filter="AvatarComponentTest|AvatarUploaderTest"`.
- [ ] **Step 5: Commit** — `feat: avatares monocromáticos como no desenho`

### Task 4: Cabeçalho de página e estado vazio no desenho do Pencil

**Files:**
- Modify: `resources/views/components/page-header.blade.php`
- Modify: `resources/views/components/empty-state.blade.php`
- Test: `tests/Feature/Components/EmptyStateTest.php`, `tests/Feature/Components/EmptyStateScreensTest.php`, e um teste de
  componente para o cabeçalho (criar `tests/Feature/Components/PageHeaderTest.php` se não houver)

Cabeçalho: mantém a API (`title`, `subtitle`, slot de ações); ganha `data-page-header`, a borda de baixo `border-line`
com 14 px (`pb-3.5`) entre o conteúdo e a borda e um espaço abaixo para o conteúdo da página (`mb-6`); título
`flux:heading size="xl" level="1"`, subtítulo `flux:subheading` (14 px `ink-2`) com 4 px (`mt-1`); ações num
`flex items-center gap-2`, quebrando para baixo em tela estreita.

Estado vazio: mantém a API (`icon`, `title`, `description`, `compact`, `action`); fora do `compact`, o ícone vai num círculo
`size-11 rounded-full bg-canvas` com o ícone `size-5 text-ink-3`, `gap-2.5`, `p-8`; título `text-sm font-semibold
text-ink`; descrição `text-xs text-ink-3` (hoje `text-ink-2`; conferir no `ThemeTokensTest` se `ink-3` sobre `surface`
passa no contraste de texto — se não passar, manter `ink-2` e registrar). No `compact`, sem círculo (ícone como hoje).

- [ ] **Step 1: Testes que falham**
  - `PageHeaderTest::test_cabecalho_tem_titulo_subtitulo_e_acoes_marcados`: renderiza `<x-page-header title="T"
    subtitle="S"><button>Ação</button></x-page-header>`; tem `data-page-header`, um `h1` com "T", o subtítulo "S" e o botão
    dentro de um elemento `data-page-header-actions`.
  - `EmptyStateTest::test_icone_fica_num_circulo_fora_do_compacto`: o padrão tem `data-empty-state-icon` com `rounded-full`;
    o `compact` não tem `data-empty-state-icon`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** os dois componentes como acima.
- [ ] **Step 4: Rodar e ver passar** — `--filter="PageHeaderTest|EmptyStateTest|EmptyStateScreensTest|SettingsPageTest"`.
- [ ] **Step 5: Commit** — `feat: cabeçalho de página e estado vazio no desenho do Pencil`

### Task 5: Verificação da fatia (controlador)

- [ ] Portão 7 do `CLAUDE.md`, lendo o código de saída de cada comando; suíte com Redis num contêiner próprio.
- [ ] Conferência visual (`conferente-visual`, chrome-devtools livre): 720p, 1080p, 2K reais e 390 px com toque, claro e
  escuro — foto no celular, avatares (fila, cabeçalho da conversa, menu do usuário, Contatos), cabeçalho de página em
  Relatórios, Contatos, Auditoria e Configurações, estados vazios (Auditoria, Respostas rápidas), barra lateral sem mudança
  de cor.
