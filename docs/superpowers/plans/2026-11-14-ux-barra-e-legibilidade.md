# UX: barra lateral e legibilidade (fatia A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, decisão do dono) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pôr a Automação na barra lateral (admin e supervisor) e garantir texto de pelo menos 12 px e alvos de clique de pelo menos 24 px no app todo.

**Architecture:** A navegação é dirigida por dados (`App\Support\Navigation::items()`): mover a Automação é mudar a seção do item e a página deixa de usar o layout do hub de Configuração. O piso de tamanho é uma varredura nas views Blade com um teste-guarda estático (no molde do `ViewConventionsTest`) que impede a volta; os alvos pequenos conhecidos ganham `min-h-6` e o script de auditoria do projeto confere o resto no navegador.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2.21, Tailwind, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-14-ux-simplificacao-design.md` (itens 6 e 7, fatia A).

## Global Constraints

- Branch `feat/ux-barra-e-legibilidade`, **empilhado** sobre `feat/ux-fila-conversa` (PR #119). O PR desta fatia abre com **base `feat/ux-fila-conversa`** (o diff mostra só a A; o GitHub reaponta para a `main` quando a #119 for mesclada) e o texto diz "depende de #119".
- Sem dependência nova. Cor só por token. Texto: nenhum `text-[9px]`, `text-[10px]` nem `text-[11px]` nas views; mínimo `text-xs` (12 px). Alvo de clique: mínimo 24×24 px (WCAG 2.2, 2.5.8).
- Rótulos de seção em monoespaçada caixa-alta viram "texto normal em negrito, 12 px" (`text-xs font-semibold`, sem `font-mono`, `uppercase` nem `tracking-wider`).
- A rota e o portão de `automacao` não mudam (`can:supervisor`); só onde o item aparece.
- Mudou método ou propriedade pública de componente: `grep -rn` em `resources/views` antes. Comandos RED/GREEN só com os afetados.
- Verificação visual em 360 px, 1280×720, 1920×1080 e 2560×1440, claro e escuro, com dado realista (script de auditoria `docs/superpowers/audit/ux-audit.js`, receita do ledger da fatia B: servidor local, banco `dimetal_visual`).
- Commits em português com prefixo, terminando com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus

- **Papéis:** o atendente não vê a Automação na barra (e a rota segue barrando); o supervisor vê entre Relatórios e Auditoria; o admin vê os dois. (Task 1)
- **Item ativo:** em `/automacao` e em `/automacao/{id}` (editor) o item "Automação" fica ativo e "Configuração" não; nas telas de Configuração é o contrário. (Task 1)
- **Sem hub:** `/automacao` não mostra a navegação de Configuração, e o supervisor, que só tem Respostas rápidas, Etiquetas e Usuários em Configuração, continua abrindo Configuração pelo primeiro item visível. (Task 1)
- **Barra recolhida:** o item novo tem nome acessível sem o texto visível (a barra fechada só mostra ícone). (Task 1)
- **Layouts com 12 px:** hora do card da Fila e da bolha, chips, rótulos e linhas densas não quebram nem estouram em 360 px e em 1280 px. (Task 2)

---

### Task 1: Automação na barra lateral

**Files:**
- Modify: `app/Support/Navigation.php` (item `Automação`), `resources/views/livewire/automation/index.blade.php` (layout da página)
- Test: `tests/Feature/Layout/NavigationTest.php`, `tests/Feature/Layout/SidebarTest.php`, `tests/Feature/Livewire/AutomationIndexTest.php`

**Interfaces:**
- Produces: `Navigation::items()` com `Automação` fora de `SECTION_CONFIGURACAO`, na ordem `…Relatórios, Automação, Auditoria`, mesmo `gate` (`supervisor`), mesma rota e `match` (`automacao*`). `Navigation::primary($user)` passa a listá-la; `settingsGroups` deixa de tê-la.

- [ ] **Step 1: Testes (RED).** `NavigationTest`: `primary` do supervisor é `['Fila','Contatos','Histórico','Relatórios','Automação']` e o do admin `[…,'Relatórios','Automação','Auditoria']`; `settingsGroups` do supervisor é `['Atendimento' => ['Respostas rápidas','Etiquetas'], 'Equipe' => ['Usuários']]` e o do admin perde "Automação" do grupo Atendimento. `SidebarTest`: o item "Automação" aparece para supervisor e admin e não para atendente; em `automacao` e `automacao.editar` ele é o corrente e `data-test="settings-entry"` não; em uma tela de Configuração, o contrário. `AutomationIndexTest`: a página não tem `nav[aria-label="Configuração"]` e mostra o botão "Novo workflow".
- [ ] **Step 2:** rodar os três arquivos; esperado FAIL.
- [ ] **Step 3: Implementar.** Em `Navigation::items()` mover a linha da Automação para depois de `Relatórios`, com seção `$work` e grupo `''`. Em `automation/index.blade.php` trocar `<x-settings-page …>` por `<section class="mx-auto w-full max-w-5xl space-y-6 p-6">` com `<x-page-header title="Automação" subtitle="…o mesmo texto…">` (o botão "Novo workflow" no slot) e fechar com `</section>`.
- [ ] **Step 4:** testes do Step 1 e `tests/Feature/Layout`, `AutomationEditorTest`, `AccessibleNamesTest`, `SettingsPageTest` verdes. Commit `feat: Automacao na barra lateral para admin e supervisor`.

### Task 2: piso de 12 px no texto

**Files:**
- Modify: todas as views com `text-[9px]`, `text-[10px]` ou `text-[11px]` (hoje 14 arquivos: `livewire/automation/editor`, `livewire/inbox/inbox`, `pages/settings/⚡alerts`, `livewire/inbox/transcript`, `livewire/alerts/bell`, `livewire/inbox/audio-player`, `livewire/history/index`, `livewire/contacts/contact-card`, `livewire/inbox/note`, `livewire/inbox/message-time`, `components/tag-chip`, `components/settings-page`, `components/disclosure`, `components/contact-chips`)
- Test: `tests/Unit/ViewConventionsTest.php`

**Interfaces:**
- Produces: `ViewConventionsTest::test_nenhum_texto_abaixo_de_12px` (varre `resources/views` com `$this->views()`).

- [ ] **Step 1: Teste-guarda (RED).** `test_nenhum_texto_abaixo_de_12px`: para cada view, `assertDoesNotMatchRegularExpression('/text-\[(?:[0-9]|1[01])px\]/', $html, "{$path}: texto abaixo de 12 px (use text-xs)")`.
- [ ] **Step 2:** rodar; FAIL listando os arquivos.
- [ ] **Step 3: Varredura.** Trocar `text-[10px]`/`text-[11px]` por `text-xs` em todas as ocorrências; nos rótulos de seção (`font-mono text-[10px] font-semibold tracking-wider … uppercase`) deixar `text-xs font-semibold` + a cor que já têm. Ajustar o que a conferência visual mostrar apertado (largura de coluna, `truncate`).
- [ ] **Step 4:** guarda verde; rodar os afetados (`BubbleColorsTest`, `InboxQueueCardTest`, `InboxChannelBadgeTest`, `AudioPlayerLayoutTest`, `TagChipTest`, `TicketDetailsBlocksTest`, `AlertsBell*`) e atualizar só o que afirmava a classe antiga, sem mudar o que provam. Commit `feat: texto com no minimo 12 px nas views`.

### Task 3: alvos de clique de pelo menos 24 px

**Files:**
- Modify: `resources/views/livewire/inbox/transcript.blade.php` (botão "Ações da mensagem"), `resources/views/livewire/contacts/contact-card.blade.php` (protocolos dos Anteriores), `resources/views/components/history-link.blade.php` ("Ver todos no Histórico"), e o que o script de auditoria ainda listar em `pequenos`
- Test: `tests/Unit/ViewConventionsTest.php`

- [ ] **Step 1: Teste (RED).** `test_alvos_de_clique_conhecidos_tem_ao_menos_24px`: cada um dos três arquivos contém `min-h-6` nos elementos clicáveis citados (asserção por trecho: o `data-testid="message-menu-button"` com `min-h-6 min-w-6`; o link/botão do protocolo e o `history-link` com `min-h-6 inline-flex items-center`).
- [ ] **Step 2:** FAIL. **Step 3:** aplicar as classes (alvo visual igual, só a área de clique cresce). **Step 4:** verde; rodar o script de auditoria no navegador (Task 4) e tratar o que sobrar em `pequenos`. Commit `feat: alvos de clique com no minimo 24 px`.

### Task 4: verificação, revisão e PR

- [ ] **Step 1:** servidor local e dados (receita da fatia B), `npm run build`; script `ux-audit.js` em `inbox`, `inbox?ticket=4`, `contatos`, `historico`, `relatorios`, `automacao`, `automacao/1`, `configuracoes/setores`: esperado `[]` (sem estouro, sem controle sem nome, sem alvo pequeno); olhar a Fila e a conversa em 360 e 1280.
- [ ] **Step 2:** portões 6 e 7 (revisão independente do branch; Pint, Larastan, PHPMD, `node --test`, `npm run build`, suíte com Redis), ledger, PR com base `feat/ux-fila-conversa`.

## Fora desta fatia

Confirmações e Desfazer (D), Histórico e Contatos (C), editor do bot (E), gráfico (F), Ctrl+K (G), faixas dispensáveis (H), sino (no fim).
