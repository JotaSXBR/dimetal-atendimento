# Automação, fatia 4b: efeitos do motor e simulador "Testar" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O motor do bot passa a falar com o mundo por uma interface de efeitos, e o editor ganha o painel "Testar", que roda o motor real sobre o rascunho sem gravar nem enviar nada.

**Architecture:** `BotEffects` reúne tudo que sai do motor (enviar, esperar, gravar contato, etiquetar, nota, encerrar, fila, persistir a sessão, "está no horário?"). `LiveEffects` é o código de hoje, movido; `SimulatedEffects` só registra. O motor e os blocos não sabem qual usam: `BotRun` deixa de carregar o `Ticket` e passa a carregar os efeitos. `BotSimulator` monta uma `BotSession` **não gravada** e um `BotRun` com `SimulatedEffects`; o estado da conversa de teste vive numa propriedade `#[Locked]` do componente Livewire `Editor`.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2, Rete.js 2 (Lit), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-11-automacao-fatia-4-design.md` (seção "Fatia 4b: efeitos e simulador" e "Anti-objetivos").

## Global Constraints

- Sem dependência nova (composer ou npm).
- Grafo publicado é versionado: **nenhuma chave nova no grafo** nesta fatia; workflows já publicados rodam igual. Nada muda no texto que o cliente recebe.
- O motor é um só, em produção e no simulador: proibido duplicar a regra de um bloco no simulador.
- Anti-objetivo: o simulador **não** envia mensagem, grava ticket, mensagem, nota, etiqueta, contato ou evento, nem dispara job, broadcast, alerta, chamada ao WAHA ou cópia de arquivo.
- A suíte do bot existente continua verde **sem alterar expectativas** (só construtores/dublês que dependam de `BotRun::$ticket` ou `BotEngine::say`).
- Nomes: "Bot" para a automação por blocos; nada novo mistura com "IA".
- Teto de passos do simulador = `BotEngine::MAX_STEPS` (50).
- Commits em português com prefixo (`feat:`, `refactor:`, `test:`); um por tarefa. Sem referência a projetos de terceiros em código, commit ou PR.
- Arquivos PHP gravados por script no Windows saem em CRLF e o Pint reprova: `sed -i 's/\r$//'`. Editar com a ferramenta `Edit`.
- Testes: Postgres de teste no ar (ver "Stack e comandos" do `CLAUDE.md`); só `php artisan test --parallel`.

## Review Focus

Entradas que a spec implica e nenhuma tarefa exercitaria sozinha, da mais provável:

1. Pergunta que o "cliente" erra três vezes no simulador: sai por `erros` para a fila padrão, igual ao motor real (Task 2).
2. Rascunho editado entre duas respostas, com o bloco em que a conversa esperava apagado: desfecho `erros`, sem exceção (Task 2).
3. Resposta vazia ou acima de 1000 caracteres no campo do simulador: recusada com aviso, sem avançar (Task 3).
4. Relógio "fora do expediente" com texto `{{proximo_horario}}` e **nenhum horário cadastrado**: renderiza sem exceção (Task 2).
5. Resposta depois do desfecho (conversa já na fila ou encerrada) e rascunho inválido no "Testar": a primeira é ignorada; o segundo mostra a lista de erros do "Validar" em vez de simular (Task 3).

---

## File Structure

- Create `app/Services/Bot/Effects/BotEffects.php`: a interface.
- Create `app/Services/Bot/Effects/LiveEffects.php`: implementação de produção (código movido de `BotEngine` e dos blocos).
- Create `app/Services/Bot/Effects/SimulatedEffects.php`: só registra, escreve em `SimulationState`.
- Create `app/Services/Bot/Simulation/SimulationState.php`: estado serializável da conversa de teste.
- Create `app/Services/Bot/Simulation/BotSimulator.php`: `start`, `reply`, `silence`.
- Modify `app/Services/Bot/BotRun.php`, `BotEngine.php`, `Nodes/{Midia,Esperar,Etiquetar,Nota,Encerrar,SalvarContato,Condicao,Transferir}Node.php`.
- Modify `app/Services/Bot/WorkflowPublisher.php` (`checkGraph`), `app/Livewire/Automation/Editor.php` (ações `sim*`).
- Modify `resources/views/livewire/automation/editor.blade.php`, `resources/js/flow-editor.js`, `resources/js/flow-editor/editor.js` (+ CSS do `is-active`).
- Tests: `tests/Feature/Bot/LiveEffectsTest.php`, `tests/Feature/Bot/BotSimulatorTest.php`, `tests/Feature/Bot/BotSimulatorReadOnlyTest.php`, `tests/Feature/Livewire/AutomationSimulatorTest.php`.

---

### Task 1: Interface de efeitos e implementação real (sem mudança observável)

**Files:**
- Create: `app/Services/Bot/Effects/BotEffects.php`, `app/Services/Bot/Effects/LiveEffects.php`
- Modify: `app/Services/Bot/BotRun.php`, `app/Services/Bot/BotEngine.php`, os oito blocos acima, e quem chamar `BotEngine::say`/`handoff`/`start`/`receive` (descobrir com `codegraph_explore "BotEngine say handoff start receive release"`: `BotEntry`, `ExpireBotSessionsCommand`, `CloseTicket`, etc.)
- Test: `tests/Feature/Bot/LiveEffectsTest.php`

**Interfaces:**
- Produces, `interface BotEffects` (namespace `App\Services\Bot\Effects`):
  - `say(string $text): void` (texto já renderizado; vazio é ignorado)
  - `media(string $path, string $caption): void` (caminho relativo do disco `local`, legenda já renderizada)
  - `wait(int $seconds): void` ("digitando…" + pausa, já limitada a 1–30 pelo bloco)
  - `saveContactField(string $field, string $value): void`
  - `tag(Tag $tag): void`
  - `note(string $body): void`
  - `close(): void`
  - `enterQueue(?Sector $sector, ?User $assignTo): ?int` (entra na fila e tenta atribuir; devolve o id de quem assumiu)
  - `logHandoff(string $reason, ?Sector $sector, ?int $assignedUserId): void`
  - `save(BotSession $session): void` (persiste a sessão; o simulador não persiste)
  - `entered(string $nodeId): void` (o motor entrou no bloco; só o simulador usa)
  - `isOpen(): bool`; `contactName(): string` (vazio se sem nome); `returning(): bool`; `protocol(): string`
- Produces: `new LiveEffects(Ticket $ticket)`.
- Produces: `BotRun::__construct(BotEngine $engine, BotSession $session, FlowGraph $flow, BotEffects $fx)`; `public readonly BotEffects $fx`; `BotRun::$ticket` **deixa de existir**.
- Produces em `BotEngine` (todos `public`): `begin(BotRun $run): void` (roda a partir do Início, `$run->flow->start()`), `reply(BotRun $run, string $text): void` (aplica `#sair`, valida que o bloco espera e entrega a resposta), `deliver(BotRun $run, string $reason, ?Sector $to = null, ?User $assignTo = null, bool $generalQueue = false): void` (escolhe setor, avisa o cliente se `$reason !== 'fluxo'`, `fx->enterQueue`, marca a sessão `transferida` e `fx->save`, `fx->logHandoff`). Mantêm assinatura: `start`, `receive`, `handoff(BotSession, string, ?Sector, ?User, bool)` (esta continua com a transação e o `lockForUpdate` e chama `deliver` com um `BotRun` de `LiveEffects`), `release`, `defaultSector`.

- [ ] **Step 1: Write the failing test** `tests/Feature/Bot/LiveEffectsTest.php`, com um ticket `pendente` de contato com `wa_id` (modelo do `setUp` de `tests/Feature/Bot/EngineExtraNodesTest.php`), `Queue::fake()`, `Sleep::fake()`, `Http::fake()`. Testes (nomes exatos):
  - `test_say_grava_mensagem_do_bot_e_despacha_o_envio`: `say('Oi')` → 1 `Message` de origem `MessageOrigin::BOT`, corpo `Oi`, `SendTicketMessageJob` empurrado; `say('  ')` não grava.
  - `test_tag_note_close_e_save_contact_field_fazem_o_que_os_blocos_faziam`: etiqueta anexada ao ticket (sem duplicar), `TicketNote` com `origin = NoteOrigin::BOT`, `close()` deixa o ticket `ENCERRADO` com `ClosedReason::BOT`, `saveContactField('name','Ana')` muda o nome do contato.
  - `test_enter_queue_com_atribuicao_devolve_o_id_de_quem_assumiu`: setor `Vendas`, usuário ativo que o atende → devolve o id e o ticket fica `em_atendimento` desse usuário; usuário inativo → `null` e ticket `aguardando`.
  - `test_wait_mostra_digitando_e_pausa`: `Http::assertSent` de `startTyping` e `stopTyping` e `Sleep::assertSlept` de 3 s para `wait(3)`; falha do WAHA não propaga.
  - `test_is_open_contact_name_returning_protocol`: sem horário cadastrado `isOpen()` é `true`; `contactName()` devolve o nome do contato (ou `''`); `returning()` é `true` só com outro ticket do mesmo contato; `protocol()` é o do ticket.
- [ ] **Step 2: Run** `php artisan test --parallel --filter=LiveEffectsTest` → FAIL (`BotEffects`/`LiveEffects` não existem).
- [ ] **Step 3: Create `BotEffects` and `LiveEffects`.** `LiveEffects` recebe o `Ticket` e **move** para si, sem alterar a lógica: `BotEngine::say`/`queueAndAssign`/`canAssign`/`assign`/`enterQueue` (hoje privados, com o `SendTicketMessage` obtido por `app()`), o corpo do `EsperarNode` (typing, `Sleep`, `Presence::botTyped`), do `MidiaNode` (checagem de caminho, cópia, `record`, despacho, logs), de `EtiquetarNode`, `NotaNode` (só a gravação), `EncerrarNode` (`CloseTicket`), `SalvarContatoNode` (`SaveContact`), e as consultas de `CondicaoNode` (`BusinessHours::isOpen(now())`, nome, retorno). `logHandoff` cria o `TicketEvent` `bot_handoff` com o payload de hoje (`reason`, `sector_id`, `assigned_user_id` só se houver). `save` é `$session->save()`; `entered` não faz nada.
- [ ] **Step 4: Refactor `BotRun`, the eight nodes and `BotEngine`** para usar `$run->fx`: `BotRun::vars()` lê `nome` de `fx->contactName()` (`'cliente'` se vazio) e `protocolo` de `fx->protocol()`; `say()` chama `fx->say`; `MensagemNode` não muda. Nos blocos, cada `enter` vira a chamada correspondente (a normalização do `Nota`, `Vars::render`, o limite de `Esperar` e a decisão "sem tag/sem valor, não faz nada" **ficam no bloco**; o efeito só executa). `TransferirNode` chama `$run->engine->deliver($run, 'fluxo', $sector, $assignTo, $data->bool('fila_geral'))`. No motor: `run`/`advance`/`settle` passam a usar `fx->save($session->fill([...]))` no lugar de `update`, e `fx->entered($id)` antes de cada `enter`; `start`/`receive`/`handoff`/`release` viram a casca real que monta `BotRun` com `LiveEffects` e delega a `begin`/`reply`/`deliver`.
- [ ] **Step 5: Run** `php artisan test --parallel --filter=LiveEffectsTest` → PASS. Depois a suíte afetada, sem tocar nenhuma expectativa: `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test --parallel` → verde (`EngineCoreTest`, `EngineBlocksTest`, `EngineExtraNodesTest`, `ExpireSessionsTest`, `IngestIntegrationTest`, `PendingStatusTest`, `StartConversationTest`). Se um teste montava `new BotRun(...)` ou usava `$run->ticket`, ajustar só a montagem.
- [ ] **Step 6: Commit** `refactor: o motor do bot fala com o mundo por BotEffects (LiveEffects é o comportamento de hoje)`.

---

### Task 2: Efeitos simulados e `BotSimulator`

**Files:**
- Create: `app/Services/Bot/Effects/SimulatedEffects.php`, `app/Services/Bot/Simulation/SimulationState.php`, `app/Services/Bot/Simulation/BotSimulator.php`
- Test: `tests/Feature/Bot/BotSimulatorTest.php`, `tests/Feature/Bot/BotSimulatorReadOnlyTest.php`

**Interfaces:**
- Consumes: `BotEffects`, `BotEngine::begin/reply/deliver`, `BotRun` (Task 1).
- Produces, `final class SimulationState`: propriedades `array $session` (`node_id`, `vars`, `attempts`, `waiting`, `state`, `expires_in_min`), `list<array{kind: 'bot'|'cliente'|'efeito'|'sistema', text: string}> $log`, `list<string> $trail`, `?string $outcome` (`null` em andamento, `fila`, `encerrada`), `string $clock` (`real`|`aberto`|`fechado`), `string $name`; `toArray(): array` e `static fromArray(array): self`.
- Produces: `SimulatedEffects::__construct(SimulationState $state)`. Cada efeito acrescenta uma linha `efeito` (textos fixos, em português: `etiquetaria "<nome>"`, `gravaria no contato: <campo> = <valor>`, `registraria a nota: <corpo>`, `esperaria <n> s`, `enviaria a mídia <arquivo> (legenda: <legenda>)`, `entraria na fila de <Setor|fila geral>[, atribuído a <Nome>]`, `encerraria a conversa`); `say` acrescenta `bot`; `entered` acrescenta o id a `trail`; `save` copia o estado da sessão para `$state->session`; `isOpen()` segue `clock` (`real` → `BusinessHours::isOpen(now())`); `contactName()` é `$state->name`; `returning()` é `false`; `protocol()` é `'TESTE'`; `enterQueue` devolve `null` e marca `outcome = 'fila'`; `close()` marca `encerrada`.
- Produces, `final class BotSimulator` (`__construct(BotEngine $engine)`): `start(array $graph, string $name, string $clock): SimulationState`; `reply(array $graph, SimulationState $state, string $text): SimulationState`; `silence(array $graph, SimulationState $state): SimulationState` (equivale a `deliver($run, 'timeout')`). `reply` e `silence` não fazem nada se `outcome !== null` ou se `session.state !== 'ativa'`. `reply` acrescenta antes a linha `cliente`. Cada chamada monta uma `BotSession` **sem gravar** (`new BotSession([...])`, nunca `save`) a partir de `$state->session`.

- [ ] **Step 1: Write the failing tests** em `BotSimulatorTest` (grafos montados em PHP, mínimos, como em `FlowValidatorTest`):
  - `test_mensagem_menu_e_transferir_registram_log_trilha_e_desfecho`: Início → Mensagem "Oi {{nome}}" → Menu ["Vendas"] → Transferir(setor Vendas). `start` deixa `session.waiting = 'menu'`, log com `bot` "Oi Maria" e o menu, trilha com os ids percorridos; `reply('1')` → `outcome = 'fila'` e linha `entraria na fila de Vendas`.
  - `test_menu_usa_a_mesma_regra_de_numero_e_sair_do_motor_real`: `reply('opção 1.')` escolhe a opção 1; `reply('#sair')` vai à fila padrão com o aviso `encaminhamento_setor`.
  - `test_pergunta_valida_cnpj_e_guarda_formatado`: Pergunta `cnpj`, resposta `11222333000181` → `vars.cnpj = '11.222.333/0001-81'`.
  - `test_pergunta_errada_tres_vezes_sai_por_erros` (Review Focus 1): 3 respostas inválidas → `outcome = 'fila'`, aviso `opcao_invalida`/`resposta_invalida` repetido 2 vezes.
  - `test_bloco_apagado_entre_respostas_vai_para_erros` (Review Focus 2): o `graph` da segunda chamada não tem o bloco em que a sessão esperava → `outcome = 'fila'`, sem exceção.
  - `test_relogio_forcado_escolhe_o_ramo_do_horario`: Condição `horario`; com `clock = 'fechado'` segue por `false`, com `aberto` por `true`.
  - `test_relogio_fechado_sem_horario_cadastrado_renderiza_proximo_horario` (Review Focus 4): texto `{{proximo_horario}}` com `clock = 'fechado'` e nenhum `BusinessHour` → sem exceção, linha `bot` presente.
  - `test_silencio_encaminha_por_timeout_e_so_uma_vez`: com `waiting = 'input'`, `silence` → `outcome = 'fila'`; segunda `silence` não acrescenta linhas.
  - `test_resposta_depois_do_desfecho_e_ignorada` (Review Focus 5): `reply` após `outcome = 'fila'` devolve o estado igual.
  - `test_efeitos_dos_blocos_viram_linhas`: Etiquetar (tag existente), Salvar contato, Nota (`{{cnpj}}`), Esperar 3 s, Mídia (arquivo no `Storage::fake('local')`), Transferir com `atribuir_a` → uma linha `efeito` para cada, com os textos acima.
  - `test_passos_acima_do_teto_vao_para_erros`: laço Mensagem → Mensagem (grafo cíclico sem espera, que o validador recusaria) termina em `outcome = 'fila'` em até 50 passos.
  
  Em `BotSimulatorReadOnlyTest`, **`test_o_simulador_nao_escreve_nem_envia_nada`**: fluxo com **todos os tipos de bloco** (Início, Mensagem, Menu, Menu de setores, Pergunta, Condição, Esperar, Mídia, Salvar contato, Etiquetar, Nota, Transferir com atribuição, e um ramo com Encerrar), percorrido por `start` + respostas roteirizadas, com `DB::listen` coletando toda consulta cujo SQL não comece por `select` (e ignorando `savepoint`/transação vazia), `Queue::fake()`, `Event::fake()`, `Bus::fake()`, `Http::fake()`, `Storage::fake('local')` e `Storage::fake` do disco de mídia. Asserções: lista de escritas vazia; `Queue::assertNothingPushed()`; `Event::assertNothingDispatched()`; `Http::assertNothingSent()`; nenhum arquivo novo nos discos; contagens de `Message`, `Ticket`, `TicketNote`, `TicketEvent`, `Contact`, `ticket_tag` e `BotSession` iguais às de antes.
- [ ] **Step 2: Run** `php artisan test --parallel --filter=BotSimulator` → FAIL (classes não existem).
- [ ] **Step 3: Implement** `SimulationState`, `SimulatedEffects` e `BotSimulator` conforme as Interfaces. `BotSimulator` monta `new BotRun($this->engine, $session, FlowGraph::fromArray($graph), $effects)` e chama `begin`/`reply`/`deliver`; o motor é o de produção, sem ramo "se simulação" em lugar nenhum. Se o teste de leitura achar uma escrita, corrigir **na origem** (o efeito que faltou na interface), não com exceção no simulador.
- [ ] **Step 4: Run** `php artisan test --parallel --filter=BotSimulator` → PASS; depois repetir a suíte afetada da Task 1.
- [ ] **Step 5: Commit** `feat: BotSimulator roda o motor real sobre o rascunho sem gravar nem enviar nada`.

---

### Task 3: Ações do editor (validação prévia, reiniciar, relógio, silêncio)

**Files:**
- Modify: `app/Services/Bot/WorkflowPublisher.php`, `app/Livewire/Automation/Editor.php`
- Test: `tests/Feature/Livewire/AutomationSimulatorTest.php`; ajustar `tests/Feature/Bot/WorkflowPublisherTest.php` só se `check` mudar de assinatura (não deve)

**Interfaces:**
- Consumes: `BotSimulator`, `SimulationState` (Task 2).
- Produces: `WorkflowPublisher::checkGraph(array $graph): list<string>` (a lógica de hoje de `check`, parametrizada; `check(Workflow)` passa a delegar `checkGraph($workflow->draft)`).
- Produces em `Editor`: `#[Locked] public array $sim = []` (estado de `SimulationState::toArray()` mais `errors: list<string>`; vazio = sem teste); `simStart(array $graph, string $clock, BotSimulator $sim, WorkflowPublisher $publisher): void`; `simReply(array $graph, string $text, BotSimulator $sim): void`; `simSilence(array $graph, BotSimulator $sim): void`; `simReset(): void`. Todas começam com `$this->authorize('supervisor')`, passam o grafo por `accept($graph)` (mesmo limite de tamanho/tipos do `validateDraft`) e **não gravam o rascunho**. `simStart` roda `checkGraph`; com erros, `sim = ['errors' => [...]]` e não simula. O nome do contato de teste é o de quem testa (`$this->user()->name`). `simReply` recusa texto vazio ou acima de 1000 caracteres com `addError('simText', ...)` sem avançar.

- [ ] **Step 1: Write the failing tests** em `AutomationSimulatorTest` (padrão de `AutomationEditorTest`: supervisor autenticado, workflow com `draft`):
  - `test_testar_roda_o_rascunho_sem_gravar_o_rascunho_e_sem_nenhuma_escrita_de_negocio`: `simStart($graphNaoSalvo, 'real')` → `sim['log']` com o menu; o `draft` do banco continua o antigo; contagens de `Message`/`Ticket`/`TicketNote` inalteradas.
  - `test_rascunho_invalido_mostra_os_erros_do_validar_em_vez_de_simular` (Review Focus 5): grafo sem bloco final → `sim['errors']` igual a `check()` do mesmo grafo, `sim['log']` vazio.
  - `test_responder_silencio_e_reiniciar`: `simReply('1')` avança; `simSilence` com conversa esperando vai à fila; `simReset` zera `sim`.
  - `test_resposta_vazia_ou_gigante_e_recusada` (Review Focus 3): `simReply('  ')` e 1001 caracteres → erro em `simText`, `sim` igual ao de antes.
  - `test_estado_do_teste_nao_e_editavel_pelo_navegador`: `->set('sim', [...])` lança (propriedade `#[Locked]`).
  - `test_atendente_nao_testa`: usuário atendente em `Livewire::test` → `assertForbidden` (como no `mount`).
  - `test_seletor_de_relogio_chega_ao_motor`: `simStart` com `'fechado'` num fluxo com Condição de horário segue o ramo "fora".
- [ ] **Step 2: Run** `php artisan test --parallel --filter=AutomationSimulatorTest` → FAIL.
- [ ] **Step 3: Implement** `checkGraph` e as ações do `Editor` conforme as Interfaces (valor de `clock` fora de `real|aberto|fechado` vira `real`).
- [ ] **Step 4: Run** `php artisan test --parallel --filter='AutomationSimulatorTest|AutomationEditorTest|WorkflowPublisherTest'` → PASS.
- [ ] **Step 5: Commit** `feat: editor ganha as ações do simulador (validação prévia, silêncio, relógio, reiniciar)`.

---

### Task 4: Painel "Testar" e realce do bloco atual

**Files:**
- Modify: `resources/views/livewire/automation/editor.blade.php`, `resources/js/flow-editor.js`, `resources/js/flow-editor/editor.js`, o CSS do editor (onde moram as classes `.flow-node.is-invalid`; achar com `grep -rn "is-invalid" resources/css`)
- Test: `tests/Feature/Livewire/AutomationSimulatorTest.php` (renderização), `node --test tests/js` se a lógica pura ganhar função em `appearance.js`

**Interfaces:**
- Consumes: ações `sim*` e propriedade `sim` (Task 3).
- Produces: `api.setActive(nodeId|null)` em `createEditor` (marca só esse bloco com a classe `is-active`, como `invalid` marca `is-invalid`); método Alpine `testOpen()`/`testClose()` e estado `testing` em `flowEditor`.

- [ ] **Step 1: Write the failing test** `test_painel_testar_aparece_no_editor_com_relogio_silencio_e_reiniciar`: `Livewire::test(Editor::class, [...])->assertSee('Testar')`, e com `sim` populado (via `simStart`) `assertSee` as mensagens do bot, a trilha, as variáveis, a linha de efeito e os controles "Reiniciar", "Simular silêncio" e o seletor com as três opções "Agora: horário real", "Dentro do expediente", "Fora do expediente". Rascunho inválido: `assertSee` o primeiro erro da lista.
- [ ] **Step 2: Run** o teste → FAIL.
- [ ] **Step 3: Implement.** Botão "Testar" junto de "Validar". O painel **ocupa a coluna de propriedades** (aba "Propriedades | Testar" no `<aside aria-label="Propriedades do bloco">`), para não cobrir canvas nem painel em janela estreita. Conteúdo: conversa (`bot` à esquerda, `cliente` à direita, `efeito` em linha tracejada cinza com ícone, `sistema` centralizado), campo de resposta (Enter envia, `wire:model` em `simText` com o erro), trilha (lista de rótulos dos blocos, o atual em destaque), variáveis (`vars` sem as que começam por `_`), desfecho ("Foi para a fila" / "Conversa encerrada"). Cada ação chama `$wire.simX(graph, ...)` com `api.getGraph()`; depois de cada resposta `api.setActive(sim.session.node_id ?? ultimo da trilha)`; ao fechar o painel ou reiniciar, `api.setActive(null)`. O painel precisa de `aria-live="polite"` na área da conversa e rótulos nos controles (acessibilidade já é regra do editor).
- [ ] **Step 4: Run** `php artisan test --parallel --filter=AutomationSimulatorTest` e `node --test tests/js` → PASS; `npm run build` → sai com 0.
- [ ] **Step 5: Verificar no navegador** (servidor local de verificação com banco próprio, ver memória `servidor-local-banco-proprio`): abrir um workflow, "Testar", percorrer o "Atendimento padrão" e um fluxo com Pergunta de CNPJ; conferir bloco realçado, trilha, efeitos, seletor de relógio, "Simular silêncio", "Reiniciar", e 720p e celular sem sobreposição. Registrar o que viu no ledger.
- [ ] **Step 6: Commit** `feat: painel Testar no editor de automação, com o bloco atual realçado no canvas`.

---

## Self-review

- **Cobertura da spec (4b):** interface de efeitos (Task 1); mensagens, trilha, variáveis, efeitos e desfecho (Tasks 2 e 4); "Reiniciar", "Simular silêncio" e seletor de relógio (Tasks 3 e 4); validador antes de simular (Task 3); nome de quem testa, número e `#sair` do motor real (Task 2); estado `#[Locked]` no componente, teto de passos (Tasks 2 e 3); zero escrita provada por teste (Task 2); suíte do bot sem mudar expectativa (Task 1).
- **Consistência de nomes:** `BotEffects`, `LiveEffects`, `SimulatedEffects`, `SimulationState`, `BotSimulator::{start,reply,silence}`, `BotEngine::{begin,reply,deliver}`, `Editor::{simStart,simReply,simSilence,simReset}`, `WorkflowPublisher::checkGraph` iguais nas quatro tarefas.
- **Decisões minhas (custo se erradas):**
  - Cada ação do simulador reenvia o grafo do canvas: editar o fluxo no meio do teste muda a conversa em curso (bloco apagado cai em `erros`). Custo baixo; guardar o grafo no estado pesaria até 512 KB por requisição.
  - "Cliente já foi atendido antes" vale sempre `false` no simulador (não há histórico de teste). Custo: o ramo `true` de Condição `retorno` só se vê editando o fluxo; um seletor "cliente recorrente" fica para depois se o dono pedir.
  - Mídia no simulador só registra a linha: arquivo ausente no storage não é detectado (o validador já exige o caminho preenchido).
  - O painel divide a coluna de propriedades em abas em vez de abrir uma terceira coluna.
- **Fora desta fatia:** modelos por segmento (4c).
