# Histórico: Conversar e linha do tempo legível Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, regra do dono). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Dar ao painel de detalhes do Histórico o botão "Conversar" (ticket aberto vai direto ao ticket; sem ticket abre a janela Nova conversa) e uma linha do tempo sem JSON bruto, com os eventos do bot em português.

**Architecture:** A regra do "Conversar" fica em `NewConversation::open()` (um lugar, todas as entradas). O Histórico só passa a carregar a janela e a mostrar o botão no cartão em modo somente leitura. A linha do tempo ganha frases novas em `TicketEventText`/`TicketTimeline`, o JSON some do `detail`, e o Histórico filtra tipos sem rótulo.

**Tech Stack:** Laravel 13, Livewire 4, Flux 2, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-13-historico-conversar-e-linha-do-tempo-design.md`

## Global Constraints

- Textos exatos: `bot_started` → "Bot iniciou o atendimento"; `bot_abandoned` → "Equipe respondeu pelo celular; a triagem do bot acabou"; `bot_handoff` → "Bot encaminhou para {setor}" (sem setor: "Fila geral"; setor apagado: "setor removido") mais o motivo entre parênteses: `sair` "cliente pediu atendente", `erros` "respostas inválidas", `timeout` "sem resposta do cliente", `sem_workflow` "sem automação ativa"; `fluxo` e motivo desconhecido ou ausente: sem sufixo.
- Ticket aberto = `Ticket::open()` (status diferente de encerrado), visível ao usuário = `readableBy($user)`; vários: o de `opened_at` mais recente.
- Nada de JSON de payload em texto mostrado ao usuário.
- Commits em português com prefixo, trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; editar com `Edit`/`Write`; sem Python em heredoc.
- Testes: `docker start pg-test` antes; `docker stop pg-test` no fim. RED visto antes de cada código.
- Blade se busca com `grep` pelo Bash; código PHP e testes com `codegraph_explore`.

## Review Focus

- Contato com ticket aberto em dois canais: vai ao mais recente.
- Ticket aberto que o usuário não vê (outro setor): abre a janela, sem redirecionar.
- Contato só com `lid` (sem telefone): botão desabilitado, nada de redirect.
- Evento de tipo desconhecido com payload JSON: o Histórico não mostra nem o tipo nem o JSON; a Auditoria mostra o tipo, sem JSON.
- `bot_handoff` sem payload, com `sector_id` nulo ou de setor apagado: frase completa, sem erro.

---

### Task 1: Linha do tempo legível

**Files:**
- Modify: `app/Support/TicketEventText.php`, `app/Support/TicketTimeline.php`
- Test: `tests/Feature/Timeline/TicketTimelineTest.php`, `tests/Feature/History/HistoryTimelineTest.php`

**Interfaces:**
- Produces: `TicketEventText::TYPES` ganha `bot_started`, `bot_abandoned`, `bot_handoff`; `TicketEventText::detail()` devolve `''` (e não JSON) para tipo sem formato próprio; `TicketTimeline::changes()` também rejeita tipo fora de `TicketEventText::TYPES`; `TicketTimeline::sentence()` devolve a frase pronta de `bot_handoff`.

- [ ] **Step 1: Testes que falham.** Em `TicketTimelineTest` (siga o helper de criação de evento do arquivo): `test_bot_started_vira_frase_sem_json` (sentence === "Bot iniciou o atendimento", sem `workflow_version_id`); `test_bot_abandoned_vira_frase` (frase exata acima); `test_bot_handoff_diz_o_setor_e_o_motivo` cobrindo `fluxo` + setor Comercial → "Bot encaminhou para Comercial"; `sair` → "… (cliente pediu atendente)"; `erros`, `timeout`, `sem_workflow` com os textos das Global Constraints; `sector_id` nulo → "Bot encaminhou para Fila geral"; setor inexistente → "setor removido"; payload ausente e motivo desconhecido → sem sufixo e sem erro; `test_tipo_sem_formato_proprio_nao_despeja_json` (`TicketEventText::detail` de `algo_novo` com payload `['x' => 1]` === `''`). Em `HistoryTimelineTest`: `test_historico_mostra_o_bot_em_portugues_e_esconde_tipo_desconhecido` (eventos `bot_started` e `algo_novo` → vê "Bot iniciou o atendimento"; não vê `algo_novo` nem `workflow_version_id`) e `test_auditoria_mostra_tipo_desconhecido_sem_json` (`AuditIndex` vê `algo_novo`, não vê o JSON).
- [ ] **Step 2: Rodar e ver falhar.** Run: `docker start pg-test; php artisan test tests/Feature/Timeline/TicketTimelineTest.php tests/Feature/History/HistoryTimelineTest.php`. Expected: FAIL nos testes novos.
- [ ] **Step 3: Implementar.** `TicketEventText::TYPES`: as três chaves com rótulos "Bot iniciou o atendimento", "Equipe respondeu pelo celular; a triagem do bot acabou", "Bot encaminhou"; a última linha de `TicketEventText::detail()` passa a `return ''`. `TicketTimeline::changes()`: além de `CONVERSATION`, rejeita `! array_key_exists($event->type, TicketEventText::TYPES)`. `TicketTimeline::sentence()`: para `bot_handoff` devolve `private function botHandoff(TicketEvent $event): string` ("Bot encaminhou para {$this->sector($payload['sector_id'] ?? null)}" + sufixo do motivo por um mapa `HANDOFF_REASONS` com os 4 textos; payload não array vira `[]`).
- [ ] **Step 4: Rodar e ver passar**, mais os consumidores de `TicketEventText::detail`: `php artisan test tests/Feature/Timeline tests/Feature/History tests/Feature/Sla/SlaEventTextTest.php tests/Feature/Inbox/NumberExistsTest.php tests/Feature/Livewire/AuditPageTest.php`. Expected: PASS. Teste existente que esperava JSON no texto: atualizar para a nova regra e ledgar um `Ruling:`.
- [ ] **Step 5: Commit** `feat: linha do tempo do ticket sem JSON bruto e com os eventos do bot em português`.

### Task 2: "Conversar" vai direto ao ticket aberto

**Files:**
- Modify: `app/Livewire/Inbox/NewConversation.php` (método `open`)
- Test: `tests/Feature/Livewire/NewConversationModalTest.php`

**Interfaces:**
- Consumes: `Ticket::open()`, `Ticket::readableBy(User)`, `NewConversation::flashExisting(Ticket, Contact)` (existentes).
- Produces: `open(?int $contactId)` com `$contactId`: se há ticket aberto legível do contato, chama `flashExisting` e `redirectRoute('inbox', ['ticket' => $id])` e retorna sem abrir a janela; senão segue como hoje.

- [ ] **Step 1: Testes que falham.** `test_contato_com_ticket_aberto_visivel_vai_direto_ao_ticket` (ticket aguardando no setor do atendente; `dispatch('start-conversation', contactId:)` → `assertRedirect(route('inbox', ['ticket' => $id]))`, `assertSet('contactId', null)`, `assertNotDispatched('modal-show')`); `test_com_ticket_aberto_em_dois_canais_vai_ao_mais_recente`; `test_ticket_aberto_de_outro_setor_abre_a_janela` (ticket em atendimento de setor que o atendente não tem: `assertDispatched('modal-show', name: 'new-conversation')`, sem redirect); `test_ticket_encerrado_nao_desvia_para_o_inbox` (abre a janela).
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Livewire/NewConversationModalTest.php`. Expected: FAIL nos 4 novos.
- [ ] **Step 3: Implementar** em `open()`: antes de `$this->pick($contactId)`, `Ticket::query()->open()->readableBy($this->user())->where('contact_id', $contactId)->orderByDesc('opened_at')->orderByDesc('id')->first()`; achou: `flashExisting($ticket, Contact::query()->findOrFail($contactId))`, `redirectRoute`, `return`.
- [ ] **Step 4: Rodar tudo do arquivo.** Os testes antigos que chegam ao ramo "já existe ticket aberto" por `dispatch('start-conversation', contactId:)` (`test_ticket_aberto_de_outro_atendente_leva_o_aviso_e_nao_o_texto`, `test_ticket_aberto_do_proprio_atendente_leva_o_texto_ao_campo`) vão falhar porque agora a janela nem abre: trocar nesses testes `dispatch('start-conversation', contactId: X)` por `call('pick', X)` (o ramo do `submit` segue valendo para a corrida em que o ticket abre depois de a janela abrir) e ledgar `Ruling:`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: Conversar vai direto ao ticket aberto do contato`.

### Task 3: Botão no painel do Histórico

**Files:**
- Modify: `resources/views/livewire/contacts/contact-card.blade.php` (rodapé do cartão compacto, linhas ~74-86), `resources/views/livewire/history/index.blade.php`
- Test: `tests/Feature/Contacts/ChatButtonsTest.php`, `tests/Feature/History/HistoryPageTest.php`

**Interfaces:**
- Consumes: `canChat` do `ContactCard::render` (ticket atual encerrado ou ausente), partial `livewire.contacts.chat-button`.
- Produces: o rodapé `data-testid="contact-actions"` do cartão compacto aparece quando `$canChat || ! $readonly`; em `readonly` só traz Conversar (sem "Editar contato"); a página do Histórico carrega `<livewire:inbox.new-conversation />` dentro da raiz.

- [ ] **Step 1: Testes que falham.** Em `HistoryPageTest`: `test_painel_de_detalhes_tem_conversar_e_a_janela` (`call('select', $ticket->id)` → `assertSeeHtml("\$dispatch('start-conversation', { contactId: {$contact->id} })")`, `assertSeeLivewire('inbox.new-conversation')`, `assertDontSee('Editar contato')`). Em `ChatButtonsTest`, o teste `test_ficha_compacta_e_completa_tem_conversar_mas_o_historico_nao` muda de nome para `…_e_o_historico_tambem` e a 3ª asserção (`readonly`) passa a `assertSeeHtml` e `assertDontSee('Editar contato')`; novo `test_historico_de_contato_so_com_lid_tem_o_botao_desabilitado`.
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/History/HistoryPageTest.php tests/Feature/Contacts/ChatButtonsTest.php`. Expected: FAIL.
- [ ] **Step 3: Implementar.** No cartão: trocar `@unless ($readonly)` do rodapé por `@if ($canChat || ! $readonly)`, e dentro do grupo `@unless ($readonly)` só no botão "Editar contato". No Histórico: `<livewire:inbox.new-conversation />` antes do `</div>` final da raiz (o componente tem raiz `absolute`, sai do fluxo da grade).
- [ ] **Step 4: Rodar e ver passar**, mais os afetados: `php artisan test tests/Feature/History tests/Feature/Contacts tests/Feature/Livewire/ContactCardCompactTest.php tests/Feature/Livewire/NewConversationModalTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: botão Conversar no painel de detalhes do Histórico`.
