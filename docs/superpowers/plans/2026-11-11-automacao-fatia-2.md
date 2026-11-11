# Automação, fatia 2: mensagens do bot editáveis e galeria de modelos

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Os textos que o bot diz sozinho (encaminhamento, opção inválida, resposta inválida, dica do `#sair`) passam a ser editáveis por workflow, a variável `{{empresa}}` existe, e "Novo workflow" oferece uma galeria de modelos, incluindo o "Atendimento com horário".

**Architecture:** Os textos ficam no grafo (`settings.mensagens`), versionados e restauráveis com o resto. Uma classe `BotMessages` concentra chaves, padrões (os textos de hoje) e a leitura a partir do `FlowGraph`. O editor Alpine guarda as mensagens ao lado do grafo e as envia junto em cada gravação (a serialização do Rete só conhece `nodes` e `edges`). Os modelos viram uma lista em código (`WorkflowTemplates`) usada pelo publicador e pela tela de criação.

**Tech Stack:** Laravel 13, Livewire 4.4, Alpine, Rete.js 2, PHPUnit, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-11-automacao-leva-b-design.md` (seção "Fatia 2")

## Global Constraints

- Postgres de teste no ar antes de qualquer teste (receita em "Stack e comandos" do `CLAUDE.md`).
- RED/GREEN só com os afetados: `php artisan test --filter=<Classe ou método>`; a suíte inteira só no portão 7.
- Chave nova no grafo é sempre opcional; sem `settings`, tudo se comporta exatamente como hoje (versões já publicadas seguem iguais).
- Chaves e padrões (texto de hoje, palavra por palavra): `encaminhamento_setor` = "Vou encaminhar você para o setor {{setor}}. Seu protocolo é {{protocolo}}."; `encaminhamento_geral` = "Vou encaminhar você para um atendente. Seu protocolo é {{protocolo}}."; `opcao_invalida` = "Opção inválida. Responda com o número de uma das opções."; `resposta_invalida` = "Resposta inválida. Tente de novo."; `rodape_sair` = "Para falar com um atendente digite #sair".
- Chave ausente = padrão; texto em branco = padrão, **exceto** `rodape_sair`, em que `''` significa "sem rodapé" (o `#sair` continua valendo). O campo `invalido` do bloco continua mandando sobre o texto do workflow.
- Teto de 1000 caracteres por texto (validado ao salvar no servidor).
- Variável nova `{{empresa}}` = `CompanySettings::name()`, disponível em toda mensagem do bot. `{{nome}}`, `{{protocolo}}`, `{{setor}}` também valem nos textos de encaminhamento.
- Modelos: Em branco (`DefaultWorkflow::blank`), Atendimento padrão (`DefaultWorkflow::graph`, sem mudança) e Atendimento com horário (texto da spec). O modelo criado fica como rascunho, sem publicar e sem ativar em canal.
- Commits em português com prefixo, um por tarefa, terminados com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Sem referência a bibliotecas de terceiros em código e commit.

## Review Focus

- Salvar o rascunho no editor **não pode apagar** as mensagens (a serialização do Rete devolve só nós e ligações). Teste na Tarefa 3 (JS) e na 4 (Livewire).
- Restaurar uma versão antiga traz as mensagens dela (ou nenhuma, se ela não tinha). Teste na Tarefa 4.
- Rodapé `''` some o rodapé e o `#sair` segue funcionando; rodapé ausente mantém o de hoje. Teste na Tarefa 2.
- Texto do workflow com `{{empresa}}`, `{{nome}}` e `{{setor}}` no encaminhamento; variável desconhecida fica como está. Teste na Tarefa 2.
- Mensagens malformadas vindas do navegador (não-lista, chave desconhecida, texto de mais de 1000 caracteres, valor que não é texto) são recusadas e nada é gravado. Teste na Tarefa 4.
- O modelo "Atendimento com horário" publica sem erro do `FlowValidator` e roda de ponta a ponta: dentro do horário passa pelo menu, fora do horário avisa o horário e vai à fila padrão. Teste na Tarefa 5.

---

### Task 1: `BotMessages`, `{{empresa}}` e a leitura das mensagens no grafo

**Files:**
- Create: `app/Services/Bot/BotMessages.php`
- Modify: `app/Services/Bot/Flow/FlowGraph.php` (guardar e expor `settings`), `app/Services/Bot/BotRun.php:21-41` (variável `empresa`)
- Test: `tests/Unit/Bot/BotMessagesTest.php`, `tests/Feature/Bot/EngineCoreTest.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `FlowGraph::message(string $key): ?string` (o texto de `settings.mensagens.<key>` se for texto, senão `null`).
  - `final class App\Services\Bot\BotMessages` com `public const KEYS` (lista das cinco chaves), `public const DEFAULTS` (chave → texto padrão), `public const MAX_LENGTH = 1000` e `public static function text(FlowGraph $flow, string $key): string` (a regra de ausente/branco/`rodape_sair`).
  - `BotRun::vars()` passa a incluir `'empresa' => CompanySettings::name()`.

- [ ] **Step 1: Write the failing tests.** `BotMessagesTest` (`PHPUnit\Framework\TestCase`; monta `FlowGraph::fromArray([...])`): sem `settings`, cada chave devolve o padrão; texto próprio devolve o texto; texto só com espaços devolve o padrão; `rodape_sair` igual a `''` devolve `''`; chave com valor que não é texto (`123`, lista) devolve o padrão; `KEYS` e `DEFAULTS` têm as mesmas cinco chaves. Em `EngineCoreTest`: `test_empresa_e_variavel_das_mensagens_do_bot` (versão com mensagem "Bem-vindo a {{empresa}}" e `CompanySettings::save(['name' => 'Dimetal Distribuidora'])`; o texto do bot sai "Bem-vindo a Dimetal Distribuidora").
- [ ] **Step 2: Run** `php artisan test --filter=BotMessagesTest` e `--filter=test_empresa_e_variavel`. Expected: FAIL (classe e variável inexistentes).
- [ ] **Step 3: Implement** `FlowGraph` com um terceiro dado `settings` lido de `$graph['settings']['mensagens']` (só o que for lista associativa) e `message()`; `BotMessages` conforme Interfaces; `BotRun::vars()` com a `empresa`. Docblock de uma linha em `BotMessages` dizendo onde cada texto é usado.
- [ ] **Step 4: Run** os mesmos testes. Expected: PASS.
- [ ] **Step 5: Commit** `feat: textos do bot lidos do grafo e variável empresa`.

### Task 2: motor usa os textos do workflow

**Files:**
- Modify: `app/Services/Bot/Nodes/ChoiceNode.php:18,58,66-74` (inválida e rodapé), `app/Services/Bot/Nodes/PerguntaNode.php:12,37` (inválida), `app/Services/Bot/BotEngine.php:98-124` (`handoff`: os dois avisos)
- Test: `tests/Feature/Bot/EngineCoreTest.php`

**Interfaces:**
- Consumes: `BotMessages::text(FlowGraph $flow, string $key): string`, `BotMessages::DEFAULTS` (Tarefa 1); `BotRun::$flow` e `BotRun::vars()`.
- Produces: nenhum símbolo novo. `ChoiceNode::DEFAULT_INVALID` e `PerguntaNode::DEFAULT_INVALID` deixam de existir (o padrão vive em `BotMessages::DEFAULTS`).

- [ ] **Step 1: Write the failing tests** em `EngineCoreTest` (helper novo `versionWithMessages(array $messages)` que monta o `simple()` com `settings.mensagens`):
  - `test_aviso_de_encaminhamento_do_workflow_vence_o_padrao`: `encaminhamento_setor` = "Oi {{nome}}, {{empresa}} vai te atender em {{setor}} (protocolo {{protocolo}})"; `say($session, '#sair')` produz esse texto renderizado (setor = o padrão `Vendas`).
  - `test_aviso_de_encaminhamento_geral_do_workflow`: sem nenhum setor recebendo, `encaminhamento_geral` próprio aparece.
  - `test_opcao_invalida_do_workflow_e_o_aviso_do_bloco_vence`: com `opcao_invalida` no workflow, `say($session, '9')` mostra o texto do workflow; com o campo `invalido` no bloco `menu_setores`, mostra o do bloco.
  - `test_resposta_invalida_da_pergunta_do_workflow`: bloco `pergunta` com validação `numero`, resposta "abc" mostra o texto do workflow.
  - `test_rodape_do_workflow_e_rodape_vazio_some`: `rodape_sair` próprio aparece no fim do menu; `rodape_sair` = `''` faz o menu terminar na última opção e `#sair` ainda leva à fila padrão.
  - O teste existente `test_fluxo_simples_saudacao_menu_de_setores_e_encaminhamento` (texto padrão, sem `settings`) deve seguir verde sem mudança.
- [ ] **Step 2: Run** `php artisan test --filter=EngineCoreTest`. Expected: os cinco novos FAIL, o resto PASS.
- [ ] **Step 3: Implement.** `ChoiceNode`: aviso = `invalido` do bloco se preenchido, senão `BotMessages::text($run->flow, 'opcao_invalida')`; o rodapé de `render` vem de `BotMessages::text($run->flow, 'rodape_sair')` (vazio = sem as duas quebras de linha finais). `PerguntaNode` idem com `resposta_invalida`. `BotEngine::handoff`: lê o grafo da versão da sessão (`FlowGraph::fromArray($locked->version()->firstOrFail()->graph)`), escolhe a chave `encaminhamento_setor` ou `encaminhamento_geral` e renderiza com `Vars::render` usando as variáveis de um `BotRun` do ticket mais `setor` e `protocolo` do momento; o `reason === 'fluxo'` continua sem aviso.
- [ ] **Step 4: Run** `php artisan test --filter=EngineCoreTest` e `php artisan test --parallel tests/Feature/Bot`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: mensagens do bot (encaminhamento, inválida e rodapé) vêm do workflow`.

### Task 3: a serialização leva as mensagens junto (JS)

**Files:**
- Modify: `resources/js/flow-editor/serialize.js`
- Test: `tests/js/flow-serialize.test.mjs`

**Interfaces:**
- Consumes: nada.
- Produces: `export function withMessages(graph, messages)` que devolve `{ ...graph, settings: { mensagens } }` com `mensagens` contendo só valores que são texto (descarta `undefined`, `null` e não-texto), e `export function messagesOf(graph)` que devolve o objeto `settings.mensagens` do grafo (ou `{}`).

- [ ] **Step 1: Write the failing tests** em `flow-serialize.test.mjs` (`node:test`): `withMessages` mantém `nodes` e `edges`; descarta valores não-texto e `undefined`; mantém `''` (rodapé oculto); `messagesOf` devolve `{}` sem `settings` e devolve as mensagens quando há.
- [ ] **Step 2: Run** `node --test tests/js`. Expected: FAIL (funções inexistentes).
- [ ] **Step 3: Implement** as duas funções em `serialize.js`.
- [ ] **Step 4: Run** `node --test tests/js`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: serialização do editor leva as mensagens do bot`.

### Task 4: servidor valida e o painel "Mensagens do bot"

**Files:**
- Modify: `app/Livewire/Automation/Editor.php:116-135` (`accept`) e `render` (entrega o catálogo de mensagens), `resources/js/flow-editor.js`, `resources/views/livewire/automation/editor.blade.php`
- Test: `tests/Feature/Livewire/AutomationEditorTest.php`

**Interfaces:**
- Consumes: `BotMessages::KEYS`, `DEFAULTS`, `MAX_LENGTH` (Tarefa 1); `withMessages`, `messagesOf` (Tarefa 3).
- Produces: `config.messages` na página: lista `[{key, label, default}]` na ordem das chaves, com estes rótulos: "Aviso ao entregar à fila (com setor)", "Aviso ao entregar à fila (sem setor)", "Aviso de opção inválida (menu)", "Aviso de resposta inválida (pergunta)", "Dica de como falar com um atendente (fim do menu)". `Editor::accept` recusa `settings` mal formado com `'As mensagens do bot são inválidas.'`.

- [ ] **Step 1: Write the failing tests** em `AutomationEditorTest`: `test_salvar_rascunho_guarda_as_mensagens` (`saveDraft` com `settings.mensagens` válidas grava no `draft`); `test_mensagens_invalidas_sao_recusadas_e_nada_e_gravado` (`settings` que não é lista, `mensagens` com chave desconhecida, valor que não é texto, texto de 1001 caracteres: `result.ok` falso, erro `graph`, rascunho igual ao anterior); `test_restaurar_traz_as_mensagens_da_versao` (versão publicada com mensagens, rascunho sem; `restore` devolve o grafo com elas); `test_pagina_do_editor_traz_o_painel_de_mensagens` (renderiza com o texto "Mensagens do bot" e os cinco padrões).
- [ ] **Step 2: Run** `php artisan test --filter=AutomationEditorTest`. Expected: os quatro novos FAIL.
- [ ] **Step 3: Implement** a validação em `accept` (usa `BotMessages::KEYS` e `MAX_LENGTH`) e o catálogo no `render`. No Alpine (`flow-editor.js`): estado `messages` iniciado de `messagesOf(config.graph)`, `catalog` de `config.messages`, `run(action)` passa `withMessages(api.getGraph(), this.messages)`, `restore` recarrega `messages` de `messagesOf(result.graph)`, `setMessage(key, value)` (vazio remove a chave, exceto `rodape_sair`, que usa `hideExitHint()` para gravar `''`) e `dirty = true` ao editar. No Blade: quando nenhum bloco está selecionado, painel "Mensagens do bot" com um campo por mensagem (placeholder = o padrão, botão "Usar o padrão"), a opção "Não mostrar a dica do #sair" e a lista de variáveis (`{{nome}}`, `{{protocolo}}`, `{{setor}}`, `{{empresa}}`, `{{horario}}`, `{{proximo_horario}}`); a linha de variáveis dos blocos ganha `{{empresa}}`.
- [ ] **Step 4: Run** `php artisan test --filter=AutomationEditorTest`, `node --test tests/js` e `npm run build`. Expected: PASS e build ok.
- [ ] **Step 5: Verificação no navegador** (Chrome local ou beta): abrir o editor, editar uma mensagem, "Salvar rascunho", recarregar e ver o texto mantido; usar "Usar o padrão". Anotar o resultado no ledger.
- [ ] **Step 6: Commit** `feat: painel Mensagens do bot no editor, com validação no servidor`.

### Task 5: galeria de modelos

**Files:**
- Create: `app/Services/Bot/WorkflowTemplates.php`
- Modify: `app/Services/Bot/DefaultWorkflow.php` (novo `withHours()`), `app/Services/Bot/WorkflowPublisher.php:21-28` (`create`), `app/Livewire/Automation/Index.php`, `resources/views/livewire/automation/index.blade.php:10-22`, e os chamadores de `create($name, $by, true|false)` que o `codegraph explore "WorkflowPublisher create"` listar (inclui testes)
- Test: `tests/Feature/Bot/WorkflowPublisherTest.php`, `tests/Feature/Livewire/AutomationIndexTest.php` (existente ou novo, no padrão dos vizinhos)

**Interfaces:**
- Consumes: `DefaultWorkflow::blank()`, `DefaultWorkflow::graph()`; `FlowValidator`.
- Produces:
  - `DefaultWorkflow::withHours(): array` (grafo do modelo com horário, conforme a spec, ids `inicio`, `horario`, `saudacao`, `menu`, `encaminhamento`, `transferir`, `fora`, `transferir_fora`).
  - `final class WorkflowTemplates` com `BLANK = 'em_branco'`, `STANDARD = 'padrao'`, `WITH_HOURS = 'horario'`, `public static function all(): array` (lista de `['key', 'name', 'description']` na ordem Em branco, Atendimento padrão, Atendimento com horário) e `public static function graph(string $key): array` (cai no em branco para chave desconhecida).
  - `WorkflowPublisher::create(string $name, User $by, string $template = WorkflowTemplates::BLANK): Workflow`.

- [ ] **Step 1: Write the failing tests.** `WorkflowPublisherTest`: `test_cada_modelo_cria_rascunho_valido_sem_publicar` (para as três chaves: o `draft` bate com `WorkflowTemplates::graph`, nenhuma versão criada, nenhum canal ativado; os dois modelos não vazios passam em `check()` com um setor cadastrado); `test_modelo_com_horario_dentro_do_horario_passa_pelo_menu` e `test_modelo_com_horario_fora_do_horario_avisa_e_vai_a_fila_padrao` (publica o modelo, usa `BotEngine::start` com `$this->travelTo` num horário aberto e outro fechado, com `BusinessHour` cadastrado; fora do horário o texto contém o horário e o ticket fica `AGUARDANDO` no primeiro setor); `test_chave_de_modelo_desconhecida_cria_em_branco`. `AutomationIndexTest`: "Novo workflow" lista os três modelos com nome e descrição; criar escolhendo "Atendimento com horário" grava o rascunho dele; o padrão da escolha é "Atendimento padrão".
- [ ] **Step 2: Run** `php artisan test --filter=WorkflowPublisherTest` e `--filter=AutomationIndexTest`. Expected: FAIL (classe e parâmetro novos).
- [ ] **Step 3: Implement** `WorkflowTemplates`, `DefaultWorkflow::withHours()`, a nova assinatura de `create` e os chamadores; `Index` troca `bool $fromTemplate` por `string $template = WorkflowTemplates::STANDARD`; o formulário troca o checkbox por uma lista de opções (radio) com nome e descrição, e uma frase de uma linha por modelo: Em branco ("Só o Início, você monta tudo"), Atendimento padrão ("Saudação, menu de setores e encaminhamento com o protocolo"), Atendimento com horário ("Como o padrão, mas fora do expediente avisa o horário e coloca o cliente na fila").
- [ ] **Step 4: Run** `php artisan test --filter=WorkflowPublisherTest`, `--filter=AutomationIndexTest`, `--filter=AutomationEditorTest` e `php artisan test --parallel tests/Feature/Bot`. Expected: PASS.
- [ ] **Step 5: Verificação no navegador:** criar um workflow pelo modelo com horário e abrir o editor; ver os 8 blocos ligados. Anotar no ledger.
- [ ] **Step 6: Commit** `feat: galeria de modelos ao criar workflow, com o modelo de atendimento por horário`.

### Task 6: verificação (portão 7)

**Files:** nenhum.

- [ ] **Step 1: Run** `vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`, `node --test tests/js`, `npm run build` e a suíte com Redis (receita no `CLAUDE.md`). Ler o código de saída de cada um. Expected: todos 0 e suíte verde.
- [ ] **Step 2:** Se o Pint reformatar algo, commitar `style:` à parte.
