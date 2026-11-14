# Gráfico de barras nos Relatórios Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fatia F: "Volume por setor" usa um gráfico de barras próprio (SVG), com dica ao passar o mouse e tabela equivalente para leitor de tela.

**Architecture:** Componente Blade `x-bar-chart` (`resources/views/components/bar-chart.blade.php`), sem biblioteca. Cada linha: rótulo e valor em HTML (texto nunca abaixo de 12 px, por isso fora do SVG), barra em SVG com `viewBox` proporcional e `preserveAspectRatio="none"`, `<title>` com o número exato e a porcentagem (dica nativa). A lista visual fica `aria-hidden`; uma `<table class="sr-only">` carrega os mesmos números.

**Tech Stack:** Laravel Blade, Tailwind.

**Spec:** `docs/superpowers/specs/2026-11-14-ux-simplificacao-design.md` (fatia F, item 13). Números e período não mudam.

## Global Constraints

- Sem texto abaixo de 12 px; sem dependência nova; tokens do tema (nada de cor fixa).

## Review Focus

- **Zero e vazio:** setor com 0 tickets mostra barra vazia (sem divisão por zero); sem linhas, o estado vazio de sempre.
- **Nome longo/HTML:** o rótulo é escapado e truncado com o nome inteiro na dica.
- **Leitor de tela:** os mesmos números na tabela `sr-only`; o desenho não é lido em duplicidade.

### Task 1: `x-bar-chart` e uso em Relatórios

**Files:**
- Create: `resources/views/components/bar-chart.blade.php`, `tests/Feature/Components/BarChartTest.php`
- Modify: `resources/views/livewire/reports/index.blade.php` (bloco "Volume por setor"), `tests/Feature/Reports/AttendanceReportTest.php` (se citar o markup antigo)

**Interfaces:**
- Produces: `<x-bar-chart :rows="list<array{label: string, value: int}>" caption="Volume por setor" />`. Cada linha: `data-testid="bar-row"`, `<svg>` com `<title>"{label}: {value} ({pct}%)"`, `pct` = valor / soma × 100 arredondado (0 se a soma é 0); largura da barra = valor / maior valor (mínimo 1 no divisor).

- [ ] **Step 1: Testes (RED).** `BarChartTest`: renderiza uma linha por item com o rótulo e o valor; `<title>` traz `Vendas: 6 (75%)`; a barra do maior vale 100% e a metade vale 50% (atributo `width` do `rect`); valor 0 não quebra e dá `0%`; `<table class="sr-only">` com os mesmos números; rótulo com `<script>` sai escapado; guarda `ViewConventionsTest` segue verde. Relatórios: a tela mostra `x-bar-chart` (testid `bar-row`) com os setores do período e o estado vazio sem tickets.
- [ ] **Step 2:** FAIL. **Step 3:** implementar. **Step 4:** verdes + suíte; conferir no Chrome (barras, dica, 1280×720 e 360). Commit `feat: grafico de barras proprio em Volume por setor`.

### Task 2: verificação, revisão e PR (portões 6 e 7, ledger, PR, merge com CI verde)
