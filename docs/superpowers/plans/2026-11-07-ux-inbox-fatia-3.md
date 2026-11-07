# UX fatia 3 (Inbox) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Coluna de detalhes em blocos recolhíveis com 5 anteriores e link ao Histórico filtrado por contato; card da fila com
atendente, setor, etiquetas, espera e ponto de SLA; hora e ticks no mesmo canto em todas as bolhas.

**Architecture:** Só Blade + Livewire existentes. Um componente Blade novo (`x-disclosure`, `<details>` nativo) serve à coluna
de detalhes e ao cartão do contato. Um filtro `contactId` entra no `HistorySearch`/`History\Index`. Um helper `WaitLabel`
formata a espera. O partial `message-time` ganha o modo `overlay`.

**Tech Stack:** Laravel 13, Livewire 4, Flux (grátis), Tailwind 4, Alpine, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-07-ux-inbox-fatia-3-design.md`

## Global Constraints

- Só tokens de cor (`ink*`, `canvas`, `surface`, `line`, `accent*`, `warn`, `danger`, `ok`, `bot`); vermelho só para SLA estourado/erro. `ThemeTokensTest` precisa continuar verde.
- Sem dependência nova; sem Flux Pro.
- Card da fila: no máximo 3 linhas; linha 3 some quando vazia.
- Nada muda em visibilidade (`visibleTo`, `inHistoryOf`, `readableBy`), portões `can:*`, SLA ou eventos de tempo real.
- Buscas em `resources/views` por `grep` no Bash (CodeGraph não indexa Blade).
- Ciclos rápidos: `php artisan test --parallel --filter=...`; portão 7 com Redis em série (CLAUDE.md).
- Um commit por tarefa, em português, prefixo `feat:`/`test:`/`fix:`, com `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. `contactId` de outro contato cujo ticket o usuário não vê → Histórico vazio, nada vaza (teste na Tarefa 1).
2. Re-render do Livewire (tempo real `ticket.updated`) não reabre/fecha um bloco que o usuário mexeu → `wire:ignore.self` no `<details>` (verificação visual na Tarefa 2; atributo checado em teste).
3. Etiqueta com cor clara/escura ilegível no tema escuro → etiqueta vira ponto colorido + nome em `text-ink-2` (Tarefa 5).
4. Ticket sem atendente, sem etiqueta e sem espera → card de 2 linhas, sem linha 3 vazia (teste na Tarefa 5).
5. Foto sem legenda com hora sobreposta → legível nos dois temas e não cobre o clique de ampliar (verificação visual na Tarefa 6).

---

### Task 1: Filtro `contactId` no Histórico

**Files:**
- Modify: `app/Services/Inbox/HistorySearch.php:26` (novo parâmetro final `?int $contactId = null`; `->when($contactId !== null, fn ($b) => $b->where('contact_id', $contactId))`)
- Modify: `app/Livewire/History/Index.php` (`#[Url] public ?int $contactId = null;`, incluir `'contactId'` na lista do `updated()`, passar ao `query()`; passar `'filteredContact' => $this->contactId ? Contact::find($this->contactId)?->label() : null` à view)
- Modify: `resources/views/livewire/history/index.blade.php` (quando `$filteredContact`: `flux:badge` "Contato: {nome}" com botão `wire:click="$set('contactId', null)"`, `aria-label="Remover filtro de contato"`)
- Test: `tests/Feature/History/HistorySearchTest.php`, `tests/Feature/History/HistoryContactFilterTest.php` (novo)

**Interfaces:**
- Produces: rota `route('historico', ['contactId' => $id])` filtra pelo contato.

- [ ] **Step 1: Testes que falham**
  - `HistorySearchTest::test_filtra_pelo_contato`: dois contatos com encerrados; `query($admin, contactId: $a->id)` devolve só os de A.
  - `HistoryContactFilterTest::test_url_com_contact_id_mostra_so_o_contato_e_o_selo`: `Livewire::withQueryParams(['contactId' => $a->id])->test(Index::class)` → `assertSee($a->label())` no selo, `assertDontSee` protocolo de B.
  - `HistoryContactFilterTest::test_contato_de_ticket_que_o_atendente_nao_ve_fica_vazio`: atendente sem o setor do ticket de A → lista sem o protocolo.
  - `HistoryContactFilterTest::test_remover_o_filtro_volta_a_lista_toda`: `->call('$set', 'contactId', null)` → vê protocolos de A e B.
- [ ] **Step 2:** `php artisan test --parallel --filter='HistorySearchTest|HistoryContactFilterTest'` → FAIL (parâmetro/propriedade inexistente).
- [ ] **Step 3:** Implementar conforme "Files". Id não inteiro na URL: o `?int` do Livewire já recusa; não tratar à mão.
- [ ] **Step 4:** Mesmo comando → PASS.
- [ ] **Step 5:** Commit `feat: Histórico filtra por contato (contactId)`.

### Task 2: `x-disclosure` e blocos da coluna de detalhes

**Files:**
- Create: `resources/views/components/disclosure.blade.php`
- Modify: `resources/views/components/ticket-details.blade.php` (bloco "Ticket" aberto envolvendo o que está entre o `<h2>Ticket` e o primeiro `<hr>`; bloco "Linha do tempo" **fechado**; trocar os `<hr>` pelo separador do componente)
- Test: `tests/Feature/Livewire/TicketDetailsBlocksTest.php` (novo; renderiza o Inbox com ticket selecionado)

**Interfaces:**
- Produces: `<x-disclosure title="Ticket" :open="true" testid="details-ticket">…</x-disclosure>`. Props: `title` (string), `open` (bool, padrão `true`), `testid` (string). Renderiza
  `<details wire:ignore.self data-testid="{testid}" @if($open) open @endif class="group border-t border-line py-3 first:border-t-0">`
  com `<summary class="flex cursor-pointer list-none items-center justify-between font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase [&::-webkit-details-marker]:hidden">{title}<flux:icon.chevron-down variant="micro" class="transition-transform duration-200 group-open:rotate-180 motion-reduce:transition-none" /></summary>`
  e o slot num `<div class="mt-2">`. Alvo do `summary` com no mínimo 44px de altura em toque (`min-h-11` ou `py`).

- [ ] **Step 1: Testes que falham** (`TicketDetailsBlocksTest`)
  - `test_bloco_do_ticket_vem_aberto_e_linha_do_tempo_fechada`: `assertSeeHtml('data-testid="details-ticket"')`; regex no HTML: o `<details` de `details-ticket` tem `open`, o de `details-timeline` não.
  - `test_blocos_ignoram_o_morph_do_livewire`: `assertSeeHtml('wire:ignore.self')` nos dois.
- [ ] **Step 2:** `--filter=TicketDetailsBlocksTest` → FAIL.
- [ ] **Step 3:** Criar o componente e aplicar no `ticket-details`.
- [ ] **Step 4:** PASS; rodar também `--filter='Inbox|History'` (o Histórico usa o mesmo painel) → PASS.
- [ ] **Step 5:** Commit `feat: blocos recolhíveis na coluna de detalhes`.

### Task 3: Cartão do contato com 5 anteriores e link ao Histórico

**Files:**
- Modify: `app/Livewire/Contacts/ContactCard.php:21,120-128` (`HISTORY_LIMIT = 5`; buscar `limit(self::HISTORY_LIMIT + 1)`, `$hasMore = count > 5`, `$history = take(5)`; `'historyTotal'` = `count()` da mesma consulta sem limite; passar `hasMore`, `historyTotal`)
- Modify: `resources/views/livewire/contacts/contact-card.blade.php` (só no ramo compacto: "Cliente"+"Observações" num `x-disclosure title="Contato" testid="details-contact"`; "Atendimentos anteriores" num `x-disclosure :title="'Anteriores ('.$historyTotal.')'" :open="$historyTotal > 0" testid="details-history"`; ao fim da lista, se `$hasMore`, link `route('historico', ['contactId' => $contact->id])` "Ver todos no Histórico", `wire:navigate`. Ramo completo (Contatos): sem disclosure, mesmo limite e mesmo link.)
- Test: `tests/Feature/Livewire/ContactCardCompactTest.php`, `tests/Feature/Livewire/ContactCardTest.php`

**Interfaces:**
- Consumes: `x-disclosure` (Task 2); rota com `contactId` (Task 1).

- [ ] **Step 1: Testes que falham**
  - `ContactCardCompactTest::test_mostra_so_os_5_ultimos_e_o_link_ao_historico`: contato com 7 tickets anteriores → 5 protocolos mais novos visíveis, os 2 mais antigos não; `assertSee('Anteriores (7)')`; `assertSeeHtml` do href com `contactId=`.
  - `ContactCardCompactTest::test_sem_link_com_5_ou_menos`: 5 anteriores → `assertDontSee('Ver todos no Histórico')`.
  - `ContactCardCompactTest::test_sem_anteriores_o_bloco_vem_fechado`: `details-history` sem `open`.
  - `ContactCardTest::test_ficha_completa_tambem_limita_a_5_sem_blocos`: modo não compacto, 7 tickets → 5 visíveis, sem `data-testid="details-contact"`.
- [ ] **Step 2:** `--filter='ContactCard'` → FAIL.
- [ ] **Step 3:** Implementar. Atualizar testes existentes que contavam até 10, se algum quebrar (ler o motivo antes).
- [ ] **Step 4:** `--filter='ContactCard|Contacts'` → PASS.
- [ ] **Step 5:** Commit `feat: cartão do contato com 5 anteriores e link ao Histórico`.

### Task 4: `WaitLabel`

**Files:**
- Create: `app/Support/WaitLabel.php` — `final class WaitLabel { public static function since(CarbonInterface $since, CarbonInterface $now): string }`
- Test: `tests/Unit/WaitLabelTest.php`

- [ ] **Step 1: Teste que falha** `test_formata_a_espera`: com `$now` fixo: 30 s → `'agora'`; 1 min → `'há 1 min'`; 59 min → `'há 59 min'`; 60 min → `'há 1 h'`; 23 h 59 → `'há 23 h'`; 24 h → `'há 1 d'`; `$since` no futuro → `'agora'`.
- [ ] **Step 2:** `--filter=WaitLabelTest` → FAIL.
- [ ] **Step 3:** Implementar com `diffInMinutes` inteiro (truncado).
- [ ] **Step 4:** PASS.
- [ ] **Step 5:** Commit `feat: rótulo de espera (WaitLabel)`.

### Task 5: Card da fila

**Files:**
- Modify: `app/Livewire/Inbox/Inbox.php:371` (`with(['contact', 'channel', 'tags', 'assignee', 'sector'])`) e `render()` (`'showSector' => Sector::query()->receivingTickets()->count() >= 2`)
- Modify: `resources/views/livewire/inbox/inbox.blade.php:96-131` (só os grupos Pendente/Aguardando/Em atendimento; "Encerrados" intocado)
- Test: `tests/Feature/Livewire/InboxQueueCardTest.php` (novo)

**Interfaces:**
- Consumes: `WaitLabel::since($item->waiting_since, now())` (Task 4); `$item->slaState()`.

Layout (spec, seção 2):
- Linha 1 e 2: como hoje.
- Linha 3 (`data-testid="card-meta"`, `flex items-center gap-2 text-[11px] text-ink-3`), só renderizada se houver ao menos um item:
  atendente (`flux:avatar size="xs" :name` + primeiro nome, `data-testid="card-assignee"`); setor se `$showSector` (`data-testid="card-sector"`);
  canal se `$showChannel` (substitui a linha de canal atual, que deixa de existir); até 2 etiquetas como ponto `size-2 rounded-full` com
  `style="background-color: {color}"` + nome em `text-ink-2`, e `+N` com `title` listando o resto (`data-testid="card-tags-more"`);
  à direita (`ms-auto`) a espera `WaitLabel` se `waiting_since` não nulo (`data-testid="card-wait"`).
- Ponto de SLA: selo do `flux:avatar` da linha 1 (conferir a API em `vendor/livewire/flux/stubs/resources/views/flux/avatar/index.blade.php`; usar o slot `badge` com `<span class="block size-2.5 rounded-full {bg-warn|bg-danger} ring-2 ring-surface">`), mais `<span class="sr-only">SLA estourado|Perto de estourar</span>` e `title`. Remover a pílula de SLA e a linha "Responder até" do card (o painel de detalhes já mostra).

- [ ] **Step 1: Testes que falham** (`InboxQueueCardTest`, admin vendo tudo)
  - `test_card_mostra_atendente_pelo_primeiro_nome`: ticket em atendimento de "Marina Souza" → `card-assignee` contém "Marina" e não "Souza".
  - `test_sem_atendente_etiqueta_nem_espera_nao_tem_linha_3`: ticket pendente sem tags, `waiting_since` nulo, um só setor → `assertDontSeeHtml('data-testid="card-meta"')`.
  - `test_setor_some_com_um_so_setor_e_aparece_com_dois`.
  - `test_mostra_duas_etiquetas_e_mais_n`: 4 etiquetas → 2 nomes visíveis e `+2`.
  - `test_espera_aparece_com_waiting_since`: `waiting_since = now()->subMinutes(12)` → `card-wait` contém "há 12 min" (relógio fixo com `travel` permitido aqui: não envolve cache).
  - `test_sla_estourado_vira_ponto_com_texto_para_leitor_de_tela`: `sla_due_at` no passado → `assertSee('SLA estourado')` dentro de `sr-only`, `assertSeeHtml('bg-danger')`, `assertDontSee('Responder até')`.
- [ ] **Step 2:** `--filter=InboxQueueCardTest` → FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** `--filter='Inbox|Sidebar|ThemeTokens'` → PASS (ajustar testes antigos que procuravam a pílula de SLA ou o texto do canal; ler cada falha antes).
- [ ] **Step 5:** Commit `feat: card da fila com atendente, setor, etiquetas, espera e ponto de SLA`.

### Task 6: Hora e ticks no mesmo canto em todas as bolhas

**Files:**
- Modify: `resources/views/livewire/inbox/message-time.blade.php` (parâmetro `$mode`: `'inline'` | `'below'` | `'overlay'`, substituindo `$inline`; `overlay` = `absolute end-1.5 bottom-1.5 rounded px-1.5 py-0.5 bg-black/55 text-white`, sem `text-ink-3`)
- Modify: `resources/views/livewire/inbox/transcript.blade.php:35,66,197` (`$timeMode`: `overlay` se foto/vídeo sem legenda; `inline` se há texto (com ou sem mídia) e o tipo não é localização/contato; senão `below`)
- Modify: `resources/views/livewire/inbox/media.blade.php` (contêiner de foto/vídeo `relative`; incluir `message-time` com `overlay` quando o modo for esse)
- Test: `tests/Feature/Livewire/InboxTranscriptTest.php`, `tests/Feature/Livewire/InboxMediaTest.php`

**Interfaces:**
- Produces: `data-time-mode="{inline|below|overlay}"` no `<span data-testid="message-time">`.

- [ ] **Step 1: Testes que falham**
  - `InboxTranscriptTest::test_texto_tem_hora_inline`.
  - `InboxMediaTest::test_foto_com_legenda_tem_hora_inline_no_fim_da_legenda`.
  - `InboxMediaTest::test_foto_sem_legenda_tem_hora_sobre_a_imagem`.
  - `InboxMediaTest::test_audio_e_documento_tem_hora_abaixo`.
  - `InboxTranscriptTest::test_recebida_nao_tem_ticks`: mensagem IN → sem `aria-label` de tick.
  Cada um conta exatamente um `data-testid="message-time"` por bolha.
- [ ] **Step 2:** `--filter='InboxTranscriptTest|InboxMediaTest'` → FAIL.
- [ ] **Step 3:** Implementar; manter "editada" junto da hora.
- [ ] **Step 4:** `--filter='Inbox|History'` → PASS.
- [ ] **Step 5:** Commit `feat: hora e ticks no canto da bolha em todos os tipos`.

### Task 7: Verificação visual

- [ ] Subir o app local (receita da memória: `php artisan serve --port=8765`, `DB_DATABASE=dimetal_visual`, SESSION/CACHE=file, QUEUE=sync; dados por factories).
- [ ] Conferir nos temas claro e escuro, em 1280px e 340px: card com 2 e 3 linhas, ponto de SLA, blocos abrem/fecham e continuam como deixados após um evento de tempo real, "Ver todos" abre o Histórico filtrado com o selo, hora sobre foto sem legenda legível.
- [ ] Anotar no ledger o que viu; corrigir regressões com teste antes (TDD).
