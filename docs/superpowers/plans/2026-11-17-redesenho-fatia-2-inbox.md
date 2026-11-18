# Redesenho, fatia 2: Inbox — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Levar a Inbox ao arranjo do Pencil (fila, cabeçalho da conversa, painel de detalhes com atendimentos anteriores em
cartão com seta), sem tirar nenhuma informação ou ação que o app já tem.

**Architecture:** Um componente novo (`x-link-card`) e mudanças de markup em `inbox.blade.php`, `ticket-details.blade.php`,
`contact-card.blade.php` (só o modo compacto) e `disclosure.blade.php`. Nenhuma propriedade, método, rota ou regra nova.

**Tech Stack:** Laravel 13, Livewire 4.4 (build CSP), Flux 2.21 free, Tailwind 4, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-17-redesenho-ui-pencil-design.md` (fatia 2 e "Decididos no início da fatia 2").

## Global Constraints

- Só visual: nenhuma função, rota, propriedade Livewire, evento ou regra nova. Nada some: toda informação e ação de hoje
  continua alcançável (anti-meta da spec).
- Build CSP do Livewire: nenhuma expressão Alpine nova em atributo; estado novo, se houver, em `Alpine.data` em `resources/js/`.
- Manter todos os `data-testid`, `wire:*`, `x-data`/`x-on`/`x-bind` existentes; manter o comportamento 2xl/`sheet` do painel,
  o `@container` da coluna da conversa e as bolhas/setinha da fatia 0 (não mexer em `transcript.blade.php`).
- Tokens de `resources/css/app.css`; nenhuma cor crua nova. Piso de 12 px (`text-xs`); alvo mínimo de 24 px.
- Flux free 2.21 antes de componente próprio. Conferido: o free não tem cartão-link (o `flux:card` não é link nem tem seta);
  `x-link-card` é próprio, no padrão "imitando o Flux Pro" (comentário de cabeçalho dizendo isso).
- `contact-card.blade.php` também serve o Histórico (somente leitura) e a gaveta de Contatos: mudar só o ramo compacto e
  conferir o Histórico na verificação. O ramo não compacto (lista de anteriores da gaveta, linhas 145+) é da fatia 3.
- Larguras das colunas não mudam nesta fatia (o Pencil desenha 1440 com o painel aberto, estado que no app só existe em 2K).
- Testes com `DB_DATABASE=fluxie_ui_test`; implementadores rodam os testes focados e o Pint nos arquivos tocados. Testes
  conferem estrutura e `data-*`; classe só quando a classe É o requisito.

## Decisões já tomadas (não reabrir)

- Encerrar só no cabeçalho (dono). Transferir vira botão quando a coluna da conversa é larga; estreita, volta ao "…" (dono).
- Bloco Ticket entra no painel da Inbox (spec), sem tirar a linha do cabeçalho. Ruling: o comentário de `ticket-details`
  dizia que isso foi tirado de propósito; a spec da fatia 2 o devolve — custo se errado: um bloco a remover.
- `x-confirm-dialog` fica (spec mandava avaliar `flux:modal`): o `data-confirm` global e o `<dialog>` nativo já cumprem o
  build CSP; trocar não muda nada visível. Custo se errado: nenhum visual.
- Selos de status seguem o `x-status-badge` do app (o Pencil foi ajustado a ele).

## Medidas do Pencil (`Dimetal.pen`, tela "Inbox"; PNGs em
`C:/Users/bkpdi/AppData/Local/Temp/claude/abad7b6d92ced975/77f07cf4-6a0c-4f97-94b4-13cbea4ab0cd/scratchpad/pen-fatia2/`)

| Parte | Medidas |
|---|---|
| Link Card | fundo `canvas`, raio 8 px (`rounded-lg`), padding 8×9 (`px-2.5 py-2`), gap 8; título mono 12 px `ink-2`; linha secundária 12 px `ink-3`; seta `chevron-right` 12 px `ink-3` à direita só quando é link; hover `bg-muted` |
| Título de seção (painel) | 12 px, peso 700, caixa alta, `tracking-wider`, `ink-3`; seta de recolher à direita (como hoje) |
| Linha chave/valor | chave 12 px `ink-3`; valor 12–13 px `ink` peso 500 (`text-sm font-medium`), 2 px entre eles; 10 px entre linhas |
| Aviso de SLA no painel | caixa `warn-soft` (estourado: `danger` a 10%), raio 8, padding 11; texto 12 px peso 600 na cor do tom |
| Título de grupo da fila | 12 px, peso 700, caixa alta, `tracking-wider`; cores de hoje (Pendente `ink-3`, Aguardando `warn`, Em atendimento `accent-content`) |
| Cartão da fila | linha nova embaixo da prévia: `x-status-badge` + protocolo mono 12 px `ink-3`; selecionado e hover `bg-muted` (hoje `bg-canvas`, que some sobre `surface`) |
| Cabeçalho da conversa | ordem das ações: Detalhes, Transferir (larga), Assumir/Tomar, "…", Encerrar |

## Review Focus

- Atendimento anterior que o usuário NÃO pode abrir (de outro setor, sem `$mine`): cartão sem seta e sem link, como hoje
  (Task 1).
- Painel do Histórico (somente leitura) continua com Ticket + Encerrado em + Motivo + Resumo, telefone do contato e linha do
  tempo; não ganha o aviso de SLA (Task 2).
- Ticket encerrado ou sem `sla_due_at` aberto na Inbox: sem aviso de SLA no painel (Task 2).
- Coluna estreita com só `canTransfer` (sem `canReassign`): o "…" não fica vazio em coluna larga e o Transferir nunca some
  (Task 3).
- Fila em "Meus" e no histórico recente ("Mais"): a linha nova de status + protocolo não estoura o cartão em 1280 com o
  painel fechado e em 390 px (Task 4; conferência visual).

---

### Task 1: `x-link-card` e Atendimentos anteriores

**Files:**
- Create: `resources/views/components/link-card.blade.php`
- Modify: `resources/views/livewire/contacts/contact-card.blade.php` (ramo compacto, bloco "Anteriores", linhas ~40-72)
- Test: `tests/Feature/Components/LinkCardTest.php` (novo), `tests/Feature/Livewire/ContactCardCompactTest.php`

**Interfaces:**
- Produces: `<x-link-card :href="?string" title="string">` + slot (linha secundária e selos). Com `href`: `<a href wire:navigate>`.
  Sem `href` e com `wire:click` nos atributos: `<button type="button">`. Sem nenhum dos dois: `<div>`. Raiz com
  `data-link-card`; seta só quando interativo, com `data-link-card-chevron`. Título em `data-link-card-title`.

- [ ] **Step 1: Testes que falham**
  - `LinkCardTest::test_com_href_e_link_com_seta`: renderiza `<x-link-card href="/x" title="2026-000142">texto</x-link-card>`;
    raiz é `a` com `href="/x"` e `wire:navigate`, tem `data-link-card-chevron`, título "2026-000142".
  - `test_com_wire_click_e_botao_com_seta`: `wire:click="foo"` → raiz `button type="button"` com o `wire:click`, com seta.
  - `test_sem_destino_e_estatico_sem_seta`: raiz `div`, sem `data-link-card-chevron`.
  - `ContactCardCompactTest::test_anteriores_viram_cartoes_e_so_os_abriveis_tem_seta`: contato com um anterior encerrado
    do usuário (abrível → `a` para `historico`) e um de outro setor (não abrível); os dois com `data-link-card`, só o
    primeiro com `data-link-card-chevron`.
- [ ] **Step 2: Rodar e ver falhar** — `--filter="LinkCardTest|ContactCardCompactTest"`.
- [ ] **Step 3: Implementar** o componente com as medidas da tabela; no contact-card, cada `<li>` vira um `x-link-card` com
  os mesmos três destinos de hoje (`historico` com `wire:navigate`; `$parent.select(id)` como `wire:click`; `inbox` com
  `wire:navigate`) e o `title` = protocolo; o slot leva a linha "data · setor" e os selos/aviso "também tem ticket aberto
  no número…" que existem hoje. Título do bloco passa a "Atendimentos anteriores (n)". `x-history-link` fica.
- [ ] **Step 4: Rodar e ver passar** — o filtro acima e `--filter="TicketDetailsBlocksTest|InboxGoBackTest"`.
- [ ] **Step 5: Commit** — `feat: atendimentos anteriores em cartão com seta`

### Task 2: Painel de detalhes no arranjo do Pencil

**Files:**
- Modify: `resources/views/components/ticket-details.blade.php`, `resources/views/components/disclosure.blade.php`,
  `resources/views/livewire/contacts/contact-card.blade.php` (ramo compacto: bloco Contato e rodapé)
- Test: `tests/Feature/Livewire/TicketDetailsBlocksTest.php`

**Interfaces:**
- Consumes: `slaState()`, `sla_due_at`, `waiting_since` do `Ticket` e `BusinessHours::whenLabel()` — os mesmos que o
  cabeçalho da conversa já usa em `inbox.blade.php` (~linha 205).

- [ ] **Step 1: Testes que falham**
  - Inverter `test_painel_do_inbox_nao_tem_o_bloco_ticket_que_o_cabecalho_ja_mostra` →
    `test_painel_do_inbox_tem_o_bloco_ticket`: `details-ticket` presente na Inbox com Canal, Setor, Responsável, Aberto em;
    o cabeçalho segue com `header-line`.
  - `test_aviso_de_sla_no_painel_so_com_ticket_esperando`: ticket aguardando com `sla_due_at` → `data-testid="details-sla"`
    com "Responder até"; estourado → "SLA estourado"; encerrado ou sem `sla_due_at` → sem `details-sla`.
  - `test_historico_mantem_ticket_encerrado_e_sem_aviso_de_sla` (Histórico: Encerrado em, Motivo, sem `details-sla`).
  - `test_contato_em_linhas_rotuladas`: no compacto, rótulos "Nome", "Empresa" (com cargo), "E-mail" (quando houver);
    "Telefone" só no Histórico (o teste atual de telefone segue valendo).
  - `test_titulos_de_secao_em_caixa_alta`: o `summary` do `x-disclosure` tem `uppercase` (a classe é o requisito).
- [ ] **Step 2: Rodar e ver falhar** — `--filter=TicketDetailsBlocksTest`.
- [ ] **Step 3: Implementar**: o bloco Ticket deixa de ser só do `$readonly` (Encerrado em/Motivo/Resumo seguem só quando
  existem); o aviso de SLA vai no fim do bloco, só fora do `$readonly` e com a mesma condição do cabeçalho; título de
  seção do `x-disclosure` com as medidas da tabela; Contato em `dl` chave/valor. Rodapé (Conversar + Editar contato) fica
  como está. Atualizar o comentário de cabeçalho de `ticket-details.blade.php`.
- [ ] **Step 4: Rodar e ver passar** — `--filter="TicketDetailsBlocksTest|ContactCardCompactTest|ContactCardTest"` e
  o teste do Histórico que renderiza o painel (`--filter=History`).
- [ ] **Step 5: Commit** — `feat: painel de detalhes da Inbox no desenho do Pencil`

### Task 3: Cabeçalho da conversa

**Files:**
- Modify: `resources/views/livewire/inbox/inbox.blade.php` (cabeçalho, ~linhas 200-275)
- Test: `tests/Feature/Livewire/InboxHeaderTest.php`

- [ ] **Step 1: Testes que falham**
  - `test_transferir_e_botao_na_coluna_larga_e_item_do_menu_na_estreita`: com `canTransfer`, existe
    `data-testid="header-transfer"` (botão, `wire:click="openPanel('transfer')"`) e o item do `header-more-menu` continua; o
    botão tem a classe de container que o esconde abaixo de `@3xl` e o item a que o esconde de `@3xl` em diante (classes
    são o requisito: `hidden @3xl:inline-flex` e `@3xl:hidden`).
  - `test_menu_mais_some_na_coluna_larga_quando_so_tem_transferir`: sem `canReassign`, o gatilho do "…" tem `@3xl:hidden`.
  - `test_sla_vai_para_a_linha_de_baixo`: `header-sla` fica dentro do mesmo contêiner de `header-line`, antes dele.
- [ ] **Step 2: Rodar e ver falhar** — `--filter=InboxHeaderTest`.
- [ ] **Step 3: Implementar** na ordem da tabela de medidas; Transferir `variant` padrão (outline) com ícone
  `arrows-right-left`; avatar do cabeçalho `size="lg"` como hoje.
- [ ] **Step 4: Rodar e ver passar** — `--filter="InboxHeaderTest|InboxHeaderLineTest|InboxPanelSelectsTest|InboxTest"`.
- [ ] **Step 5: Commit** — `feat: cabeçalho da conversa no arranjo do Pencil`

### Task 4: Fila

**Files:**
- Modify: `resources/views/livewire/inbox/inbox.blade.php` (grupos e cartão, ~linhas 96-180)
- Test: `tests/Feature/Livewire/InboxQueueCardTest.php`

- [ ] **Step 1: Testes que falham**
  - `test_cartao_mostra_status_e_protocolo`: cada cartão tem `data-testid="card-status"` (um `x-status-badge` com o status
    do ticket) e `data-testid="card-protocol"` com o protocolo.
  - `test_cartao_selecionado_usa_muted`: o cartão do ticket aberto tem `bg-muted` (a classe é o requisito).
  - `test_titulo_do_grupo_em_caixa_alta`: o título "Aguardando · n" tem `uppercase`.
- [ ] **Step 2: Rodar e ver falhar** — `--filter=InboxQueueCardTest`.
- [ ] **Step 3: Implementar**: linha nova embaixo da prévia (status + protocolo), `hover:bg-muted` e selecionado `bg-muted`,
  títulos de grupo com as medidas da tabela. A coluna da direita (tempo, setor, atendente, não lidas) fica.
- [ ] **Step 4: Rodar e ver passar** — `--filter="InboxQueue|InboxPendingTest|InboxViewSwitchTest"`.
- [ ] **Step 5: Commit** — `feat: cartão da fila com status e protocolo`

### Task 5: Verificação da fatia (controlador)

- [ ] Portão 7 do `CLAUDE.md`, lendo o código de saída de cada comando; suíte com Redis num contêiner próprio.
- [ ] Conferência visual (`conferente-visual`, chrome-devtools): 720p, 1080p, 2K e 390 px com toque, claro e escuro —
  painel de detalhes (2K aberto; abaixo, na folha), anteriores com e sem seta, aviso de SLA, cabeçalho em coluna larga e
  estreita (Transferir botão x menu), cartões da fila nas abas Todos/Meus/Mais, Histórico (painel somente leitura).
