# UX 4b: componentes de base e demais telas (PR 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Um selo de status único (`x-status-badge`) com o tom vindo do enum, regra de botões aplicada (só `flux:button`), estado vazio e esqueleto de carregamento padronizados e usados em todas as telas.

**Architecture:** Só Blade e métodos de enum. `tone()` em cada enum (fonte única) → `<x-status-badge :status="$enum" />` (componente anônimo com tokens do app) → trocado nos pontos onde o estado aparece. `x-empty-state` e `x-skeleton-rows` (sobre `flux:skeleton`) substituem textos soltos e silêncio de carregamento. Guardas de convenção em testes impedem a volta do que foi removido.

**Tech Stack:** Laravel 13, Livewire 4, Flux 2.21 (grátis), Tailwind 4, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-10-ux-componentes-design.md`

## Global Constraints

- Só tokens de cor do app (`ink*`, `canvas`, `surface`, `line`, `accent*`, `warn`, `danger`, `ok`, `bot`); vermelho só para SLA estourado/erro; verde só `ok`; nada de `amber/sky/green/red-*` cru nas views; `ThemeTokensTest` continua verde e é estendido.
- Estado nunca só por cor: o selo sempre tem texto (e ponto decorativo `aria-hidden`).
- Animação do esqueleto só com movimento permitido: o `app.css` já zera animações em `prefers-reduced-motion` (`test_movimento_reduzido_zera_transicoes`); não criar animação nova fora disso.
- Texto em português; botão só com ícone sempre com `aria-label` (e `tooltip` quando couber).
- Blade (Laravel 13): componente anônimo em `resources/views/components/`, `@props` primeiro, `$attributes->class(...)`, slot nomeado vazio por `$slot->isEmpty()`/`$slot->hasActualContent()`. Scripts Python que gravam Blade: string raw (`r'...'`); um único bloco `@php ... @endphp` por arquivo grande (nunca `@php(...)` misturado com bloco). Depois de gravar `.php`, `vendor/bin/pint` nos tocados.
- Buscas em `resources/views` por `grep` no Bash (CodeGraph não indexa Blade). Saída de Blade vem condensada: trocar `<`/`>` por `‹`/`›` ao imprimir, ou `caveman_retrieve`.
- Verificação visual no **Claude in Chrome** (nunca no DevTools), resoluções por iframe do mesmo site, claro e escuro, 1280x720, 1920x1080, 2560x1440 e 360px.
- Um commit por tarefa, em português, com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Nenhum achado de revisão, inclusive menor, fica sem correção antes do PR.

## Review Focus

1. Enum novo sem `tone()` (caso esquecido): o selo falha alto (`match` sem padrão) e um teste percorre `cases()` de cada enum (Tarefa 1).
2. Estado fora do enum (SLA, usuário ativo/inativo, workflow publicado/rascunho, sessão removida do canal): tom nomeado explícito, com teste do texto e do tom (Tarefas 3 e 4).
3. Selo legível nos dois temas: contraste AA de cada tom sobre o próprio fundo calculado do CSS (Tarefa 2).
4. Esqueleto que fica preso ou pisca: só aparece com `wire:loading` e `.delay`, e some quando a resposta chega; sem esqueleto na primeira pintura (Tarefa 7).
5. Botão de ação convertido perdendo `wire:click`/`wire:confirm`/`x-on:click`: testes de comportamento das telas seguem verdes e a lista de `<button>` crus restantes é fixada por um guarda (Tarefa 6).

---

### Task 1: `tone()` nos enums

**Files:**
- Modify: `app/Enums/TicketStatus.php`, `ChannelStatus.php`, `SectorStatus.php`, `GoogleConnectionStatus.php`, `CloseOutcome.php`
- Test: `tests/Unit/EnumToneTest.php` (novo)

**Interfaces:**
- Produces: `public function tone(): string` em cada um dos 5 enums, devolvendo um destes: `neutral | bot | ok | warn | danger | accent`.

Mapeamento (decisão): `TicketStatus`: PENDENTE `bot`, AGUARDANDO `warn`, EM_ATENDIMENTO `ok`, ENCERRADO `neutral`. `ChannelStatus`: WORKING `ok`, FAILED `danger`, SCAN_QR_CODE/PASSKEY_REQUIRED/PASSKEY_CONFIRMATION_REQUIRED `warn`, STOPPED/STARTING `neutral`. `SectorStatus`: ATIVO `ok`, FORA_DO_MENU `bot`, DESATIVADO `neutral`. `GoogleConnectionStatus`: CONNECTED `ok`, NEEDS_REAUTH `warn`. `CloseOutcome`: RESOLVIDO `ok`, SEM_RESPOSTA `neutral`, CLIENTE_PEDIU `neutral`.

- [ ] **Step 1: Teste que falha** (`EnumToneTest`, `PHPUnit\Framework\TestCase`): um `data provider` com a tabela acima (`[Caso, tom esperado]`) e `test_todo_caso_dos_enums_tem_tom_valido`: para cada um dos 5 enums, `cases()` inteiro devolve um tom dentro do conjunto permitido.
- [ ] **Step 2:** `php artisan test --filter=EnumToneTest` → FAIL (método inexistente).
- [ ] **Step 3:** Implementar `tone()` com `match ($this)` sem `default` (caso novo quebra o teste de percurso).
- [ ] **Step 4:** PASS. **Step 5:** Commit `feat: tom de cada estado nos enums (tone)`.

### Task 2: `x-status-badge`

**Files:**
- Create: `resources/views/components/status-badge.blade.php`
- Modify: `tests/Unit/ThemeTokensTest.php` (contraste dos tons)
- Test: `tests/Feature/Components/StatusBadgeTest.php` (novo, usa `$this->blade(...)`)

**Interfaces:**
- Consumes: `tone()` (Tarefa 1) e `label()` dos enums.
- Produces: `<x-status-badge :status="$enum" />` (tom e texto do enum) e `<x-status-badge tone="warn">Texto</x-status-badge>` (estado fora do enum). Props: `status` (BackedEnum|null), `tone` (string|null), `size` (`sm` padrão). Renderiza `<span data-status-tone="{tone}" class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium {classes do tom}">` com um ponto `<span aria-hidden="true" class="size-1.5 rounded-full bg-current">` e o texto. Classes por tom: `neutral` `bg-canvas text-ink-2 ring-1 ring-line`; `bot` `bg-bot/10 text-bot`; `ok` `bg-ok/10 text-ok`; `warn` `bg-warn/10 text-warn`; `danger` `bg-danger/10 text-danger`; `accent` `bg-accent-soft text-accent-content`. Tom desconhecido: `InvalidArgumentException`.

- [ ] **Step 1: Testes que falham**
  - `StatusBadgeTest::test_renderiza_o_tom_e_o_texto_do_enum`: `TicketStatus::AGUARDANDO` → `data-status-tone="warn"`, texto "Aguardando", classe `text-warn`.
  - `test_slot_substitui_o_texto_e_tom_explicito_vale_sem_enum`: `<x-status-badge tone="danger">SLA estourado</x-status-badge>`.
  - `test_tom_desconhecido_falha_alto`: `tone="rosa"` → exceção.
  - `test_ponto_e_decorativo_e_o_texto_sempre_existe`: o ponto tem `aria-hidden="true"`; texto não vazio.
  - `test_classe_do_chamador_se_mistura`: `class="ms-2"` aparece no `span`.
  - `ThemeTokensTest::test_tons_do_selo_tem_contraste_aa_sobre_o_proprio_fundo`: para cada tom (`ok`, `bot`, `warn`, `danger`, `accent` e `neutral`) nos dois temas, a razão do texto sobre o fundo calculado (`mix`/`ratio` que o arquivo já tem; fundo `/10` sobre `surface` e `canvas`) é >= 4.5.
- [ ] **Step 2:** `--filter='StatusBadgeTest|ThemeTokensTest'` → FAIL.
- [ ] **Step 3:** Implementar o componente (mapa de classes por tom em um `match` no próprio componente).
- [ ] **Step 4:** PASS. **Step 5:** Commit `feat: componente x-status-badge`.

### Task 3: Selo no Inbox, Histórico, contatos e Relatórios

**Files:** `resources/views/livewire/inbox/inbox.blade.php` (cabeçalho da conversa; some `$statusColor`), `livewire/history/index.blade.php`, `livewire/contacts/contact-card.blade.php`, `components/ticket-details.blade.php`, `livewire/reports/index.blade.php` (coluna "Situação"), `livewire/inbox/inbox.blade.php` (cabeçalho de encerrado: desfecho)
**Test:** `tests/Feature/Components/StatusBadgeScreensTest.php` (novo) e ajustes nos testes existentes dessas telas.

- [ ] **Step 1: Testes que falham** (`StatusBadgeScreensTest`, usando o padrão de `InboxHeaderTest`/`HistoryTest`):
  - Inbox: o cabeçalho tem `[data-status-tone]` com o tom de cada `TicketStatus` (parametrizar pelos 4 status) e **não** existe mais `data-flux-badge` com cor no cabeçalho.
  - Histórico: item e cabeçalho de ticket encerrado mostram o desfecho (`CloseOutcome`) em selo com o tom do enum.
  - Cartão do contato: cada atendimento anterior mostra o status em selo.
  - Relatórios, tabela de espera: "Situação" usa `x-status-badge` com `tone="danger"` ("SLA estourado") ou `tone="warn"` ("Perto de estourar").
- [ ] **Step 2:** FAIL. **Step 3:** Trocar cada ponto (ler o markup antes; manter o texto). **Step 4:** `--filter='Inbox|History|Contact|Reports|Sla|ThemeTokens'` PASS (ler cada falha de teste antigo: se checava a cor crua, atualizar para o tom). **Step 5:** Commit `feat: selo de status no Inbox, Histórico, contatos e Relatórios`.

### Task 4: Selo em Usuários, Canais, Google, Setores, Automação, Auditoria e sino

**Files:** `livewire/settings/users.blade.php` (ativo/inativo e papel), `livewire/settings/channel-card.blade.php` (a pílula local `$tone/$badge` passa a `x-status-badge`; "Desativado" `neutral`, "Sessão removida" `danger`), `livewire/settings/channels.blade.php`, `livewire/settings/google-contacts.blade.php` (`GoogleConnectionStatus`) e a sincronização por contato em `livewire/contacts/` , `livewire/settings/sectors.blade.php` (`SectorStatus`), `livewire/automation/index.blade.php` (workflow publicado `ok` / rascunho `neutral`), `livewire/audit/index.blade.php` (o que for estado), `livewire/alerts/bell.blade.php` (mapa de tom do alerta, se houver pílula).
**Test:** `tests/Feature/Components/StatusBadgeSettingsTest.php` (novo) + testes existentes.

- [ ] **Step 1: Testes que falham:** por tela, o estado aparece em `[data-status-tone]` com o tom certo: canal `WORKING` `ok`, `FAILED` `danger`, QR `warn`, desativado `neutral`, sessão removida `danger`; usuário inativo `neutral` e ativo `ok`; conexão Google `ok`/`warn`; setor `ok`/`bot`/`neutral`; workflow publicado `ok`; (papel do usuário `neutral`, sem cor semântica).
- [ ] **Step 2–4:** como a Tarefa 3. **Step 5:** Commit `feat: selo de status nas telas de configuração, automação e auditoria`.
- [ ] **Guarda (no mesmo commit):** `tests/Unit/ViewConventionsTest.php::test_views_nao_usam_mais_status_color_nem_selo_a_mao`: nenhum `.blade.php` contém `$statusColor`, `flux:badge` com `color="` de paleta crua (`sky|amber|green|red|orange|zinc`), nem o padrão de pílula manual `rounded-full ... bg-(ok|warn|danger)/10` fora de `components/status-badge.blade.php` (lista curta de exceções justificadas em comentário no teste: o ponto da Fila e o sino).

### Task 5: Botões: `docs/ui-botoes.md` e migração do `x-ui.button`

**Files:** `docs/ui-botoes.md` (novo), `livewire/settings/channel-card.blade.php` (14), `livewire/settings/channels.blade.php` (2), `pages/auth/login.blade.php` (2); remover `components/ui/button.blade.php` quando sem uso (manter `components/ui/field.blade.php`, usado pelo login).
**Test:** `tests/Unit/ViewConventionsTest.php` (guarda) + testes das telas.

Regra a documentar (uma página curta): uma ação **primária** por tela ou seção (`variant="primary"`); secundário `filled` ou `subtle`; `danger` só destrutivo; ícone sem texto com `aria-label` e `tooltip`; tamanho `sm` padrão, `xs` em faixas e linhas densas; carregamento: `flux:button` com `wire:click`/`submit` já mostra spinner; confirmar destrutivo com `wire:confirm`. Mapa de migração: `x-ui.button` `primary` → `variant="primary"`; `secondary` → `variant="filled"`; `ghost` → `variant="ghost"`; `type`, `wire:*` e `x-on:*` mantidos.

- [ ] **Step 1: Teste que falha:** `ViewConventionsTest::test_nenhuma_view_usa_x_ui_button`: grep em todas as views por `<x-ui.button` → vazio.
- [ ] **Step 2:** FAIL (18 usos). **Step 3:** Migrar; apagar o componente; escrever `docs/ui-botoes.md`. **Step 4:** `--filter='Channel|Login|Auth|ViewConventions'` PASS; conferir o login e o cartão de canal no navegador (Tarefa 8).
- [ ] **Step 5:** Commit `feat: botões só com flux:button e regra documentada`.

### Task 6: `<button>` crus que são ação

**Files:** as views com `<button` cru (listar com `grep -rn "<button" resources/views`); converter **só as ações** em `flux:button` (`size="xs"` em linhas densas): "Reenviar", "Apagar" (nota), "Regravar/Descartar/Enviar" da voz, "Salvar nota", "Marcar todas como lidas", "Cancelar", botões de lista do sino. Ficam crus: abas (`role="tab"`), gatilhos Alpine internos, itens de menu e de lista selecionável, e qualquer botão cujo visual não cabe no Flux (decisão registrada no guarda).
**Test:** `tests/Unit/ViewConventionsTest.php::test_botoes_crus_restantes_sao_os_aprovados`: um array `arquivo => quantidade` com os `<button` crus que sobram; mudar a lista exige decisão consciente.

- [ ] **Step 1:** rodar o grep e montar a tabela aprovada (arquivo → motivo) no teste. **Step 2:** teste FAIL (a contagem atual é maior que a aprovada). **Step 3:** converter as ações mantendo `wire:click`, `wire:confirm`, `x-on:click`, `x-bind:*`, `aria-*`, `data-testid`. **Step 4:** `--filter='Inbox|Transcript|Composer|Voice|Alert|ViewConventions'` PASS (ler cada falha). **Step 5:** Commit `feat: botões de ação crus viram flux:button`.

### Task 7: `x-empty-state` e esqueleto de carregamento

**Files:** `resources/views/components/empty-state.blade.php`, `resources/views/components/skeleton-rows.blade.php` (novos); aplicar em `livewire/contacts/index`, `history/index`, `audit/index`, `automation/index`, `reports/index`, `settings/tags`, `settings/quick-replies`, `settings/business-hours-page`, `contacts/contact-card`, `components/ticket-details` (linha do tempo), `inbox/inbox` (lista vazia e conversa ao trocar de ticket).
**Test:** `tests/Feature/Components/EmptyStateTest.php`, `tests/Feature/Components/SkeletonRowsTest.php`, e os testes de tela existentes.

**Interfaces:**
- `<x-empty-state icon="inbox" title="Nenhum contato ainda" description="..."><x-slot:action>...botão...</x-slot:action></x-empty-state>`: props `icon` (nome de ícone do Flux), `title`, `description` (opcional); slot nomeado `action` opcional (`$action->isEmpty()`); raiz `<div data-empty-state class="flex flex-col items-center gap-2 px-6 py-10 text-center">` com `flux:icon`, `flux:heading`/`flux:text`.
- `<x-skeleton-rows :rows="5" />`: `flux:skeleton.group animate="pulse"` com N linhas (avatar redondo + duas linhas), `aria-hidden="true"` e `data-skeleton`.

Regra de uso: `wire:loading.delay` (150ms) + `wire:target` nos filtros/busca/período de cada tela, com o esqueleto **no lugar** da lista enquanto carrega (`wire:loading` no esqueleto, `wire:loading.remove` na lista); sem esqueleto na primeira pintura. No Inbox, `wire:target="select"` na área da conversa.

- [ ] **Step 1: Testes que falham:** `EmptyStateTest` (título, descrição, ícone; ação só quando há slot; sem ação não renderiza o contêiner da ação); `SkeletonRowsTest` (N linhas, `aria-hidden`, `data-skeleton`, `animate-pulse`); por tela: lista vazia mostra `[data-empty-state]` com o texto novo; a tela tem `[data-skeleton]` com `wire:loading` e `wire:target` que lista os filtros reais da tela.
- [ ] **Step 2:** FAIL. **Step 3:** Criar os componentes e aplicar tela por tela (ler o markup de cada vazio antes; manter as asserções de texto dos testes antigos atualizando o texto só quando o novo for melhor). **Step 4:** `--filter='EmptyState|Skeleton|Contacts|History|Audit|Automation|Reports|Tags|QuickReplies|BusinessHours|Inbox'` PASS. **Step 5:** Commit `feat: estado vazio e esqueleto de carregamento padronizados`.

### Task 8: Demais telas no navegador e ajustes

**Files:** as das Tarefas 3 a 7 se o navegador mostrar problema; testes novos para comportamento.

- [ ] **Step 1:** Subir o app local (receita da memória; dados por tinker: tickets em todos os status, canais em vários estados, usuários ativo/inativo, setores, workflow publicado e rascunho, contatos com e sem Google, eventos de auditoria, listas vazias) e abrir com o Claude in Chrome numa aba nova.
- [ ] **Step 2:** Em 1280x720, 1920x1080, 2560x1440 e 360px, claro e escuro, conferir cada tela: Fila, Histórico, Contatos, Relatórios, Auditoria, Automação, Configuração (Canais, Usuários, Setores, Etiquetas, Respostas rápidas, Horário, Google) e o login: selos com o tom certo e legíveis, botões consistentes, estados vazios, esqueleto aparecendo ao filtrar (atrasar a resposta com `Network.emulateNetworkConditions` não existe no Chrome extension: usar um `sleep` temporário no componente local, desfeito depois) e sumindo; alinhamento e sem rolagem horizontal.
- [ ] **Step 3:** Corrigir (teste primeiro quando for comportamento) e repetir o Step 2. **Step 4:** Commit `fix: ajustes das telas com os novos componentes`, se houve ajuste.

**Depois das tarefas:** portão 6 (revisor independente do branch; todo achado, inclusive menor, corrigido), portão 7 (suíte com Redis em série, Pint, Larastan, PHPMD), portão 8 (PR 2, CI, merge, `deploy-beta`, teste final no beta com o Claude in Chrome nas três resoluções).
