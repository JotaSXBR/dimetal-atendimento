# Sidebar recolhível Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A sidebar fecha para ícones no desktop, lembra a escolha e não perde o aviso de fila/SLA nem o aviso de WhatsApp fechada.

**Architecture:** Só Blade. `flux:sidebar` passa de `collapsible="mobile"` para `collapsible`; o Flux já guarda o estado (`persist`) e já esconde badge e `icon-trailing` fechado, então o aviso da Fila vai num ponto dentro do slot de ícone do item. Elementos próprios (aviso de WhatsApp, sino) usam a variante `in-data-flux-sidebar-collapsed-desktop:` do Flux.

**Tech Stack:** Laravel 13, Livewire 4, Flux 2.21 (grátis), Tailwind 4, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-10-sidebar-recolhivel-design.md`

## Global Constraints

- Flux grátis, sem dependência nova; `stashable` não existe no código.
- Só tokens de cor (`bg-accent` amarelo, `bg-warn`, `bg-danger`); vermelho só para SLA estourado.
- Não mudar itens, ordem, rotas, portões `can:*` (`Navigation`) nem `SidebarState`.
- `data-test="queue-badge"`, `data-test="sla-badge"` e `data-test="settings-entry"` continuam como estão (testes existentes).
- Alvo de toque dos ícones fechados >= 44px de área clicável; item com nome acessível fechado.
- Verificação visual em 1280x720, 1920x1080 e 2560x1440, temas claro e escuro, aberta e fechada, e 360px (gaveta como hoje).
- Buscas em `resources/views` por `grep` no Bash (CodeGraph não indexa Blade). Em Windows, scripts Python que gravam `.blade.php` devem ser seguidos de `vendor/bin/pint` nos `.php` tocados (final de linha).

## Review Focus

1. SLA estourado sem ninguém aguardando, fechada → ponto vermelho mesmo assim (teste na Tarefa 1).
2. Fila com aguardando e sem SLA → ponto amarelo; sem pendência → nenhum ponto (Tarefa 1).
3. Atendente (sem Configuração) e supervisor também recebem a sidebar recolhível (Tarefa 1).
4. Aviso de WhatsApp desconectado fechado: ícone com `aria-label` e `title`, texto escondido só fechado (Tarefa 2).
5. Estado salvo + recarga/`wire:navigate` sem pulo, e celular intacto (Tarefa 3, no navegador).

---

### Task 1: Sidebar recolhível e ponto da Fila

**Files:**
- Modify: `resources/views/layouts/app/sidebar.blade.php` (linha 7: `collapsible="mobile"` → `collapsible`; linha 11: tirar `class="lg:hidden"` do `flux:sidebar.collapse`; item da Fila com ponto)
- Test: `tests/Feature/Layout/SidebarTest.php`

**Interfaces:**
- Produces: dentro do item `inbox`, via `<x-slot:icon>`, um `<span data-test="queue-dot" class="hidden in-data-flux-sidebar-collapsed-desktop:block absolute -end-0.5 -top-0.5 size-2.5 rounded-full ring-2 ring-zinc-50 dark:ring-zinc-900 {bg-danger|bg-accent}">` com `<span class="sr-only">` descrevendo (`N com SLA estourado` ou `N aguardando`). O ícone do slot continua `flux:icon :icon="$item->icon"` dentro de `<div class="relative">`. Só para o item `inbox`; os demais mantêm `:icon`.

- [ ] **Step 1: Testes que falham** (`SidebarTest`, usando os helpers `page()` e `xpath()` que o arquivo já tem)
  - `test_sidebar_e_recolhivel_no_desktop_para_todos_os_papeis`: para atendente, supervisor e admin, o HTML contém `<ui-sidebar` com `collapsible="true"` (e não `collapsible="mobile"`).
  - `test_botao_de_colapso_aparece_no_desktop`: o elemento `flux:sidebar.collapse` renderizado não tem `lg:hidden` na classe (xpath em `//*[@data-flux-sidebar-collapse]` ou o botão do cabeçalho; conferir o atributo no HTML renderizado antes de fixar o xpath).
  - `test_ponto_da_fila_fechada_e_vermelho_com_sla_estourado_mesmo_sem_aguardando`: 1 ticket com SLA estourado e nenhum aguardando → `//*[@data-test="queue-dot"]` existe, classe contém `bg-danger`, texto sr-only `1 com SLA estourado`.
  - `test_ponto_da_fila_fechada_e_amarelo_com_aguardando`: 3 aguardando sem SLA estourado → classe contém `bg-accent`, sr-only `3 aguardando`.
  - `test_sem_pendencia_nao_ha_ponto_da_fila`: nenhum `queue-dot`.
  - `test_ponto_da_fila_so_aparece_com_a_sidebar_fechada`: a classe do `queue-dot` contém `hidden` e `in-data-flux-sidebar-collapsed-desktop:block`.
- [ ] **Step 2:** `php artisan test --parallel --filter=SidebarTest` → FAIL (collapsible="mobile" ainda; sem `queue-dot`).
- [ ] **Step 3:** Implementar conforme "Files"/"Interfaces". O estado do ponto vem de `$counts['breached']` (vermelho, texto `{n} com SLA estourado`) e, se zero, de `$counts['waiting']` (`bg-accent`, `{n} aguardando`); nenhum ponto se ambos zero.
- [ ] **Step 4:** `--filter=SidebarTest` e `--filter='Layout|Sla'` → PASS.
- [ ] **Step 5:** Commit `feat: sidebar recolhível no desktop com ponto da Fila fechada`.

### Task 2: Aviso de WhatsApp, cabeçalho e menu do usuário fechados

**Files:**
- Modify: `resources/views/layouts/app/sidebar.blade.php` (aviso de WhatsApp; cabeçalho com `x-app-logo`, sino e colapso), `resources/views/components/desktop-user-menu.blade.php` e `resources/views/components/app-logo.blade.php` só se o navegador (Tarefa 3) mostrar corte
- Test: `tests/Feature/Layout/SidebarTest.php`

**Interfaces:**
- Consumes: variante `in-data-flux-sidebar-collapsed-desktop:` do Flux.
- Produces: o aviso vira `<div role="status" data-test="whatsapp-down">` com dois filhos: o parágrafo de texto (`in-data-flux-sidebar-collapsed-desktop:hidden`) e um ícone `exclamation-triangle` (`hidden in-data-flux-sidebar-collapsed-desktop:flex`, `aria-label="WhatsApp desconectado"`, `title="WhatsApp desconectado. As mensagens ficam aguardando até a conexão voltar."`, classe `text-warn`).

- [ ] **Step 1: Testes que falham**
  - `test_aviso_de_whatsapp_tem_texto_e_icone_com_rotulo_para_a_sidebar_fechada`: com canal desconectado, `//*[@data-test="whatsapp-down"]` contém o texto atual do aviso e um elemento com `aria-label="WhatsApp desconectado"`; o texto tem `in-data-flux-sidebar-collapsed-desktop:hidden`.
  - O teste existente `test_aviso_quando_nenhum_canal_esta_conectado` e `test_sem_aviso_com_canal_conectado` seguem verdes.
- [ ] **Step 2:** `--filter=SidebarTest` → FAIL.
- [ ] **Step 3:** Implementar o aviso. Cabeçalho fechado: o Flux já esconde o nome da marca fechado e mostra o botão de expandir ao passar o mouse sobre o logo; **o sino (`livewire:alerts.bell`) precisa continuar acessível**: empilhar o cabeçalho fechado (`in-data-flux-sidebar-collapsed-desktop:flex-col`) se o navegador (Tarefa 3) mostrar o sino cortado ou sobreposto. Decidir pelo navegador, não por palpite.
- [ ] **Step 4:** `--filter='SidebarTest|Alerts'` → PASS.
- [ ] **Step 5:** Commit `feat: aviso de WhatsApp e cabeçalho da sidebar fechada`.

### Task 3: Verificação no navegador e ajustes de alinhamento

**Files:**
- Modify (se o navegador mostrar problema): `resources/views/layouts/app/sidebar.blade.php`, `resources/views/layouts/inbox.blade.php`, as views de Fila/Histórico/Contatos/Relatórios que usem largura fixa de coluna
- Test: um teste por ajuste de comportamento (TDD); ajuste só de classe CSS fica na verificação visual

- [ ] **Step 1:** Subir o app local (receita da memória: `php artisan serve --port=8765`, banco `dimetal_visual`, SESSION/CACHE=file, QUEUE=database; usuário e tickets por tinker) e abrir com o Claude in Chrome numa aba nova.
- [ ] **Step 2:** Em 1280x720, 1920x1080 e 2560x1440, tema claro e escuro, aberta e fechada: Fila (com um ticket SLA estourado e outro aguardando), Histórico, Contatos, Relatórios. Conferir: ponto da Fila, tooltips, sino, aviso de WhatsApp, menu do usuário só avatar, colunas sem corte nem desalinhamento.
- [ ] **Step 3:** Fechar, recarregar e navegar (`wire:navigate`) entre telas: o estado fica (`localStorage`) e não há pulo no primeiro desenho. Em 360px: gaveta como hoje.
- [ ] **Step 4:** Corrigir o que achar (teste primeiro quando for comportamento) e repetir os passos 2 e 3.
- [ ] **Step 5:** Commit `fix: ajustes da sidebar fechada nas três resoluções` (só se houve ajuste).

**Depois das tarefas:** portão 6 (revisor independente do branch), portão 7 (suíte com Redis em série, Pint, Larastan, PHPMD), portão 8 (PR, CI, merge, `deploy-beta`) e verificação final no beta com o Claude in Chrome nas três resoluções.
