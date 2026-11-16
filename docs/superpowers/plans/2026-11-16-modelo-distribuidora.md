# Modelo Distribuidora do beta: plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: superpowers:subagent-driven-development (este projeto) para executar tarefa por tarefa. Passos com checkbox (`- [ ]`).

**Objetivo:** o segmento "Distribuidora" passa a gerar a automação que roda no dimetal-beta, com `{{empresa}}` no lugar do nome. O modelo atual, com CNPJ e nota fiscal, continua como "Distribuidora completo".

**Arquitetura:** os modelos por segmento são classes `Template` em `app/Services/Bot/Templates/Segments/`, listadas em `WorkflowTemplates::SEGMENTS`. O grafo de cada uma é montado com o `FlowBuilder`. Workflows já existentes não mudam: o grafo do modelo só é copiado quando se cria um workflow novo.

**Stack:** PHP 8.5, Laravel 13, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-11-16-modelo-distribuidora-e-renomeacao-design.md` (seção "Projeto 0").

## Restrições globais

- Os textos do modelo novo são os do beta, só com "Dimetal Distribuidora" trocado por `{{empresa}}` e "voce" por "você".
- Nenhum `setor_id` fixo no modelo novo: os setores vêm do bloco `menu_setores`, e fora do horário vai para a fila geral (`fila_geral: true`).
- Chaves: `distribuidora` (modelo novo) e `distribuidora_completo` (o atual). Ordem em `SEGMENTS`: `[Distribuidora::class, DistribuidoraCompleto::class]`.
- A descrição de todo segmento contém "não", dizendo o que o modelo não faz (regra do `SegmentTemplatesTest`).
- Testes afetados no ciclo RED/GREEN: `php artisan test --filter='SegmentTemplatesTest|WorkflowTemplatesTest|FlowBuilderTest'`. O Postgres de teste precisa estar no ar (ver `CLAUDE.md`).

## Foco da revisão

1. **Sem setores cadastrados:** o modelo novo cria um rascunho que valida (`checkGraph` vazio), e `resolve()['missing']` vem `[]`, porque nenhum setor é nomeado. Coberto na Tarefa 2.
2. **Resposta inválida no menu:** o bot responde "Resposta inválida." e repete o menu, não a mensagem padrão. Coberto na Tarefa 2.
3. **Silêncio no menu (5 min):** vai para a fila geral sem travar a sessão. Coberto na Tarefa 2.
4. **Nome da empresa não configurado:** `{{empresa}}` cai no padrão de `CompanySettings::name()`, sem sobrar `{{empresa}}` literal no texto. Coberto na Tarefa 2.
5. **Workflow já salvo com a chave antiga:** não existe, porque os workflows guardam o grafo e não a chave. Nenhum teste a acrescentar; foi conferido em `WorkflowPublisher::create`.

---

### Tarefa 1: o modelo atual vira "Distribuidora completo"

**Arquivos:**
- Renomear: `app/Services/Bot/Templates/Segments/Distribuidora.php` → `app/Services/Bot/Templates/Segments/DistribuidoraCompleto.php` (classe `DistribuidoraCompleto`, `key()` = `'distribuidora_completo'`, `name()` = `'Distribuidora completo'`; descrição e grafo como estão)
- Modificar: `app/Services/Bot/WorkflowTemplates.php` (import e `SEGMENTS = [DistribuidoraCompleto::class]` nesta tarefa)
- Testes: `tests/Feature/Bot/SegmentTemplatesTest.php`, `tests/Feature/Bot/WorkflowTemplatesTest.php`

**Interfaces:**
- Produz: `App\Services\Bot\Templates\Segments\DistribuidoraCompleto implements Template`, com `key(): 'distribuidora_completo'`.

- [ ] **Passo 1: ajustar os testes para a chave nova (RED)**
  - `SegmentTemplatesTest`: nos três testes `test_distribuidora_*`, trocar `'distribuidora'` por `'distribuidora_completo'` e renomeá-los para `test_distribuidora_completo_*`. `test_a_galeria_tem_o_segmento_distribuidora` passa a afirmar `assertContains('distribuidora_completo', $this->segments())`.
  - `WorkflowTemplatesTest::test_a_lista_traz_os_modelos_na_ordem`: espera as chaves `['em_branco', 'padrao', 'horario', 'distribuidora_completo']` e os nomes `[..., 'Distribuidora completo']`.
- [ ] **Passo 2: rodar e ver falhar**
  `php artisan test --filter='SegmentTemplatesTest|WorkflowTemplatesTest'`. Esperado: FAIL (a chave `distribuidora_completo` não existe e cai no modelo em branco).
- [ ] **Passo 3: renomear com `git mv`** e ajustar a classe, a chave, o nome e o docblock ("Distribuidora completo: ...").
- [ ] **Passo 4: rodar e ver passar** (mesmo comando). Esperado: PASS.
- [ ] **Passo 5: commit**
  `git commit -m "refactor: modelo Distribuidora atual vira Distribuidora completo"`

### Tarefa 2: o modelo Distribuidora do beta

**Arquivos:**
- Criar: `app/Services/Bot/Templates/Segments/Distribuidora.php`
- Modificar: `app/Services/Bot/WorkflowTemplates.php` (`SEGMENTS = [Distribuidora::class, DistribuidoraCompleto::class]`)
- Testes: `tests/Feature/Bot/SegmentTemplatesTest.php`, `tests/Feature/Bot/WorkflowTemplatesTest.php`

**Interfaces:**
- Consome: `FlowBuilder::start()`, `->branch(string $from, string $port, array $steps)`, `->link()`, `->graph()`; `Template` (`key`, `name`, `description`, `graph`).
- Produz: `Distribuidora::key() === 'distribuidora'`, `name() === 'Distribuidora'`.

**Grafo exigido** (ids legíveis; o `opening()` não serve, porque usa o `menu` de opções fixas e o texto padrão de fora do horário):

| id | tipo | data |
|----|------|------|
| `inicio` | inicio | `[]` |
| `horario` | condicao | `['kind' => 'horario']` |
| `saudacao` | mensagem | `['texto' => 'Olá, {{nome}}! Bem-vindo a {{empresa}}.']` |
| `menu` | menu_setores | `['texto' => 'Com qual setor você quer falar? Responda com o número da opção:', 'invalido' => 'Resposta inválida.', 'timeout_min' => 5]` |
| `encaminhamento` | mensagem | `['texto' => 'Certo! Seu protocolo é {{protocolo}}. Você entrou na fila de {{setor}} e um atendente já te responde em instantes.']` |
| `transferir` | transferir | `[]` |
| `fora` | mensagem | `['texto' => "Olá, {{nome}}! \nBem-vindo a {{empresa}}. \nNosso horário de atendimento é {{horario}}. Retornamos {{proximo_horario}} e você já está na fila para ser atendido."]` |
| `transferir_fora` | transferir | `['fila_geral' => true]` |

Ligações:
- `inicio→horario` (out);
- `horario→saudacao` (true), `saudacao→menu` (out), `menu→encaminhamento` (setor), `encaminhamento→transferir` (out);
- `horario→fora` (false), `fora→transferir_fora` (out).

`settings` do grafo: `['mensagens' => ['encaminhamento_setor' => "Vou encaminhar você para o setor: {{setor}} \nSeu protocolo é {{protocolo}}"]]`. O `FlowBuilder::graph()` devolve só `nodes` e `edges`: a classe acrescenta `settings` ao array que ele retorna.

Descrição (precisa conter "não"): `'Saudação com o nome da empresa, menu com os setores cadastrados e o protocolo ao encaminhar; fora do expediente avisa o horário e coloca o cliente na fila geral. Não faz perguntas antes de entregar ao setor.'`

- [ ] **Passo 1: testes (RED)**
  - **Em `SegmentTemplatesTest`:**
    - `test_a_galeria_tem_o_segmento_distribuidora` volta a afirmar também `assertContains('distribuidora', ...)` e checa a ordem: `assertSame(['distribuidora', 'distribuidora_completo'], $this->segments())`.
    - `test_todo_segmento_e_valido_com_e_sem_os_setores`: a afirmação "deveria acusar setores faltando" passa a valer só para segmentos cujo grafo tem algum `transferir` com `data.setor`. Ela fica antes do `resolve`; olhar o grafo cru da classe ou o `missing` com setores cadastrados, a critério do builder. Para `distribuidora`, afirmar `assertSame([], $resolved['missing'])`. O `checkGraph` sem setores continua `[]` para todos.
    - `test_todo_segmento_tem_no_maximo_cinco_opcoes_e_uma_descricao_honesta`: exige ao menos um bloco `menu` **ou** `menu_setores`. O limite de 5 opções vale só para os blocos `menu`.
  - **Novos testes em `SegmentTemplatesTest`** (setUp já cria Vendas, Financeiro e SAC):
    - `test_distribuidora_escolhe_o_setor_pelo_menu_e_vai_a_fila_dele`:
      - grava `Setting::put('company.name', 'Loja Teste')`; `walk('distribuidora', 'aberto', ['2'])`;
      - `outcome === IN_QUEUE` e o efeito contém `'entraria na fila de Financeiro'`;
      - o primeiro texto do bot é `'Olá, Maria! Bem-vindo a Loja Teste.'`; o segundo começa com `'Com qual setor você quer falar?'` e contém `'2 - Financeiro'`;
      - um texto do bot contém `'Você entrou na fila de Financeiro'`.
    - `test_distribuidora_resposta_invalida_repete_o_menu_com_o_aviso_do_beta`: `walk('distribuidora', 'aberto', ['9'])` → `outcome === null`, e entre os textos do bot está `'Resposta inválida.'`.
    - `test_distribuidora_silencio_no_menu_vai_a_fila`: `start` aberto e depois `silence` → `IN_QUEUE`.
    - `test_distribuidora_sem_nome_de_empresa_nao_deixa_variavel_crua`:
      - sem `company.name` gravado, `walk('distribuidora', 'fechado', [])`;
      - o único texto do bot contém `'Bem-vindo a '` e **não** contém `'{{empresa}}'`;
      - a mensagem padrão de `encaminhamento_setor` do grafo é a do beta: `WorkflowTemplates::graph('distribuidora')['settings']['mensagens']['encaminhamento_setor']` começa com `'Vou encaminhar você para o setor:'`.
  - `WorkflowTemplatesTest::test_a_lista_traz_os_modelos_na_ordem`: chaves `[..., 'distribuidora', 'distribuidora_completo']` e nomes `[..., 'Distribuidora', 'Distribuidora completo']`.
- [ ] **Passo 2: rodar e ver falhar** (`--filter='SegmentTemplatesTest|WorkflowTemplatesTest'`). Esperado: FAIL (a chave `distribuidora` cai no modelo em branco).
- [ ] **Passo 3: implementar** `Distribuidora` com o grafo da tabela e incluir a classe em `SEGMENTS`.
- [ ] **Passo 4: rodar e ver passar**: o mesmo filtro e depois `php artisan test --parallel --filter=Bot`. Esperado: PASS. O teste `test_fora_do_horario_vai_a_fila_geral_sem_perguntar` vale para os dois segmentos sem ajuste.
- [ ] **Passo 5: commit**
  `git commit -m "feat: modelo Distribuidora com a automação do beta (menu de setores e horário)"`

### Depois das tarefas

- Portão 7 (comandos do `CLAUDE.md`), revisão final do branch (`revisor-final`).
- Conferência no servidor local: criar um workflow "Distribuidora" e rodar o simulador do editor com o relógio aberto e o fechado.
- Os specs e este plano vão no PR deste branch. O PR só se abre quando o dono pedir.
