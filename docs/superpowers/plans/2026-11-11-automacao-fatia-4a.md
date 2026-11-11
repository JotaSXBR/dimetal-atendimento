# Automação, fatia 4a: blocos e validações

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline; subagente só se o dono pedir). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Atribuir a no Transferir, Horário de atendimento na paleta, Pergunta com validação (CNPJ, CPF, CEP, telefone, data), Condição "contato tem nome" e "já foi atendido", bloco Nota para a equipe com origem própria.

**Architecture:** Validações como classes pequenas de `app/Services/Bot/Answers`, escolhidas por um registro; a nota automática ganha `origin` (enum `NoteOrigin`); o motor ganha só o necessário (assumir o ticket depois da fila). O editor ganha os blocos e rótulos novos em `nodes.js`/`appearance.js`.

**Tech Stack:** Laravel 13, Livewire 4.4, Rete (editor), PHPUnit, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-11-automacao-fatia-4-design.md` (seção "Fatia 4a")

## Global Constraints

- Chave nova no grafo é sempre opcional; versão já publicada roda igual (nenhum comportamento de hoje muda).
- Sem dependência nova. Sem referência a projetos de terceiros em código e commit.
- Automação é "Bot"; a IA futura será "IA": `NoteOrigin` nasce com `Equipe` e `Bot` (`Ia` entra com a IA).
- CNPJ: 14 posições, 12 primeiras `0-9A-Z`, dígitos numéricos, módulo 11 com valor = ASCII − 48, pesos 2 a 9 da direita para a esquerda, resto 0 ou 1 dá 0 senão 11 − resto; aceita máscara e minúsculas; recusa sequência repetida. Vetores: `12ABC34501DE` fecha em `35`, `112223330001` fecha em `81`.
- Resposta válida é guardada formatada: CNPJ `11.222.333/0001-81` (alfanumérico mantém a máscara `12.ABC.345/01DE-35`), CPF `111.222.333-96`, CEP `01310-100`, telefone `(11) 98888-7777` (ou `(11) 3888-7777`), data `dd/mm/aaaa`.
- Commits em português com prefixo, um por tarefa, terminados com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Testes: `php artisan test --parallel --filter=...` com o Postgres de teste e as variáveis `DB_*` do `CLAUDE.md`; JS com `node --test tests/js`.

## Review Focus

- CNPJ com máscara, minúsculas, 13 ou 15 posições, só zeros e dígito errado: tudo recusado ou aceito conforme a regra. Task 1.
- Data impossível (31/02, 29/02 em ano comum) e telefone sem DDD: recusados. Task 1.
- "Atribuir a" com pessoa inativa, apagada ou de outro setor: o ticket fica só na fila, sem erro e sem derrubar o handoff. Task 5.
- Versão publicada sem as chaves novas (`atribuir_a`, `validacao` nova, `kind` novo) roda como antes. Tasks 2, 3 e 5.
- Nota com variável inexistente (`{{x}}`) renderiza vazia e não quebra; texto de 1000+ caracteres é recusado ao publicar. Task 4.
- Nota de bot não é apagável por usuário e o histórico não quebra com autor nulo. Task 4.

---

### Task 1: respostas validadas (`app/Services/Bot/Answers`)

**Files:**
- Create: `app/Services/Bot/Answers/Answer.php` (interface), `Cnpj.php`, `Cpf.php`, `Cep.php`, `Telefone.php`, `Data.php`, `Answers.php` (registro)
- Test: `tests/Unit/Bot/AnswersTest.php`

**Interfaces:**
- Produces: `interface Answer { public function parse(string $text): ?string; }` devolve a resposta formatada ou `null` se inválida. `final class Answers { public static function parse(string $kind, string $text): ?string; }` com `kind` em `cnpj|cpf|cep|telefone|data`; `kind` desconhecido devolve o texto aparado (como `texto`).

- [ ] **Step 1: Write the failing test** `AnswersTest`: dataset com `Answers::parse('cnpj', '11.222.333/0001-81')` → `'11.222.333/0001-81'`; `'11222333000181'` → mesmo formatado; `'12abc34501de35'` → `'12.ABC.345/01DE-35'`; `'11222333000180'`, `'00000000000000'`, `'1122233300018'`, `'112223330001811'` → `null`. CPF: `'11122233396'` → `'111.222.333-96'`; `'11111111111'` e `'11122233395'` → `null`. CEP: `'01310100'` e `'01310-100'` → `'01310-100'`; `'1310100'` → `null`. Telefone: `'11988887777'`, `'+55 (11) 98888-7777'`, `'(11)98888-7777'` → `'(11) 98888-7777'`; `'1133334444'` → `'(11) 3333-4444'`; `'988887777'` → `null`. Data: `'05/11/2026'` e `'5/11/2026'` → `'05/11/2026'`; `'31/02/2026'`, `'29/02/2025'`, `'2026-11-05'` → `null`; `'29/02/2024'` → válida.
- [ ] **Step 2: Run** `php artisan test --parallel --filter=AnswersTest`. Expected: FAIL (classes inexistentes).
- [ ] **Step 3: Implement** as cinco classes (cada uma tira máscara com `preg_replace`, confere tamanho e dígitos; CNPJ e CPF por módulo 11 conforme as restrições; data com `checkdate`) e `Answers::parse` (mapa `kind` → instância; sem estado).
- [ ] **Step 4: Run** o mesmo comando. Expected: PASS.
- [ ] **Step 5: Commit** `feat: validação e formatação de CNPJ, CPF, CEP, telefone e data nas respostas do bot`.

### Task 2: Pergunta usa as validações

**Files:**
- Modify: `app/Services/Bot/Nodes/PerguntaNode.php` (`answer`/`valid`), `resources/js/flow-editor/nodes.js` (opções de `validacao` do bloco `pergunta`)
- Test: `tests/Feature/Bot/EngineCoreTest.php` (acrescentar; usar o helper de montagem de sessão que o arquivo já tem)

**Interfaces:**
- Consumes: `Answers::parse(string $kind, string $text): ?string`.

- [ ] **Step 1: Write the failing tests**: `test_pergunta_cnpj_guarda_formatado` (resposta `11222333000181` → variável `11.222.333/0001-81`, segue a porta `out`); `test_pergunta_cnpj_invalido_repete_a_pergunta_e_na_terceira_vai_a_fila` (aviso do bloco `invalido`, três tentativas, `Step::leave('erros')` como hoje); `test_pergunta_sem_validacao_nova_continua_igual` (`texto`, `email` e `numero` com os mesmos resultados de hoje).
- [ ] **Step 2: Run** `--filter=EngineCoreTest`. Expected: FAIL nos dois primeiros.
- [ ] **Step 3: Implement**: em `answer`, para `validacao` em `cnpj|cpf|cep|telefone|data` usar `Answers::parse` (nulo = inválido; senão guardar o valor formatado); `email`, `numero` e `texto` ficam como estão. Em `nodes.js` acrescentar as cinco opções ao select `validacao`.
- [ ] **Step 4: Run** `--filter=EngineCoreTest` e `node --test tests/js`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: pergunta do bot valida CNPJ, CPF, CEP, telefone e data`.

### Task 3: Condição "contato tem nome" e "já foi atendido"

**Files:**
- Modify: `app/Services/Bot/Nodes/CondicaoNode.php`, `resources/js/flow-editor/nodes.js` (opções de `kind`), `resources/js/flow-editor/appearance.js` (`previewOf` de `condicao`)
- Test: `tests/Feature/Bot/EngineCoreTest.php`, `tests/js/flow-appearance.test.mjs`

- [ ] **Step 1: Write the failing tests**: `condicao` `kind: nome` segue `true` quando `contact.name` tem texto e `false` quando vazio; `kind: retorno` segue `true` se o contato tem outro ticket além do atual e `false` se só tem o atual; `kind` desconhecido continua comparando variável (comportamento de hoje). JS: `previewOf('condicao', {kind:'nome'})` → `['O contato já tem nome?']` e `{kind:'retorno'}` → `['Já foi atendido antes?']`.
- [ ] **Step 2: Run** os dois conjuntos. Expected: FAIL.
- [ ] **Step 3: Implement** em `CondicaoNode::enter` o `match` de `kind` com `nome` e `retorno` (consulta `Ticket::where('contact_id', ...)->whereKeyNot($run->ticket->id)->exists()`); em `nodes.js` as duas opções do select `kind`; em `previewOf` as duas linhas.
- [ ] **Step 4: Run** os dois conjuntos. Expected: PASS.
- [ ] **Step 5: Commit** `feat: condição do bot por nome do contato e por retorno de cliente`.

### Task 4: nota automática (origem própria) e bloco Nota para a equipe

**Files:**
- Create: `app/Enums/NoteOrigin.php`, `database/migrations/2026_11_11_000003_ticket_notes_origin.php`, `app/Services/Bot/Nodes/NotaNode.php`
- Modify: `app/Models/TicketNote.php`, `app/Policies/TicketNotePolicy.php`, `database/factories/TicketNoteFactory.php`, a view/componente que mostra o autor da nota (`app/Livewire/Inbox/Transcript.php` e seu Blade), `app/Services/Bot/Flow/NodeType.php`, `app/Services/Bot/Flow/FlowValidator.php` (`checkText`), `app/Services/Bot/BotEngine.php` (`node()`), `resources/js/flow-editor/nodes.js`, `resources/js/flow-editor/appearance.js` (ícone `note`, categoria `acao`, prévia)
- Test: `tests/Feature/Inbox/TicketNoteTest.php`, `tests/Feature/Bot/EngineCoreTest.php`, `tests/Unit/Bot/FlowValidatorTest.php`, `tests/Feature/Livewire/InboxNotesTranscriptTest.php`, `tests/js/flow-appearance.test.mjs`

**Interfaces:**
- Produces: `enum NoteOrigin: string { case Equipe = 'equipe'; case Bot = 'bot'; public function label(): string; }` (`label()`: "Equipe", "Bot"). `TicketNote` com `origin` (cast `NoteOrigin`, padrão `Equipe`) e `user_id` nulo para `Bot`. `NodeType::Nota = 'nota'` (porta `out`, rótulo "Nota para a equipe").

- [ ] **Step 1: Write the failing tests**: (a) política: `delete` de nota `Bot` é negado a qualquer usuário, inclusive admin; de nota `Equipe` continua só do autor; (b) `TicketNote::create(['ticket_id'=>..., 'origin'=>'bot', 'body'=>'x'])` funciona sem `user_id`; (c) engine: percorrer um fluxo `inicio → nota → encerrar` com `texto: 'CNPJ {{cnpj}} e {{x}}'` e variável `cnpj` já guardada grava uma `TicketNote` com origem `bot`, sem autor, corpo `CNPJ 11.222.333/0001-81 e ` (variável ausente vira vazio); (d) validador: `nota` sem texto → erro `"<id>: escreva o texto."`, com mais de 1000 caracteres → erro `"<id>: a nota passa de 1000 caracteres."`; (e) Transcript mostra "Bot" no lugar do nome para a nota automática e não quebra com `user_id` nulo; (f) JS: `categoryOf('nota')` é `acao`, `ICONS.note` existe, `previewOf('nota', {texto:'Pedido 10'})` → `['Pedido 10']`.
- [ ] **Step 2: Run** os conjuntos citados. Expected: FAIL.
- [ ] **Step 3: Implement**: migração (`origin` string padrão `equipe`; `user_id` passa a aceitar nulo; `down` restaura sem apagar dados), modelo (cast e `Fillable` com `origin`), política (`delete` exige `origin === Equipe`), fábrica, Transcript e Blade (rótulo da origem quando não é `Equipe`), `NodeType::Nota` (label e porta padrão `out`), `FlowValidator::checkText` (texto obrigatório e limite 1000), `NotaNode::enter` (renderiza `texto` com `Vars::render($texto, $run->vars())` e cria a nota com origem `Bot`; devolve `Step::next()`), `BotEngine::node()`, e no editor o bloco `nota` (palette, campo `texto` textarea, `icon: 'note'`) com o ícone SVG `note` em `ICONS`, `BY_TYPE.nota = 'acao'` e a prévia.
- [ ] **Step 4: Run** os conjuntos e `vendor/bin/phpstan analyse --no-progress`. Expected: PASS e 0 erros.
- [ ] **Step 5: Commit** `feat: bloco Nota para a equipe e origem da nota (equipe ou bot)`.

### Task 5: Atribuir a no Transferir

**Files:**
- Modify: `app/Services/Bot/Nodes/TransferirNode.php`, `app/Services/Bot/BotEngine.php` (`handoff`), `app/Services/Bot/Flow/FlowValidator.php` (`validate`, `checkReferences`), `app/Services/Bot/WorkflowPublisher.php` (`check`), `app/Livewire/Automation/Editor.php` (`render`: lista `users`), `resources/js/flow-editor/nodes.js` (campo `atribuir_a`, `kind: 'user'`), `resources/js/flow-editor.js` e `resources/views/livewire/automation/editor.blade.php` (select de pessoas), `resources/js/flow-editor/appearance.js` (prévia do Transferir com pessoa)
- Test: `tests/Feature/Bot/EngineCoreTest.php`, `tests/Unit/Bot/FlowValidatorTest.php`, `tests/Feature/Livewire/AutomationEditorTest.php`, `tests/js/flow-appearance.test.mjs`

**Interfaces:**
- Consumes: `ClaimTicket::__invoke(Ticket, User): bool`, `User::canHandleSector(?int): bool`, `TicketAssigned` (notificação).
- Produces: `BotEngine::handoff(BotSession $session, string $reason, ?Sector $to = null, ?User $assignTo = null): void`; `FlowValidator::validate(array $graph, ?array $sectorIds = null, ?array $tagIds = null, ?array $userIds = null): array` (`userIds` = ids de usuários ativos).

- [ ] **Step 1: Write the failing tests**: engine: `transferir` com `setor_id` fixo e `atribuir_a` de usuário ativo que atende o setor deixa o ticket `em_atendimento` com `assigned_user_id` dele e evento `claimed`; com usuário inativo, de outro setor (atendente que não atende o setor) ou inexistente o ticket fica `aguardando` na fila, sem exceção; sem `atribuir_a` nada muda (teste existente). Validador: `atribuir_a` com id que não é de usuário ativo → `"<id>: a pessoa <id> não existe ou está inativa."`; ausente vale. Editor: `render` entrega `users` (id e nome, só ativos). JS: `previewOf('transferir', {setor_id:1, atribuir_a:5}, {sectors:[{id:1,name:'Vendas'}], users:[{id:5,name:'Ana'}]})` → `['Vendas → Ana']`; pessoa que sumiu → `['Vendas → pessoa removida']`.
- [ ] **Step 2: Run** os conjuntos. Expected: FAIL.
- [ ] **Step 3: Implement**: `TransferirNode` lê `atribuir_a` e passa o `User` ativo a `handoff`; em `handoff`, depois de `enterQueue` e só quando `$assignTo !== null && $assignTo->active && $assignTo->canHandleSector($sector?->id)`, chamar `(new ClaimTicket)($ticket, $assignTo)` e, se vencer, `$assignTo->notify(new TicketAssigned($ticket))`; o `TicketEvent` `bot_handoff` leva `assigned_user_id` no payload. `check()` passa os ids de usuários ativos. Conferir em `TransferTicket`/`enterQueue` onde o alerta de fila dispara e, se for barato, não avisar a fila quando o ticket nasce atribuído (se não for, registrar como menor no ledger). Editor: campo `atribuir_a` (`kind: 'user'`, opção vazia "Ninguém (só a fila)") e prévia.
- [ ] **Step 4: Run** os conjuntos e `vendor/bin/phpstan analyse --no-progress`. Expected: PASS e 0 erros.
- [ ] **Step 5: Commit** `feat: bloco Transferir pode atribuir o ticket a uma pessoa`.

### Task 6: Horário de atendimento na paleta e rótulos Aberto/Fechado

**Files:**
- Modify: `resources/js/flow-editor/nodes.js` (`outputsFor`, lista de atalhos da paleta), `resources/js/flow-editor.js` (paleta e `add`), `resources/js/flow-editor/editor.js` (`addNode(type, at, data)`), `resources/views/livewire/automation/editor.blade.php` (item da paleta e do menu de contexto)
- Test: `tests/js/flow-serialize.test.mjs`

**Interfaces:**
- Produces: `PALETTE_SHORTCUTS` em `nodes.js`: `[{ key: 'horario', label: 'Horário de atendimento', type: 'condicao', data: { kind: 'horario' }, category: 'logica' }]`; `outputsFor('condicao', {kind:'horario'})` rotula as portas `true` e `false` como "Aberto" e "Fechado" (as chaves do grafo não mudam; `kind` diferente mantém "Sim" e "Não").

- [ ] **Step 1: Write the failing test**: `outputsFor('condicao', {kind:'horario'})` → `[{port:'true',label:'Aberto'},{port:'false',label:'Fechado'}]`; com `{kind:'variavel'}` e `{}` continua `Sim`/`Não`; `PALETTE_SHORTCUTS[0].data.kind === 'horario'`.
- [ ] **Step 2: Run** `node --test tests/js`. Expected: FAIL.
- [ ] **Step 3: Implement** os rótulos, o atalho, e a paleta/menu de contexto mostrando-o (clique cria uma `condicao` com `data = structuredClone(atalho.data)` por `addNode(type, at, data)`).
- [ ] **Step 4: Run** `node --test tests/js`, `npm run build` e conferir no navegador: o item aparece em "Decisões", cria o bloco com portas Aberto/Fechado, e o modelo "Atendimento com horário" abre com os rótulos novos. Expected: testes passam, build ok.
- [ ] **Step 5: Commit** `feat: Horário de atendimento na paleta do editor, com portas Aberto e Fechado`.

### Task 7: verificação da fatia

**Files:** nenhum.

- [ ] **Step 1: Run** `vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`, `node --test tests/js`, `npm run build` e `php artisan test --parallel`. Expected: todos 0 e suíte verde.
- [ ] **Step 2:** Pint que reformatar vira commit `style:` à parte; fim de linha CRLF gravado por script de edição se corrige com `sed -i 's/\r$//'`.
