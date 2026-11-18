# Redesenho, fatia 3: Histórico e Contatos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Contatos em tabela larga com as colunas que o app já tem e a gaveta do contato abrindo na ficha de leitura do Pencil,
com "Editar contato" levando ao formulário de hoje.

**Architecture:** Markup de `resources/views/livewire/contacts/index.blade.php` (tabela `flux:table` do free) e do ramo não
compacto de `resources/views/livewire/contacts/contact-card.blade.php` (ficha de leitura + formulário), reaproveitando
`$editing`/`toggleEdit()`/`save()` que já existem em `App\Livewire\Contacts\ContactCard` e o `x-link-card` da fatia 2. O
Histórico já está no arranjo do Pencil (lista com avatar, busca + Filtros, painel somente leitura da fatia 2): sem tarefa.

**Tech Stack:** Laravel 13, Livewire 4.4 (build CSP), Flux 2.21 free, Tailwind 4, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-17-redesenho-ui-pencil-design.md` (fatia 3 e "Decididos no início da fatia 3").

## Global Constraints

- Só visual: nenhuma rota, regra, propriedade Livewire ou evento novo. Nada some: toda informação e ação de hoje continua
  alcançável (anti-meta da spec). O formulário de edição continua com todos os campos e regras de hoje.
- Build CSP: nenhuma expressão Alpine nova em atributo. Manter todos os `data-testid`, `wire:*` e `wire:key` existentes.
- Tokens de `resources/css/app.css`; nenhuma cor crua nova. `bg-surface-2`/`hover:bg-surface-2` em `contacts/index` não existem
  como token (não pintam nada): trocar por `bg-muted`/`hover:bg-muted`. Piso de 12 px; alvo mínimo de 24 px.
- Flux free 2.21 antes de componente próprio: `flux:table` (columns, column, rows, row, cell, `:paginate`) existe no free;
  `x-link-card` (fatia 2) para os atendimentos.
- O ramo compacto do `contact-card` (painel da Inbox e do Histórico) foi feito na fatia 2 e não muda.
- Testes com `DB_DATABASE=fluxie_ui_test`; implementadores rodam os testes focados e o Pint nos arquivos tocados. Testes conferem
  estrutura e `data-*`; classe só quando a classe é o requisito.

## Decisões (não reabrir)

- Gaveta abre na ficha de leitura; "Editar contato" troca para o formulário (dono). Salvar volta à ficha (já é o que `save()`
  faz: `editing = false`).
- Ruling: largura das telas de gestão "toda, com teto em 2K" = conteúdo com `max-w-[120rem]` (1920 px), centralizado — em 2560
  a tabela não se espalha por 2300 px. Custo se errado: trocar um valor (as fatias 4 e 5 usam o mesmo).
- Saem do desenho, por não existirem no app: Importar CSV, filtros Setor e Ordenar por, colunas Último atendimento e Tickets.

## Medidas do Pencil (`Dimetal.pen`: "Contatos" e "Contatos - gaveta (ficha)"; PNGs em
`C:/Users/bkpdi/AppData/Local/Temp/claude/abad7b6d92ced975/77f07cf4-6a0c-4f97-94b4-13cbea4ab0cd/scratchpad/pen-f3b/`
— `XAI5l.png` tabela, `ihTGU.png` gaveta, `N9sq02.png` Histórico)

| Parte | Medidas |
|---|---|
| Página Contatos | cabeçalho de página (fatia 1) com Novo contato; busca (largura toda) + filtro de etiquetas (~320 px) na mesma linha em telas largas, empilhados no celular; tabela em cartão `rounded-xl border border-line`, cabeçalho de colunas `bg-canvas` 12 px peso 600 `ink-3` |
| Linha da tabela | padding 11×16; Contato = avatar 32 px + nome 13 px peso 500 `ink` + empresa 12 px `ink-3` embaixo; Telefone mono 12 px `ink-2` (~150 px); Etiquetas (chips de hoje, ~240 px); Ações à direita: Conversar (botão de hoje) + seta `chevron-right` 14 px `ink-3`; hover e selecionada `bg-muted` |
| Gaveta (ficha) | largura `max-w-md` de hoje; cabeçalho: avatar 48 px + nome 16 px peso 600 + telefone mono `ink-2`; cartão de dados `rounded-xl border border-line p-4` com linhas chave/valor (chave 12 px `ink-3`, valor `text-sm font-medium`): E-mail, Empresa, Cargo, Outro telefone, Aniversário, Endereço, Sites (links), Etiquetas (chips); títulos de seção 12 px peso 700 caixa alta `tracking-wider` `ink-3`; Observações; Atendimentos anteriores em `x-link-card`; "Ver todos no Histórico"; status do Google; rodapé fixo com Conversar + Editar contato (mesmo arranjo do rodapé do painel da Inbox) |

## Review Focus

- Contato sem e-mail, empresa, cargo, sites, observações ou etiquetas: a ficha não mostra rótulo vazio nem seção vazia (só
  "Nenhuma observação." como no painel da Inbox, se fizer sentido manter o mesmo texto) (Task 2).
- Erro de validação ao salvar (ex.: site inválido): o formulário continua aberto com o erro; não volta à ficha (Task 2).
- Atendimento anterior que o usuário não pode abrir: cartão sem seta e sem link (Task 2).
- Busca sem resultado e lista vazia: estado vazio dentro da tabela, ocupando a largura (Task 1).
- 390 px: a tabela não estoura a página (rolagem horizontal só dentro do cartão da tabela, se for preciso) (Task 1; conferência).

---

### Task 1: Contatos em tabela larga

**Files:**
- Modify: `resources/views/livewire/contacts/index.blade.php`
- Test: `tests/Feature/Livewire/ContactsIndexTest.php`

- [ ] **Step 1: Testes que falham**
  - `test_contatos_em_tabela_com_as_colunas_do_app`: a página tem `data-testid="contacts-table"` com os cabeçalhos "Contato",
    "Telefone", "Etiquetas"; cada contato é uma linha (`wire:key="contact-{id}"` mantido) com nome, telefone e o botão Conversar.
  - `test_linha_selecionada_usa_muted`: com `selectedId`, a linha do contato tem o token `bg-muted` (a classe é o requisito) e
    nenhuma view de contatos usa `surface-2`.
  - `test_pagina_usa_a_largura_de_gestao`: o contêiner da página tem `max-w-[120rem]` e não tem `max-w-4xl`.
  - `test_busca_sem_resultado_mostra_estado_vazio_na_tabela`: busca sem resultado → `x-empty-state` "Nenhum contato
    encontrado." dentro de `contacts-table`.
- [ ] **Step 2: Rodar e ver falhar** — `--filter=ContactsIndexTest`.
- [ ] **Step 3: Implementar**: `flux:table` com `:paginate="$contacts"` (sai o `{{ $contacts->links() }}`; se algum teste
  existente depender do markup antigo da paginação, ajustar o teste ao do Flux), colunas e células conforme a tabela de
  medidas; a célula Contato é o `<button wire:click="select(id)">` de hoje (alvo da linha); a seta é decorativa
  (`aria-hidden`). Busca + filtro numa linha (`flex flex-col gap-3 sm:flex-row`). Skeleton e formulário "Novo contato"
  seguem como hoje. A tabela fica num contêiner com `overflow-x-auto` para o celular.
- [ ] **Step 4: Rodar e ver passar** — `--filter="ContactsIndexTest|ChatButtonsTest|ContactChips|EmptyStateScreensTest"`.
- [ ] **Step 5: Commit** — `feat: contatos em tabela larga no desenho do Pencil`

### Task 2: Gaveta do contato abre na ficha de leitura

**Files:**
- Modify: `resources/views/livewire/contacts/contact-card.blade.php` (ramo não compacto, ~linhas 95-181)
- Modify: `app/Livewire/Contacts/ContactCard.php` (só o docblock de `$compact`, linha 35: "Em Contatos ela é sempre o
  formulário" deixa de valer)
- Test: `tests/Feature/Livewire/ContactCardTest.php` (ou o teste que já monta a ficha não compacta)

**Interfaces:**
- Consumes: `x-link-card` (`resources/views/components/link-card.blade.php`: `href` → `<a wire:navigate>`, `wire:click` →
  `<button>`, nenhum → `<div>`; seta só quando interativo), `toggleEdit()`, `save()`, `$editing`, `$history`, `$openable`,
  `$hasMore`, `$google`, `$contactTags`, `canChat`.

- [ ] **Step 1: Testes que falham**
  - `test_gaveta_abre_na_ficha_de_leitura`: `ContactCard` não compacto, sem editar → `data-testid="contact-sheet"` com nome,
    telefone, as linhas que existem (E-mail, Empresa, Cargo, Outro telefone, Aniversário, Endereço, Sites) e sem nenhum
    `flux:input` do formulário (`wire:model="form.name"` ausente).
  - `test_editar_contato_abre_o_formulario_e_salvar_volta_a_ficha`: `call('toggleEdit')` → `wire:model="form.name"` presente e
    botão Cancelar (`wire:click="toggleEdit"`); `set('form.name', ...)->call('save')` → ficha de novo.
  - `test_erro_de_validacao_mantem_o_formulario`: site inválido + `save` → erro e formulário ainda aberto.
  - `test_ficha_sem_dados_opcionais_nao_mostra_rotulos_vazios`: contato só com nome e telefone → sem "E-mail", "Empresa",
    "Cargo", "Sites".
  - `test_atendimentos_da_gaveta_em_cartao_e_so_os_abriveis_com_seta`: um anterior legível (encerrado → link para `historico`)
    e um não legível → os dois com `data-link-card`, só o primeiro com `data-link-card-chevron`.
- [ ] **Step 2: Rodar e ver falhar** — `--filter=ContactCardTest`.
- [ ] **Step 3: Implementar** a ficha (`! $compact && ! $editing`) com as medidas da tabela; Etiquetas na ficha como chips
  somente leitura (`x-contact-chips` ou o mesmo chip do painel); o picker de etiquetas fica no formulário, como hoje.
  Atendimentos: `x-link-card` com os destinos de hoje do ramo não compacto (legível encerrado → `historico`; legível aberto →
  `inbox`; senão estático), slot com data · setor, selos e `close_summary`. Rodapé: `chat-button` + `flux:button` "Editar
  contato" (`wire:click="toggleEdit"`), num `flux:button.group` como no painel da Inbox. No formulário não compacto, o botão
  Conversar sai do meio do formulário (fica no rodapé da ficha) e entra "Cancelar" (`toggleEdit`) ao lado de "Salvar contato".
- [ ] **Step 4: Rodar e ver passar** — `--filter="ContactCard|ContactsIndexTest|ChatButtonsTest|StatusBadgeScreensTest"`.
- [ ] **Step 5: Commit** — `feat: gaveta do contato abre na ficha de leitura`

### Task 3: Verificação da fatia (controlador)

- [ ] Portão 7 do `CLAUDE.md`; suíte com Redis num contêiner próprio.
- [ ] Conferência visual (`conferente-visual`, chrome-devtools): 720p, 1080p, 2K e 390 px com toque, claro e escuro —
  tabela de Contatos (largura, teto de 1920, linha selecionada, estado vazio, paginação), gaveta (ficha, editar, cancelar,
  salvar, atendimentos com e sem seta), Histórico (sem regressão da fatia 2).
