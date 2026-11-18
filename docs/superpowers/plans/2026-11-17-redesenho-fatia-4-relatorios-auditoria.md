# Redesenho, fatia 4: Relatórios e Auditoria — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Relatórios e Auditoria na largura de gestão e no visual do Pencil: cartões de número (`x-stat`), painéis com cabeçalho
e tabelas `flux:table`, período abaixo do cabeçalho, sem tirar nenhum dado.

**Architecture:** Um componente novo (`x-stat`) e markup de `resources/views/livewire/reports/index.blade.php`,
`resources/views/livewire/audit/index.blade.php` e `resources/views/livewire/audit/system-log.blade.php`. Nenhuma propriedade,
consulta, rota ou regra nova.

**Tech Stack:** Laravel 13, Livewire 4.4 (build CSP), Flux 2.21 free, Tailwind 4, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-17-redesenho-ui-pencil-design.md` (fatia 4).

## Global Constraints

- Só visual: nenhuma propriedade Livewire, consulta, rota ou regra nova. Nada some: todo número, coluna, aviso e filtro de hoje
  continua (inclusive o aviso do verificador de SLA e "Ninguém esperando além do SLA agora.").
- Build CSP: nenhuma expressão Alpine nova em atributo. Manter `data-testid`, `wire:*` e `wire:key` existentes.
- Tokens de `resources/css/app.css`; nenhuma cor crua nova. Piso de 12 px; alvo mínimo de 24 px.
- Largura de gestão (decidida na fatia 3): conteúdo `max-w-[120rem]` centralizado no lugar do `max-w-6xl`.
- `flux:table` do free nas tabelas; as células do Flux trazem `text-zinc-500` e bordas zinc: usar `text-* ` num `span` interno
  e `border-line!` nas linhas/cabeçalho, como em `resources/views/livewire/contacts/index.blade.php` (fatia 3). Paginação:
  continua `{{ $events->links() }}` (a do app, com `wire:loading` e tokens — não usar `:paginate` do Flux;
  `tests/Feature/PaginationColorsTest.php`).
- Testes com `DB_DATABASE=fluxie_ui_test`; implementadores rodam os testes focados e o Pint nos arquivos tocados. Testes conferem
  estrutura e `data-*`; classe só quando a classe é o requisito.

## Decisões (não reabrir)

- Saem do desenho por não existirem no app: Filtros, Exportar CSV, Exportar auditoria, "Personalizado" no período,
  "Envelhecimento da fila", colunas Setor e "Fora do painel" do desempenho, busca e coluna IP da Auditoria.
- O gráfico de volume por setor continua o `x-bar-chart` (barras horizontais), dentro de um painel com o cabeçalho do desenho.
- Ruling: `x-audit-tabs` fica (a spec mandava trocar por `flux:navbar`): o `flux:navbar` desenha itens em pílula, não abas
  sublinhadas, e o componente já é o do Pencil desde a fatia 1. Custo se errado: trocar um componente de 14 linhas.
- Flux free tem stat? Não (é Pro): `x-stat` é próprio, imitando o Flux Pro, com comentário de cabeçalho dizendo isso.

## Medidas do Pencil (`Dimetal.pen`: "Relatorios" e "Auditoria"; PNGs em
`C:/Users/bkpdi/AppData/Local/Temp/claude/abad7b6d92ced975/77f07cf4-6a0c-4f97-94b4-13cbea4ab0cd/scratchpad/pen-f4b/`)

| Parte | Medidas |
|---|---|
| Período (Relatórios) | `flux:radio.group variant="segmented"` sai das ações do cabeçalho e vai para uma linha logo abaixo dele, à esquerda |
| Stat (`x-stat`) | cartão `rounded-xl border border-line bg-surface p-4`; rótulo 12 px `ink-3`; valor mono `text-2xl` peso 500 `ink` (tom `danger` quando pedido); nota opcional 12 px `ink-3` embaixo |
| Painel | cartão `rounded-xl border border-line bg-surface` com `overflow-hidden`; cabeçalho `px-4 py-3.5`: título 13 px peso 600 `ink` (`text-sm font-semibold`) + subtítulo 12 px `ink-3`; corpo: tabela encostada nas bordas, ou conteúdo com `p-4` |
| Tabela | cabeçalho de colunas `bg-canvas` 12 px peso 600 `ink-3`; linhas padding 11×16, bordas `line`; números mono à direita |
| Desempenho por atendente | coluna Atendente com avatar 24 px (`x-avatar size="xs"`) + nome |
| Auditoria | abas no cabeçalho (como hoje); filtros Período/Usuário/Ação numa linha; tabela larga num painel; ação como `x-status-badge tone="neutral"`; estado vazio (`x-empty-state` não compacto, com círculo) ocupando a tabela |

## Review Focus

- Período sem dados: cada painel mostra a frase vazia de hoje ("Nenhum ticket no período.", "Nenhum atendimento no período.",
  "Ninguém esperando além do SLA agora.") dentro do painel (Task 1).
- Valores nulos nos stats ("—" de mediana e SLA) continuam aparecendo (Task 1).
- Auditoria do Sistema (`system-log`) com o mesmo arranjo da de tickets; as duas abas continuam navegando (Task 2).
- Detalhe longo de evento quebra linha dentro da célula, sem estourar a tabela (Task 2; conferência).
- 390 px: stats empilhados, tabelas rolando dentro do painel, página sem rolagem lateral (Tasks 1 e 2; conferência).

---

### Task 1: `x-stat` e Relatórios

**Files:**
- Create: `resources/views/components/stat.blade.php`
- Modify: `resources/views/livewire/reports/index.blade.php`
- Test: `tests/Feature/Components/StatTest.php` (novo), `tests/Feature/Livewire/ReportsPageTest.php` (ou o teste que já renderiza
  `App\Livewire\Reports\Index` — conferir o nome em `tests/Feature`)

**Interfaces:**
- Produces: `<x-stat label="string" :value="string|int" note="?string" tone="?'danger'" />` — raiz `data-stat`, rótulo `dt`,
  valor `dd` com `data-stat-value`; usado num `dl` (o pai é o `dl class="grid …"` de hoje).

- [ ] **Step 1: Testes que falham**
  - `StatTest::test_stat_mostra_rotulo_valor_e_nota`: `<x-stat label="SLA cumprido" value="96,4%" note="no período" />` → `data-stat`,
    `dt` "SLA cumprido", `data-stat-value` "96,4%", nota presente; sem `note` não há nota.
  - `StatTest::test_tom_danger_pinta_o_valor`: `tone="danger"` → o `data-stat-value` tem `text-danger` (a classe é o requisito).
  - `ReportsPageTest::test_relatorios_usam_stats_e_paineis`: 4 `data-stat`; painéis `data-panel` para "Aguardando resposta além do
    SLA", "Volume por setor" e "Desempenho por atendente"; o período (`flux:radio.group`) fora do `data-page-header-actions`;
    contêiner `max-w-[120rem]`.
  - `ReportsPageTest::test_desempenho_mostra_avatar_do_atendente`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** `x-stat` com as medidas; Relatórios: os 4 stats com `x-stat` (o 4º com `tone="danger"` quando
  `overdue > 0` e a nota "tickets vencidos agora"); painéis com `data-panel` no arranjo da tabela de medidas; as duas tabelas em
  `flux:table`; o `x-bar-chart` dentro do painel "Volume por setor" (`caption` de hoje); o aviso do verificador de SLA fica como
  `flux:callout variant="warning"` (ou o bloco de hoje com `rounded-xl`) no mesmo lugar.
- [ ] **Step 4: Rodar e ver passar** — `--filter="StatTest|Reports|BarChartTest|StatusBadgeScreensTest|EmptyStateScreensTest"`.
- [ ] **Step 5: Commit** — `feat: relatórios com stats e painéis do desenho`

### Task 2: Auditoria (tickets e sistema)

**Files:**
- Modify: `resources/views/livewire/audit/index.blade.php`, `resources/views/livewire/audit/system-log.blade.php`
- Test: `tests/Feature/Livewire/AuditPageTest.php` (ou o teste que já renderiza `App\Livewire\Audit\Index`) e
  `tests/Feature/Livewire/SystemLogPageTest.php`

- [ ] **Step 1: Testes que falham**
  - `test_auditoria_em_tabela_larga_num_painel`: contêiner `max-w-[120rem]`; tabela num `data-panel`; colunas Data e hora, Usuário,
    Ação, Ticket, Detalhe; a ação de cada evento num `x-status-badge` (`data-status-tone="neutral"`); `wire:key="event-{id}"`.
  - `test_auditoria_vazia_mostra_estado_vazio_com_circulo`: sem eventos → `data-empty-state-icon` dentro da tabela.
  - Mesmos dois testes para o `system-log` (com as colunas que ele já tem).
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar**: largura; filtros numa linha (`grid sm:grid-cols-3` de hoje); `flux:table` no painel; badge da
  ação; estado vazio não compacto; `{{ $events->links() }}` (ou o paginador de hoje do system-log) no rodapé do painel só com
  `hasPages()`.
- [ ] **Step 4: Rodar e ver passar** — `--filter="Audit|SystemLog|PaginationColorsTest|PageHeaderTest|EmptyStateScreensTest"`.
- [ ] **Step 5: Commit** — `feat: auditoria em tabela larga no desenho do Pencil`

### Task 3: Verificação da fatia (controlador)

- [ ] Portão 7 do `CLAUDE.md`; suíte com Redis num contêiner próprio.
- [ ] Conferência visual (`conferente-visual`, chrome-devtools): 720p, 1080p, 2K e 390 px com toque, claro e escuro — Relatórios
  (stats, painéis, tabelas, gráfico, períodos Hoje/7/30, vazio), Auditoria Tickets e Sistema (filtros, tabela, badge, vazio,
  paginação se houver dados).
