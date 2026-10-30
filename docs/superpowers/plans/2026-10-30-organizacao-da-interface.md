# Organização da interface Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (inline, por regra do `AGENTS.md`). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Menu enxuto, painel de detalhes legível (Inbox e Histórico) e linha do tempo em frases, sem mudar o funcionamento do app.

**Architecture:** Estende o que existe: `ContactCard` ganha modo `compact` (resumo + botão Editar); a classe `TicketTimeline` monta as frases dos eventos com nomes de setor e de usuário buscados em lote; um componente Blade `ticket-details` serve ao Inbox e ao Histórico; `SidebarState` alimenta contadores e aviso do WhatsApp no menu.

**Tech Stack:** Laravel 13, Livewire 4, Flux, Alpine, Tailwind, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-30-organizacao-da-interface-design.md`

## Global Constraints
- Só apresentação: nenhum evento gravado, permissão ou regra de SLA muda. O que exigir mudança de funcionamento vai para "PR futuro" no spec.
- **Ordem pedida pelo dono:** implementação primeiro, **testes no fim** (Task 6), antes do PR. Pint, Larastan, PHPMD e a suíte inteira (`QUEUE_CONNECTION=database`) antes de abrir o PR.
- Textos em português; permissões iguais às atuais (`can:supervisor`, `can:admin`, `visibleTo`).
- Commits em português com prefixo (`feat:`, `fix:`), um por tarefa. Branch: `feat/menu-e-componentes-de-tela`.
- Antes de editar um arquivo, consultar o raio de impacto no CodeGraph.

## Review Focus
- Setor ou usuário apagado no payload do evento: "setor removido"/"usuário removido", nunca erro. Teste na Task 6.
- Evento de tipo desconhecido: a tela mostra o conteúdo como veio (a auditoria não esconde dado); tipo conhecido nunca mostra JSON.
- Histórico aberto por URL (`?ticketId=`) com ticket que o usuário não pode ver ou que não está encerrado: negado como hoje.
- Atendente vê contadores só do que `visibleTo` permite.
- `ContactCard` em Contatos continua com o formulário aberto (`compact` falso).

---

### Task 1: Frases da linha do tempo (`TicketTimeline`)

**Files:**
- Create: `app/Support/TicketTimeline.php`
- Modify: `app/Livewire/Audit/Index.php`, `resources/views/livewire/audit/index.blade.php`, `app/Livewire/History/Index.php`, `resources/views/livewire/history/index.blade.php`

**Interfaces:**
- Produces: `final class TicketTimeline` com `__construct(Collection $events)` (carrega `Sector` e `User` pelos ids de `from_sector`, `to_sector`, `from_user`, `to_user` em duas consultas), `detail(TicketEvent $event): string`, `sentence(TicketEvent $event): string` e `static changes(Collection $events): Collection` (descarta `message_in`, `message_out`, `after_hours_reply`, `media_purged`).
- Consumes: `TicketEventText::label(string)`, `TicketEventText::detail(TicketEvent)` (usado para `closed` e `sla_breached`), `ClosedReason::label()`.

Payloads reais: `transferred` = `from_sector?`, `to_sector`, `from_user?`, `note?`; `reassigned`/`taken_over` = `from_user?`, `to_user`; `closed` = `reason`, `outcome?`, `summary?`; `merged` = `into_ticket_id`.

- [ ] **Step 1:** Criar `TicketTimeline`: `detail` devolve texto sem o nome do ator ("de Comercial para Financeiro · nota", "para Beto", "esperando desde …, limite …", "ticket 2026-000005" para `merged`) e `''` para os demais; `sentence` = "{ator ?? 'Sistema'} {verbo} {detail}" com verbos por tipo (assumiu, transferiu, reatribuiu, tomou, encerrou, abriu o ticket, SLA estourado).
- [ ] **Step 2:** Auditoria: coluna Detalhe usa `(new TicketTimeline($events->getCollection()))->detail($event)` calculado uma vez no `render()`; mantém todos os eventos.
- [ ] **Step 3:** Histórico: `events` passam por `TicketTimeline::changes`; a view lista `sentence` com a hora, sem JSON.
- [ ] **Step 4:** Commit `feat: linha do tempo em frases, com nomes de setor e de pessoa`.

### Task 2: Painel de detalhes e ficha em modo resumo

**Files:**
- Create: `resources/views/components/ticket-details.blade.php`
- Modify: `app/Livewire/Contacts/ContactCard.php`, `resources/views/livewire/contacts/contact-card.blade.php`, `resources/views/livewire/inbox/inbox.blade.php`, `resources/views/livewire/history/index.blade.php`, `app/Livewire/History/Index.php`

**Interfaces:**
- Produces: `<x-ticket-details :ticket="$ticket" :tz="$tz" :readonly="bool" :events="$events|null" :closer="..." />`; `ContactCard` com `public bool $compact = false` e `public bool $editing = false` (método `toggleEdit(): void`); `History\Index::$ticketId` com `#[Url]`.
- Consumes: Task 1 (`TicketTimeline`) no Histórico; `route('historico', ['ticketId' => $id])`.

- [ ] **Step 1:** `ContactCard`: `mount(int $contactId, bool $compact = false)`; no `render`, o formulário só aparece se `!$compact || $editing`; no resumo: nome, empresa, telefone, observações, tickets anteriores (data, setor, motivo; cada um um link ao Histórico) e botão Editar/Cancelar; o aviso do Google só se `$google['error']` (compact), detalhe completo dentro do Editar. Contatos mantém `compact=false`.
- [ ] **Step 2:** Componente `ticket-details`: ordem cliente → dados do ticket (setor, responsável, aberto em, SLA ou motivo de encerramento; Canal aqui, sem repetir protocolo e telefone) → ficha/observações → (Histórico) linha do tempo. Recolhível com Alpine `x-data` e `localStorage('ticketDetailsOpen')`, botão com `aria-expanded`/`aria-controls`.
- [ ] **Step 3:** Inbox e Histórico trocam seus `<aside>` pelo componente; botão **Detalhes** (`lg:hidden`) no cabeçalho da conversa abre o painel por cima da conversa, com botão voltar.
- [ ] **Step 4:** `History\Index`: `#[Url] public ?int $ticketId`; `select()` mantém `authorize('view')` e `abort_unless(ENCERRADO)`; `render()` já usa `inHistoryOf` ao carregar (cobre ticket não permitido pela URL: some em vez de abrir).
- [ ] **Step 5:** Commit `feat: painel de detalhes legivel com ficha em resumo e Editar`.

### Task 3: Menu lateral, contadores e abas

**Files:**
- Create: `app/Support/SidebarState.php` (já escrito: `counts(User): array{waiting:int, breached:int}`, `whatsappDown(): bool`)
- Modify: `resources/views/layouts/app/sidebar.blade.php`, `routes/web.php` (já: `Route::redirect('/', '/inbox')`), `app/Livewire/Inbox/Inbox.php`, `resources/views/livewire/inbox/inbox.blade.php` (abas)

- [ ] **Step 1:** Sidebar: grupos Atendimento (Atendimento com contadores, Contatos, Histórico), Gestão (`@can('supervisor')` Relatórios; `@can('admin')` Auditoria), Configurações com subgrupos Operação (Canais, Setores, Horário, Respostas rápidas), Pessoas e empresa (Usuários, Empresa), Dados e integrações (Google Contatos, Retenção com ícone `trash`/`archive-box-x-mark`). Mantém as condições `@can` atuais por item. Remove "Platform", "Meus tickets", Repository, Documentation. Rodapé: aviso "WhatsApp desconectado" se `whatsappDown()`.
- [ ] **Step 2:** Abas do Inbox: contador de Aguardando e Meus (mesma regra `visibleTo`), calculados no `render()` com uma consulta agregada.
- [ ] **Step 3:** Commit `feat: menu lateral reorganizado, contadores e aviso de WhatsApp desconectado`.

### Task 4: Cabeçalho de página e larguras

**Files:**
- Create: `resources/views/components/page-header.blade.php`
- Modify: views de Relatórios, Contatos, Auditoria e `livewire/settings/*.blade.php`

**Interfaces:** `<x-page-header title="..." subtitle="..."> {{ slot de ação }} </x-page-header>` (renderiza `flux:heading size="xl" level="1"` + `flux:subheading`).

- [ ] **Step 1:** Aplicar o componente e padronizar `max-w-3xl` (formulários: Empresa, Google Contatos, Respostas rápidas, Retenção, Setores) e `max-w-6xl` (Relatórios, Contatos, Auditoria, Usuários).
- [ ] **Step 2:** Commit `feat: cabecalho de pagina unico e larguras padronizadas`.

### Task 5: Revisão (`requesting-code-review`)
- [ ] Rodar Pint e olhar a diff inteira pela lista Review Focus; pedir revisão ao advisor (não a subagente) e tratar o que vier por severidade.

### Task 6: Testes (no fim, antes do PR) e verificação
**Files:** Create `tests/Feature/Layout/SidebarTest.php` (já escrito; ajustar), `tests/Feature/Timeline/TicketTimelineTest.php`, `tests/Feature/Livewire/ContactCardCompactTest.php`, `tests/Feature/History/HistoryTimelineTest.php`; Modify `tests/Feature/ExampleTest.php` (já).

- [ ] **Step 1:** `TicketTimelineTest`: `test_transferencia_vira_frase_com_nomes`, `test_setor_ou_usuario_apagado_vira_removido`, `test_payload_vazio_ou_tipo_desconhecido_nao_mostra_json`, `test_changes_descarta_eventos_de_mensagem`.
- [ ] **Step 2:** `HistoryTimelineTest`: `test_historico_nao_lista_mensagens`, `test_auditoria_lista_todos_os_eventos_sem_json`, `test_ticket_pela_url_respeita_a_permissao`.
- [ ] **Step 3:** `ContactCardCompactTest`: `test_inbox_mostra_resumo_e_esconde_o_formulario`, `test_editar_abre_o_formulario`, `test_aviso_do_google_so_com_erro`, `test_contatos_continua_com_formulario_aberto`.
- [ ] **Step 4:** `SidebarTest` (já escrito): menu por papel, contadores, aviso do WhatsApp, `/` → `/inbox`.
- [ ] **Step 5:** Run: `QUEUE_CONNECTION=database php artisan test` → Expected: tudo passa; `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse --memory-limit=1G`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml` sem violações.
- [ ] **Step 6:** Commit `test: cobertura da organizacao da interface`; push; PR (corpo sem citar referências de mercado); esperar o CI; merge só depois dos 4 checks verdes (autorizado pelo dono neste trabalho).

## Self-review
- Cobertura do spec: decisões 1–7, menu, abas com contador, painel, linha do tempo, Auditoria, Histórico por URL, componentes de página, lista de PR futuro. Dividir o Inbox em subcomponentes ficou fora, como no spec.
- Nomes consistentes: `TicketTimeline::detail/sentence/changes`, `SidebarState::counts/whatsappDown`, `ContactCard::$compact/$editing`.
- Proporção: plano curto; corpos das classes ficam para a execução.
