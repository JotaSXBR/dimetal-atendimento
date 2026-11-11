# Automação, fatia 4c: galeria de modelos por segmento Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** "Novo workflow" passa a oferecer modelos prontos por segmento (nesta fatia só a Distribuidora; os outros segmentos da spec ficam fora), montado com os blocos existentes, com o setor resolvido pelo nome e conferido pelo simulador.

**Architecture:** Um montador de grafo (`FlowBuilder`) escreve o fluxo sem coordenadas; um arquivo PHP por segmento usa o montador e traz o nome dos setores desejados. `WorkflowTemplates::resolve()` troca o nome do setor pelo id do setor que existe (e lista os que faltam); o editor organiza o desenho na primeira abertura de um grafo sem posição. A galeria agrupa os modelos.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2, Rete.js 2, PHPUnit, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-11-automacao-fatia-4-design.md` (seção "Fatia 4c: galeria por segmento", incluindo "Escopo da 4c").

## Global Constraints

- Sem dependência nova (composer ou npm). Nenhum bloco novo e nenhuma chave nova no grafo: o modelo usa só os blocos e campos que o motor já lê (`menu` com `opcoes`, `pergunta` com `variavel` e `validacao`, `condicao` com `kind: horario`, `nota`, `mensagem`, `transferir` com `setor_id` ou `fila_geral`).
- Cada modelo: no máximo 5 opções no menu; aviso de horário no início (aberto → saudação e menu; fechado → avisa o horário e entra na **fila geral**, sem perguntas); perguntas validadas (`cnpj`, `data`, `numero`, `telefone` onde couber); uma Nota para a equipe com o que foi coletado; Transferir ao setor certo. "Falar com atendente" já existe em todo menu (`#sair`).
- Dado pessoal só quando necessário e com a finalidade na pergunta. CNPJ pode; CPF e dado de saúde não são pedidos.
- Setores usados (os padrões da instalação): `Vendas`, `Financeiro`, `SAC`. **Escopo (dono, 11/11): só a Distribuidora;** a infraestrutura serve a qualquer segmento futuro, mas nenhum outro entra agora. Nome sem diferenciar maiúscula; só setor que recebe tickets (`SectorStatus::receivesTickets()`).
- Texto ao cliente: usar as variáveis que o motor já tem (`{{nome}}`, `{{empresa}}`, `{{protocolo}}`, `{{setor}}`, `{{horario}}`, `{{proximo_horario}}`).
- Nomes: "Bot" para a automação; nada novo mistura com "IA". Sem referência a projetos de terceiros em código, commit e PR.
- Os modelos existentes (`em_branco`, `padrao`, `horario`) e as conversas de versões já publicadas continuam iguais.
- Commits em português com prefixo, um por tarefa. Editar com a ferramenta `Edit`; script Python no Windows grava CRLF e o Pint reprova (`sed -i 's/\r$//'`).
- Testes: Postgres de teste no ar (`CLAUDE.md`, "Stack e comandos"; nesta máquina o `pg-test-5434`, `DB_PORT=5434`); `php artisan test --parallel`.

## Review Focus

1. Setor citado pelo modelo com nome em caixa diferente (`sac`), com espaços nas pontas, ou **inativo** (não recebe tickets): o primeiro resolve, o segundo resolve, o terceiro conta como ausente. (Task 2)
2. Instalação sem um dos setores do modelo: o grafo continua válido e publicável, o Transferir fica sem setor fixo (cai na fila padrão) e o aviso lista cada setor faltante uma vez só. (Tasks 2 e 3)
3. Cliente erra o CNPJ três vezes, fica em silêncio ou manda `#sair` no meio de uma opção: vai à fila sem exceção. (Task 5)
4. Fora do horário o modelo não pergunta nada e entrega à fila geral, sem setor. (Task 5)
5. Grafo que já tem posição (inclusive `0,0` explícito) nunca é reorganizado ao abrir; só o que vem sem nenhuma coordenada. (Task 4)

---

### Task 1: `FlowBuilder`, o montador de grafo

**Files:**
- Create: `app/Services/Bot/Templates/FlowBuilder.php`
- Test: `tests/Unit/Bot/FlowBuilderTest.php`

**Interfaces:**
- Produces (todos os métodos fluentes devolvem `self`, menos `graph()`):
  - `FlowBuilder::start(): self` — cria o bloco `inicio` (id `inicio`).
  - `node(string $id, string $type, array $data = []): self`
  - `link(string $from, string $to, string $port = 'out'): self`
  - `branch(string $from, string $port, array $steps): self` — `$steps` é `list<array{0: string, 1: string, 2?: array<string, mixed>}>` (`[id, type, data]`); cria os blocos e liga `$from --$port--> primeiro`, depois cada um ao seguinte pela porta `out`.
  - `opening(string $greeting, string $menuText, array $options): self` — acrescenta o padrão comum: `horario` (condicao `kind: horario`) ligado ao Início; porta `true` → `saudacao` (mensagem) → `menu` (bloco `menu`, `opcoes` = `$options`, `timeout_min` 5); porta `false` → `fora` (mensagem: o texto de aviso do horário do modelo "Atendimento com horário", em `DefaultWorkflow::withHours()`) → `transferir_fora` (`fila_geral: true`).
  - `graph(): array` — `['nodes' => [...], 'edges' => [...]]` com `id`, `type`, `data` e **sem `x` e `y`**; arestas `['from','port','to']`.

- [ ] **Step 1: Write the failing test** em `FlowBuilderTest`: `test_branch_liga_a_porta_e_encadeia_em_out` (`branch('menu','opt:1',[['a','mensagem',['texto'=>'x']],['b','nota',['texto'=>'y']]])` gera arestas `menu/opt:1→a` e `a/out→b`); `test_opening_monta_horario_menu_e_ramo_fechado` (blocos `inicio, horario, saudacao, menu, fora, transferir_fora`; `menu.data.opcoes` igual ao informado; `transferir_fora.data.fila_geral === true`; arestas `horario/true→saudacao`, `horario/false→fora`); `test_graph_nao_traz_posicao` (nenhum nó tem `x` nem `y`).
- [ ] **Step 2: Run** `php artisan test --filter=FlowBuilderTest` → FAIL (classe ausente).
- [ ] **Step 3: Implement** a classe `final`, guardando nós e arestas em arrays; id repetido lança `InvalidArgumentException` (erro do autor do modelo, não do usuário).
- [ ] **Step 4: Run** o teste → PASS; `vendor/bin/pint --test` e `vendor/bin/phpstan analyse --no-progress` limpos.
- [ ] **Step 5: Commit** `feat: montador de grafo dos modelos de automação`.

### Task 2: Registro de modelos e resolução de setor por nome

**Files:**
- Create: `app/Services/Bot/Templates/Template.php` (interface)
- Modify: `app/Services/Bot/WorkflowTemplates.php`, `app/Services/Bot/WorkflowPublisher.php:22-35`
- Test: `tests/Feature/Bot/WorkflowTemplatesTest.php` (acrescentar), `tests/Feature/Bot/WorkflowPublisherTest.php` se o `create` mudar de comportamento

**Interfaces:**
- Consumes: `FlowBuilder` (Task 1).
- Produces:
  - `interface Template { public static function key(): string; public static function name(): string; public static function description(): string; public function graph(): array; /** @return list<string> os setores que o modelo cita, em ordem, sem repetir */ ... }` — o grafo do modelo marca o setor desejado em `transferir.data.setor` (nome, **nunca gravado**).
  - `WorkflowTemplates::SEGMENTS` (lista das classes de segmento, vazia nesta tarefa) e `all()` passando a devolver `list<array{key, name, description, group}>` com `group` = `'Básicos'` para os três atuais e `'Por segmento'` para os de `SEGMENTS`.
  - `WorkflowTemplates::resolve(string $key): array{graph: array<string, mixed>, missing: list<string>}` — para cada `transferir` com `data.setor`: acha o setor existente (nome comparado sem diferenciar maiúscula e sem espaços nas pontas, só os que `receivesTickets()`), grava `setor_id` e **remove** `setor`; se não acha, remove `setor` sem `setor_id` e põe o nome em `missing` (sem repetir, na ordem em que aparece). Chave desconhecida vale o em branco, como hoje.
  - `WorkflowTemplates::graph(string $key)` continua existindo, devolvendo `resolve($key)['graph']`.
  - `WorkflowPublisher::create` usa `resolve()` (a assinatura não muda).

- [ ] **Step 1: Write the failing tests:** `test_resolve_troca_o_nome_do_setor_pelo_id_sem_diferenciar_caixa` (modelo de teste registrado em `SEGMENTS` por dublê/classe de teste com `setor: ' sac '` e setor `SAC` existente → `data.setor_id` = id do SAC e nenhuma chave `setor` no grafo); `test_resolve_trata_setor_inativo_como_ausente` (setor com status que não recebe tickets → `missing === ['SAC']`, sem `setor_id`); `test_resolve_lista_cada_setor_faltante_uma_vez` (dois Transferir para o mesmo setor inexistente → `missing` com uma entrada); `test_all_agrupa_basicos_e_segmentos`; `test_o_grafo_resolvido_nao_vai_ao_rascunho_com_a_chave_setor` (via `WorkflowPublisher::create`, o `draft` não tem `setor`). Para registrar o modelo de teste sem tocar produção, `SEGMENTS` vira propriedade estática sobrescrevível por teste (`WorkflowTemplates::$segments`, redefinida em `setUp`/`tearDown`), ou o teste usa um dos segmentos reais depois da Task 5; escolha a forma mais curta e documente no teste.
- [ ] **Step 2: Run** `php artisan test --filter=WorkflowTemplatesTest` → FAIL.
- [ ] **Step 3: Implement** conforme as interfaces. Atalho a marcar no código: `shortcut: nome do setor comparado só por caixa e espaço, sem acento; trocar por slug se alguém renomear com acento`.
- [ ] **Step 4: Run** `php artisan test --filter='WorkflowTemplates|WorkflowPublisher|AutomationIndex'` → PASS (os três modelos antigos não mudam).
- [ ] **Step 5: Commit** `feat: registro de modelos por segmento e setor resolvido pelo nome`.

### Task 3: Galeria agrupada e aviso dos setores que faltam

**Files:**
- Modify: `app/Livewire/Automation/Index.php` (`create`, nova propriedade pública `?string $notice`), `resources/views/livewire/automation/index.blade.php:10-32` (lista de modelos) e o ponto onde `$error` é exibido
- Test: `tests/Feature/Livewire/AutomationIndexTest.php`

**Interfaces:**
- Consumes: `WorkflowTemplates::all()` (com `group`) e `WorkflowTemplates::resolve()`.
- Produces: `Index::$notice` — texto "Criado. Estes setores do modelo não existem aqui: Vendas, SAC. Escolha o setor nos blocos Transferir." quando `missing !== []`; nulo caso contrário e ao criar de novo.

- [ ] **Step 1: Write the failing tests:** `test_a_galeria_agrupa_os_modelos` (a tela de "Novo workflow" mostra os grupos "Básicos" e "Por segmento" na ordem e cada modelo dentro do seu); `test_criar_de_modelo_com_setor_faltando_avisa_quais` (modelo de teste com Vendas inexistente → `notice` cita "Vendas" e o workflow é criado); `test_criar_com_todos_os_setores_nao_avisa`.
- [ ] **Step 2: Run** `php artisan test --filter=AutomationIndexTest` → FAIL.
- [ ] **Step 3: Implement** a view agrupando por `group` (cabeçalho por grupo, mesmo `label`/radio de hoje, mesmas chaves `wire:key`) e a `create` calculando o `notice` com `resolve($this->template)['missing']` antes de criar. Respeitar `resources/views` convenções (`ViewConventionsTest`: sem botão cru novo).
- [ ] **Step 4: Run** `php artisan test --filter='AutomationIndexTest|ViewConventionsTest'` → PASS.
- [ ] **Step 5: Commit** `feat: galeria de modelos agrupada e aviso dos setores que faltam`.

### Task 4: O editor organiza o desenho de um grafo sem posição

**Files:**
- Modify: `resources/js/flow-editor/appearance.js` (nova função pura exportada), `resources/js/flow-editor/editor.js:~270` (`load`)
- Test: `tests/js/flow-appearance.test.mjs`

**Interfaces:**
- Produces: `hasPositions(nodes): boolean` — `true` se algum nó tem `x` **e** `y` numéricos (inclusive `0`); `false` quando todos os nós vêm sem coordenada (ou a lista é vazia).

- [ ] **Step 1: Write the failing test** `hasPositions: sem coordenadas é falso; 0,0 explícito é verdadeiro; um nó com posição basta` (Review Focus 5).
- [ ] **Step 2: Run** `node --test tests/js` → FAIL.
- [ ] **Step 3: Implement** `hasPositions` e, em `load(source)` do `editor.js`, depois do `draw`, chamar `organize` **só** quando `!hasPositions(source.nodes)`. Conferir como o `Editor.php` entrega o rascunho ao JS (ele pode normalizar via `FlowGraph` e preencher `x`/`y` com `0`); se normalizar, entregar o rascunho cru ou marcar a ausência de outro modo, e provar com um teste Livewire que o grafo de um modelo chega sem coordenadas.
- [ ] **Step 4: Run** `node --test tests/js` e `npm run build` → verdes. Verificação no navegador fica para a Task 9.
- [ ] **Step 5: Commit** `feat: o editor organiza o desenho na primeira abertura de um modelo sem posição`.

### Task 5: Segmento Distribuidora e a prova pelo simulador

**Files:**
- Create: `app/Services/Bot/Templates/Segments/Distribuidora.php`; `tests/Feature/Bot/SegmentTemplatesTest.php`
- Modify: `app/Services/Bot/WorkflowTemplates.php` (registrar em `SEGMENTS`), `tests/Feature/Bot/WorkflowTemplatesTest.php` (a lista agora tem os três básicos mais Distribuidora; atualizar `test_a_lista_traz_os_tres_modelos_na_ordem`)

**Interfaces:**
- Consumes: `FlowBuilder::start()/opening()/branch()/graph()`, `Template`, `WorkflowTemplates::resolve()`, `BotSimulator::start(array $graph, string $name, string $clock): SimulationState` e `::reply(array $graph, SimulationState $state, string $text): SimulationState`.
- Produces: `Distribuidora` (key `distribuidora`, name `Distribuidora`, descrição que diz o que faz — coleta CNPJ e dados do pedido e entrega ao setor — e o que **não** faz — não consulta pedido, boleto nem estoque) e o **harness** do `SegmentTemplatesTest`:
  - `walk(string $key, string $clock, list<string> $replies): SimulationState` (cria os setores `Vendas`, `Financeiro`, `SAC`, resolve o modelo, inicia o simulador e envia as respostas em ordem);
  - um `dataProvider` `routes()` com linhas `[chave, relógio 'aberto', respostas, setor esperado, trechos da nota]`; um segmento futuro só acrescenta linhas e uma classe.
  - Testes genéricos sobre **todos** os `SEGMENTS`: `test_todo_segmento_e_valido_com_e_sem_setores` (validador sem erros com os quatro setores criados e com nenhum), `test_todo_segmento_tem_no_maximo_cinco_opcoes_e_descricao`, `test_fora_do_horario_vai_a_fila_geral_sem_perguntar`, `test_cada_rota_termina_na_fila_do_setor_com_a_nota` (usa `routes()`), `test_tres_cnpj_errados_vao_a_fila` e `test_sair_no_meio_vai_a_fila` (Review Focus 3 e 4).

**Distribuidora** (setor entre parênteses; todas as opções começam pedindo o CNPJ, `variavel: cnpj`, `validacao: cnpj`, texto dizendo que é para localizar o cadastro):

| # | Opção | Perguntas seguintes (`variavel`, `validacao`) | Nota para a equipe | Setor |
|---|---|---|---|---|
| 1 | Pedido ou orçamento | `itens` (texto): produtos e quantidades | "Pedido/orçamento. CNPJ {{cnpj}}. Itens: {{itens}}" | Vendas |
| 2 | 2ª via de boleto | `nota` (`numero`): número da nota fiscal | "2ª via de boleto. CNPJ {{cnpj}}. Nota {{nota}}" | Financeiro |
| 3 | Entrega | `nota` (`numero`) | "Consulta de entrega. CNPJ {{cnpj}}. Nota {{nota}}" | SAC |
| 4 | Troca ou devolução | `nota` (`numero`), `motivo` (texto) | "Troca/devolução. CNPJ {{cnpj}}. Nota {{nota}}. Motivo: {{motivo}}" | SAC |
| 5 | Falar com meu vendedor | — | "Cliente pede o vendedor. CNPJ {{cnpj}}" | Vendas |

Depois da nota, uma Mensagem de encaminhamento com `{{protocolo}}` e `{{setor}}` e o Transferir (`setor: <nome>`). CNPJ de prova nos testes: `11.222.333/0001-81` (válido).

- [ ] **Step 1: Write the failing tests** (o harness, os genéricos e as cinco linhas de `routes()` acima; cada linha afirma `outcome === 'fila'`, o último efeito `entraria na fila de <Setor>` e a nota com o CNPJ formatado e o dado da opção).
- [ ] **Step 2: Run** `php artisan test --filter=SegmentTemplatesTest` → FAIL (segmento ausente).
- [ ] **Step 3: Implement** `Distribuidora` com o montador conforme a tabela e registrá-la em `SEGMENTS`; textos ao cliente escritos pelo construtor, curtos, em português do dia a dia.
- [ ] **Step 4: Run** `php artisan test --filter='SegmentTemplatesTest|WorkflowTemplatesTest'`; Pint, Larastan e PHPMD limpos.
- [ ] **Step 5: Commit** `feat: modelo de automação para distribuidora, com prova pelo simulador`.

### Task 6: Conferência no navegador e anotações

**Files:**
- Modify: `docs/superpowers/specs/2026-11-11-automacao-fatia-4-design.md` (manter anotado que os demais segmentos ficam fora)

- [ ] **Step 1:** com o servidor local (`dimetal_local`, ver nota de memória), criar um workflow da Distribuidora pela galeria; conferir o agrupamento, o aviso de setor faltante (apagando um setor antes) e que o editor abre cada modelo já organizado, sem blocos sobrepostos, em 1280 e 800 px de largura.
- [ ] **Step 2:** rodar "Testar" no editor na Distribuidora (relógio aberto e fechado) e conferir trilha, variáveis e desfecho.
- [ ] **Step 3:** registrar o resultado no ledger e commitar qualquer ajuste de texto ou desenho com `fix:`.
