# UX: fundação visual e menu com Configuração em hub — Plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, regra do `CLAUDE.md`) para implementar este plano tarefa a tarefa. Steps usam checkbox (`- [ ]`).

**Goal:** Trocar a identidade para amarelo com tema escuro funcional e opcional, e enxugar o menu lateral com Configuração como um hub de uma só entrada.

**Architecture:** Os tokens semânticos do `app.css` (nomes atuais, valores redefinidos em `.dark`) fazem as classes existentes (`text-ink`, `bg-canvas`...) se adaptarem sozinhas. `Navigation` passa a expor `primary`, `settingsGroups`, `settingsEntry` e `settingsActive`; a sidebar e o `x-settings-page` leem deles. O tema usa `@fluxAppearance` e `$flux.appearance` do Flux, guardado no navegador.

**Tech Stack:** Laravel 13, Livewire 4, Flux (Blade), Tailwind 4, PHPUnit (Postgres 127.0.0.1:5432).

**Spec:** `docs/superpowers/specs/2026-11-06-ux-fundacao-e-menu-hub-design.md`

## Global Constraints

- Só o beta; nunca commitar na `main`; sem PR sem pedido. Um commit por tarefa, em português, prefixo `feat:`/`fix:`/`test:`/`docs:`.
- O portão `can:*` das rotas (`routes/web.php`) não muda; `Navigation` só decide o que aparece.
- Marca: `--color-accent` = `yellow-400`, `--color-accent-foreground` = `yellow-950`. Verde só como `ok` (status de sucesso).
- Texto com contraste mínimo 4,5:1 (WCAG AA) nos dois temas; o vermelho (`danger`) só para SLA estourado, erro e ação destrutiva.
- Movimento 150 a 250 ms (`--duration-base: 200ms`) e `prefers-reduced-motion: reduce` zera transições.
- Padrão de tema: Sistema; sem migration. Sem `x-status-badge`, botão unificado, skeleton ou mudança no Inbox (fatias futuras).
- Testes precisam do Postgres (`docker run -d --name pg-test -e POSTGRES_USER=dimetal -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=dimetal_test -p 5432:5432 postgres:18-alpine`). Ciclos: `php artisan test --parallel --filter=<nome>`.
- `grep` pelo Bash em `resources/views` (o CodeGraph não indexa Blade); avisar o dono em uma linha ao usar.

## Desvios do spec (a dizer ao dono na mensagem final)

- O spec dizia que o supervisor tem o link "Configuração" apontando para Usuários. Pela ordem de `Navigation::items()`, o primeiro item visível dele é **Respostas rápidas**; o plano usa essa regra (primeiro item visível) e o teste a fixa.
- "Navegação oculta com 1 só item de Configuração" **não entra**: nenhum papel chega a 1 item (supervisor tem 4, admin 10). YAGNI.

## Review Focus

- Atendente sem item de Configuração: sem entrada no menu, sem coluna de navegação, e a URL direta continua 403 (testar `get('/configuracoes/setores')`).
- SLA estourado com 0 aguardando (ticket em atendimento com `waiting_since` antigo): o badge vermelho aparece mesmo assim.
- Rotas fora do `x-settings-page` que ainda são "Configuração": `automacao.editar` e `configuracoes.google.callback` deixam o item Configuração ativo.
- Texto ilegível no escuro: o contraste dos pares de token é medido em teste (T1), não à mão.
- `prefers-reduced-motion` presente no CSS (contrato testado) e o script de aparência dentro do `<head>`, antes do primeiro paint.

---

### Task 1: Tokens, identidade amarela, escuro e movimento

**Files:**
- Modify: `resources/css/app.css` (bloco `@theme`, novo bloco `.dark`, movimento, remover o bloco comentado do starter kit)
- Modify: `resources/views/partials/head.blade.php` (adicionar `@fluxAppearance` antes de `@vite`)
- Test: `tests/Unit/ThemeTokensTest.php`, `tests/Feature/Layout/AppearanceTest.php`

**Interfaces:**
- Produces: tokens `--color-ink`, `-ink-2`, `-ink-3`, `-line`, `-canvas`, `-surface`, `-accent`, `-accent-content`, `-accent-foreground`, `-accent-soft`, `-accent-deep`, `-warn`, `-danger`, `-ok`, `-bot`, todos em **hex** (comentário com o degrau do Tailwind), cada um redefinido em `.dark`; `--duration-base`, `--ease-out`. `window.Flux.applyAppearance` no `<head>`.

- [ ] **Step 1: testes que falham.** `ThemeTokensTest` lê `resources/css/app.css` e: (a) `test_cada_token_semantico_tem_valor_no_claro_e_no_escuro` (os 15 tokens acima existem em `@theme` e em `.dark`, em hex); (b) `test_a_marca_e_amarela_e_o_verde_antigo_sumiu` (`--color-accent` é `#facc15`, `#12705a` não aparece); (c) `test_contraste_minimo_de_texto_nos_dois_temas`: com uma função `ratio(hex, hex)` (luminância WCAG), exige ≥ 4,5 para `ink|ink-2|ink-3` sobre `canvas` e `surface`, `accent-content`, `warn`, `danger`, `ok` sobre `surface` e `canvas`, e `accent-foreground` sobre `accent`, em cada tema; (d) `test_movimento_reduzido_zera_transicoes` (o CSS contém `@media (prefers-reduced-motion: reduce)` e `--duration-base: 200ms`). `AppearanceTest::test_o_head_aplica_o_tema_antes_do_primeiro_paint` pede `/inbox` e afirma que `window.Flux.applyAppearance` aparece **antes** do `<link`/`<script` do Vite no HTML.
- [ ] **Step 2:** `php artisan test --filter=ThemeTokensTest` e `--filter=AppearanceTest`. Esperado: FAIL (tokens em verde, sem bloco `.dark`, sem `@fluxAppearance`).
- [ ] **Step 3: implementar** os tokens em hex e o bloco `.dark` (o `.dark` sobrescreve os mesmos nomes dentro de `@layer theme`, como o bloco comentado do starter kit); `warn` laranja-queimado (`orange-700` claro, `orange-400` escuro), `ok` verde, `accent-content` mais escuro que `yellow-600` no claro (comece em `#a16207` e escureça até o teste (c) passar), `accent-soft`/`accent-deep` em tons de amarelo. Manter o remapeamento `zinc → stone` e a regra do título do menu (`[data-flux-sidebar-group] > div.px-3`). Adicionar `--duration-base`, `--ease-out` e o bloco `prefers-reduced-motion`. Apagar o bloco comentado do starter kit. Inserir `@fluxAppearance` em `head.blade.php` antes de `@vite`.
- [ ] **Step 4:** os dois filtros passam; `npm run build` sem erro.
- [ ] **Step 5: Commit** — `feat: identidade amarela, tokens claro/escuro e aparência do Flux no head`

### Task 2: Seletor de tema nos menus do usuário

**Files:**
- Create: `resources/views/components/appearance-switcher.blade.php`
- Modify: `resources/views/components/desktop-user-menu.blade.php`, `resources/views/layouts/app/sidebar.blade.php` (menu mobile)
- Test: `tests/Feature/Layout/AppearanceTest.php`

**Interfaces:**
- Produces: `<x-appearance-switcher />`, um `flux:menu.radio.group` com `x-data x-model="$flux.appearance"` e três `flux:menu.radio` (`light`/`dark`/`system`, rótulos Claro/Escuro/Sistema, ícones `sun`/`moon`/`computer-desktop`), com `data-test="appearance-switcher"`.

- [ ] **Step 1: teste que falha.** `test_o_seletor_de_tema_esta_nos_dois_menus_do_usuario`: `/inbox` contém `data-test="appearance-switcher"` duas vezes e os valores `light`, `dark`, `system` com os rótulos Claro, Escuro, Sistema.
- [ ] **Step 2:** `php artisan test --filter=AppearanceTest`; esperado FAIL.
- [ ] **Step 3: implementar** o componente e incluí-lo nos dois dropdowns, entre "Minha conta" e "Sair", separado por `flux:menu.separator`. Conferir as props do radio em `vendor/livewire/flux/stubs/resources/views/flux/menu/radio/`.
- [ ] **Step 4:** passa. Conferir no navegador que escolher "Escuro" alterna a classe `dark` no `<html>` e sobrevive a um reload (confirma a hipótese do `@fluxAppearance`).
- [ ] **Step 5: Commit** — `feat: seletor de tema no menu do usuário`

### Task 3: API de navegação (`Navigation`)

**Files:**
- Modify: `app/Support/Navigation.php`
- Test: `tests/Feature/Layout/NavigationTest.php`

**Interfaces:**
- Produces (instância, todas filtram por `$user->can($item->gate)` como `tree()`):
  `primary(User $user): array` (`list<NavItem>`, itens fora de `SECTION_CONFIGURACAO`, na ordem de `items()`);
  `settingsGroups(User $user): array` (`array<string, list<NavItem>>`, grupo → itens);
  `settingsEntry(User $user): ?NavItem` (primeiro item de Configuração visível);
  `settingsActive(User $user): bool` (algum item de Configuração visível casa com `request()->routeIs($item->match)`).
- `tree()` e `siblings()` continuam até a T5.

- [ ] **Step 1: testes que falham** em `NavigationTest`: `test_primary_por_papel` (atendente: Fila, Contatos, Histórico; supervisor: + Relatórios; admin: + Relatórios, Auditoria); `test_settings_groups_por_papel` (supervisor: `Atendimento => [Respostas rápidas, Etiquetas, Automação]`, `Equipe => [Usuários]`; admin: os 4 grupos da lista atual; atendente: `[]`); `test_settings_entry_e_o_primeiro_visivel` (admin → `configuracoes.setores`; supervisor → `configuracoes.respostas`; atendente → `null`); `test_settings_active_cobre_automacao_editar_e_google_callback` (`$this->get(...)` não serve; usar `Request::create` + `app()->instance('request', ...)` com `Route::getRoutes()->match`, ou `actingAs` + GET e inspecionar a view; escolha a mais simples que prove `automacao.editar` e `configuracoes.google.callback` ativos e `inbox` inativo).
- [ ] **Step 2:** `php artisan test --filter=NavigationTest`; esperado FAIL (métodos inexistentes).
- [ ] **Step 3: implementar** os quatro métodos reaproveitando `items()`; sem duplicar o filtro de gate (extrair um privado `visible(User): list<NavItem>` usado por `tree()` e pelos novos).
- [ ] **Step 4:** passa (os testes antigos de `tree`/`siblings` continuam verdes).
- [ ] **Step 5: Commit** — `feat: Navigation expõe itens primários, grupos e entrada de Configuração`

### Task 4: Sidebar enxuta

**Files:**
- Modify: `resources/views/layouts/app/sidebar.blade.php`
- Test: `tests/Feature/Layout/SidebarTest.php`, `tests/Feature/Livewire/CompanyPageTest.php:103-108`

**Interfaces:**
- Consumes: `Navigation::primary/settingsEntry/settingsActive` (T3), `SidebarState::counts` (inalterado).
- Produces: `data-test="queue-badge"` (amarelo, contagem de aguardando, só se > 0) e `data-test="sla-badge"` (vermelho, `aria-label="N com SLA estourado"`, só se > 0, **independente** de aguardando).

- [ ] **Step 1: reescrever os testes** (RED): em `SidebarTest`, trocar `test_atendente_so_ve_o_grupo_de_atendimento` e `test_supervisor_ve_acompanhamento_...` por: o atendente vê Fila/Contatos/Histórico e não vê "Configuração"; o supervisor vê Relatórios e um link "Configuração" para `route('configuracoes.respostas')` e **não** vê "Etiquetas" na sidebar; o admin vê Auditoria, link para `route('configuracoes.setores')` e não vê "Horário e SLA"; nenhum papel vê "Acompanhamento". `test_contadores_...` passa a afirmar `data-test="queue-badge"` com 3 e `data-test="sla-badge"` com `aria-label="1 com SLA estourado"` e que o texto "aguardando" solto sumiu; `test_sem_pendencia...` sem os dois `data-test`; novo `test_sla_estourado_sem_ninguem_aguardando` (ticket `EM_ATENDIMENTO` com `waiting_since` e `sla_due_at` vencidos: badge vermelho presente, `queue-badge` ausente); novo `test_configuracao_fica_ativa_nas_paginas_de_configuracao` (GET `/configuracoes/setores` marca o item com o atributo de ativo do Flux). Em `CompanyPageTest`, renomear `test_o_menu_se_chama_atendimento` para `test_o_menu_do_atendente_lista_a_fila` com `assertSee('Fila')->assertDontSee('Fila do setor')`.
- [ ] **Step 2:** `php artisan test --filter=SidebarTest` e `--filter=CompanyPageTest`; esperado FAIL.
- [ ] **Step 3: implementar** a sidebar: lista plana de `primary()`, depois, se `settingsEntry()` não for nulo, o item "Configuração" (ícone `cog-6-tooth`, `href` da entrada, `current` = `settingsActive()`), com um pequeno espaço antes (sem borda). Fila ganha o badge amarelo e o vermelho (ver o stub `flux/sidebar/item` para a forma de pôr dois badges); apagar o `<p>` "N aguardando". Remover o `<p>` manual de cabeçalho "Configuração" e os `flux:sidebar.group` por seção. Manter o aviso do WhatsApp, só com tokens (`warn`).
- [ ] **Step 4:** passa; `php artisan test --parallel --filter=Layout`.
- [ ] **Step 5: Commit** — `feat: sidebar plana com Configuração em uma entrada e badges da Fila`

### Task 5: Hub de Configuração (`x-settings-page`)

**Files:**
- Modify: `resources/views/components/settings-page.blade.php`, `app/Support/Navigation.php` (remover `siblings`, `tree` se sem uso, `SECTION_ATENDIMENTO`/`SECTION_ACOMPANHAMENTO` só se sem uso)
- Test: `tests/Feature/Layout/SettingsPageTest.php`, `tests/Feature/Layout/NavigationTest.php`

**Interfaces:**
- Consumes: `Navigation::settingsGroups(User)` (T3). O componente mantém as props `route`, `title`, `subtitle`, `width`.
- Produces: `<nav aria-label="Configuração">` com um título por grupo e `aria-current="page"` no item da página.

- [ ] **Step 1: reescrever os testes** (RED): `SettingsPageTest::test_admin_ve_a_navegacao_de_configuracao_com_a_pagina_atual_marcada` (em `/configuracoes/setores`: `aria-label="Configuração"`, títulos Atendimento/Equipe/Conexões/Empresa, link para `configuracoes.horario`, `aria-current="page"` em Setores, e **sem** `Configuração ›` nem `Páginas de`); `test_supervisor_so_ve_os_grupos_que_pode_abrir` (sem Conexões nem Empresa); `test_todas_as_paginas_de_configuracao_usam_a_navegacao_comum` (loop das 9 páginas com `nav aria-label="Configuração"`, no lugar do loop de `Configuração ›`); `test_atendente_continua_barrado` (GET `/configuracoes/setores` → 403). Em `NavigationTest`: apagar `test_irmaos_...` e adaptar `labels()` e os três testes por papel para `primary`/`settingsGroups` (a lista por seção deixa de existir).
- [ ] **Step 2:** `php artisan test --filter=SettingsPageTest`; esperado FAIL.
- [ ] **Step 3: implementar** o componente em duas colunas (`lg:flex`): navegação vertical à esquerda, de `settingsGroups(auth()->user())`, que em tela estreita vira faixa no topo com rolagem horizontal; sem breadcrumb nem pílulas; conteúdo à direita preservando os `max-w-*` por `width`. Item ativo com `aria-current="page"` e destaque de marca (`accent-soft`/`accent-content`); alvo de toque ≥ 44 px; transição com `--duration-base`. Depois de verde, remover `siblings` e o que ficar sem uso (`grep` por `siblings(`, `->tree(`, `SECTION_`).
- [ ] **Step 4:** `php artisan test --parallel --filter=Layout` e `--filter=Livewire` passam.
- [ ] **Step 5: Commit** — `feat: Configuração vira hub com navegação própria, sem pílulas nem breadcrumb`

### Task 6: Legibilidade no escuro em todas as telas

**Files:**
- Modify: as views que, no escuro, ficarem ilegíveis (lista decidida pela verificação; trocar cor crua por token só onde falhar)
- Evidence: screenshots em `.superpowers/sdd/2026-11-06-ux-fundacao-e-menu-hub/` (ignorado pelo git)

- [ ] **Step 1:** subir o app local (`php artisan serve`, `npm run dev` ou `build`, usuário admin criado no banco local por factory/tinker) e abrir, no navegador, **claro e escuro** (e mobile 390 px para o menu): Fila, Contatos, Histórico, Relatórios, Auditoria, Automação (lista) e as 9 páginas de Configuração. Capturar uma tela por página e por tema.
- [ ] **Step 2:** anotar toda tela com texto ilegível, fundo branco fixo ou borda sumida; para cada uma, `grep -n "zinc-\|red-\|green-\|amber-\|sky-\|orange-\|blue-" <view>` e trocar pela classe de token (`text-ink-*`, `bg-surface`, `border-line`, `text-danger`, `text-warn`, `text-ok`) ou adicionar o `dark:` pontual.
- [ ] **Step 3:** refazer as capturas das telas mexidas; nada ilegível. Conferir foco visível com Tab na sidebar e na navegação de Configuração.
- [ ] **Step 4: Commit** — `fix: telas legíveis no tema escuro` (um commit só; se nada precisar mudar, registrar no ledger "T6: sem alterações").

### Task 7: Portão 7 — verificação completa

- [ ] **Step 1:** `vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml` (conferir o código de saída), `php artisan test --parallel`.
- [ ] **Step 2:** suíte em série espelhando o CI com Redis (`docker run -d --name redis-test -p 6379:6379 redis:8-alpine`; `CACHE_STORE=redis QUEUE_CONNECTION=redis SESSION_DRIVER=redis REDIS_CLIENT=predis REDIS_HOST=127.0.0.1 REDIS_PORT=6379 php artisan test`); depois `docker rm -f redis-test`.
- [ ] **Step 3:** saídas lidas na própria sessão; qualquer falha volta à tarefa dona. Sem commit próprio.
