# Atendimento padrão com o fluxo do beta: plano de implementação

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: superpowers:subagent-driven-development (este projeto) para executar tarefa por tarefa. Passos com checkbox (`- [ ]`).

**Objetivo:** o modelo básico "Atendimento padrão" passa a ser a automação que roda no dimetal-beta (horário → saudação → menu de setores → fila), com `{{empresa}}` no lugar do nome. O "Atendimento com horário" sai, porque ficaria igual.

**Arquitetura:** `DefaultWorkflow::graph()` é o grafo do "Atendimento padrão": é ele que a galeria (`WorkflowTemplates`, chave `padrao`) e o `DefaultWorkflowSeeder` usam. Workflows já existentes não mudam, porque o grafo só é copiado quando se cria um workflow novo. O segmento "Distribuidora" (CNPJ) não é tocado.

**Stack:** PHP 8.5, Laravel 13, Livewire 4, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-11-16-modelo-distribuidora-e-renomeacao-design.md` (seções "Dados de origem" e "Projeto 0: design").

## Restrições globais

- Os textos são os do beta, só com "Dimetal Distribuidora" trocado por `{{empresa}}` e "voce" por "você".
- Nenhum `setor_id` fixo: o setor vem do `menu_setores`; fora do horário vai para a fila geral (`fila_geral: true`).
- `DefaultWorkflow::OUT_OF_HOURS` **fica**, porque o `FlowBuilder::opening` (segmento Distribuidora) usa. `DefaultWorkflow::withHours()` e `WorkflowTemplates::WITH_HOURS` saem.
- Testes afetados: `php artisan test --filter='WorkflowTemplatesTest|SegmentTemplatesTest|AutomationIndexTest|AutomationEditorTest|WorkflowPublisherTest|AutomationSimulatorTest'`. Precisa do Postgres de teste no ar (ver `CLAUDE.md`).

## Foco da revisão

1. **Instalação sem setores:** o rascunho do padrão continua válido (`checkGraph` vazio); o `menu_setores` sem setores manda para a fila (`Step::leave('erros')`). Coberto no `test_cada_modelo_cria_rascunho_valido_sem_publicar`; o comportamento do menu vazio já existia.
2. **Resposta inválida no menu:** o bot responde "Resposta inválida." e repete o menu. Teste novo.
3. **Nome da empresa não configurado:** não sobra `{{empresa}}` cru no texto. Teste novo.
4. **Chave antiga `horario`** (uma aba aberta com a galeria antiga enviando `template=horario`): cai no em branco sem erro, pelo `default` do `resolve`. Teste novo no `AutomationIndexTest`.
5. **Mensagem padrão de encaminhamento:** o grafo leva `settings.mensagens.encaminhamento_setor` do beta, e o editor aceita esse `settings` ao salvar. Teste novo.

---

### Task 1: "Atendimento padrão" vira o fluxo do beta e o "com horário" sai

**Arquivos:**
- Modificar: `app/Services/Bot/DefaultWorkflow.php` (`graph()` novo; remove `withHours()`; docblocks)
- Modificar: `app/Services/Bot/WorkflowTemplates.php` (remove `WITH_HOURS` da lista e do `resolve`; descrição do padrão)
- Testes: `tests/Feature/Bot/WorkflowTemplatesTest.php`, `tests/Feature/Livewire/AutomationIndexTest.php` (linhas ~75, ~118–130), e conferir `tests/Feature/Bot/WorkflowPublisherTest.php` e `tests/Feature/Livewire/AutomationEditorTest.php`, que usam `DefaultWorkflow::graph()` e devem continuar passando sem mudança

**Interfaces:**
- Produz: `DefaultWorkflow::graph(): array` com `nodes`, `edges` e `settings`; `WorkflowTemplates::all()` com os básicos `['em_branco', 'padrao']`.

**Grafo exigido de `DefaultWorkflow::graph()`** (posições `x`/`y` como as do `withHours()` atual):

| id | tipo | x, y | data |
|----|------|------|------|
| `inicio` | inicio | 0, 0 | `[]` |
| `horario` | condicao | 260, 0 | `['kind' => 'horario']` |
| `saudacao` | mensagem | 520, 0 | `['texto' => 'Olá, {{nome}}! Bem-vindo a {{empresa}}.']` |
| `menu` | menu_setores | 780, 0 | `['texto' => 'Com qual setor você quer falar? Responda com o número da opção:', 'invalido' => 'Resposta inválida.', 'timeout_min' => 5]` |
| `encaminhamento` | mensagem | 1040, 0 | `['texto' => 'Certo! Seu protocolo é {{protocolo}}. Você entrou na fila de {{setor}} e um atendente já te responde em instantes.']` |
| `transferir` | transferir | 1300, 0 | `[]` |
| `fora` | mensagem | 520, 240 | `['texto' => "Olá, {{nome}}! \nBem-vindo a {{empresa}}. \nNosso horário de atendimento é {{horario}}. Retornamos {{proximo_horario}} e você já está na fila para ser atendido."]` |
| `transferir_fora` | transferir | 780, 240 | `['fila_geral' => true]` |

Ligações: as mesmas sete do `withHours()` atual.

`settings`: `['mensagens' => ['encaminhamento_setor' => "Vou encaminhar você para o setor: {{setor}} \nSeu protocolo é {{protocolo}}"]]`.

Descrição do padrão em `WorkflowTemplates::all()`: `'Saudação com o nome da empresa, menu de setores e protocolo; fora do expediente avisa o horário e coloca o cliente na fila.'`

- [ ] **Passo 1: testes (RED)**
  - `WorkflowTemplatesTest`:
    - `test_all_marca_os_tres_modelos_basicos_no_grupo_basicos` → renomear para `test_all_marca_os_modelos_basicos_no_grupo_basicos`, esperando `['em_branco', 'padrao']`.
    - `test_a_lista_traz_os_modelos_na_ordem` → chaves `['em_branco', 'padrao', 'distribuidora']` e nomes `['Em branco', 'Atendimento padrão', 'Distribuidora']`.
    - `test_cada_modelo_cria_rascunho_valido_sem_publicar` → `assertCount(8, WorkflowTemplates::graph(WorkflowTemplates::STANDARD)['nodes'])`.
    - Os dois testes `test_modelo_com_horario_*` → renomear para `test_padrao_dentro_do_horario_passa_pelo_menu` e `test_padrao_fora_do_horario_avisa_e_vai_a_fila_geral_sem_setor`, usando `WorkflowTemplates::STANDARD`. No primeiro:
      - gravar `Setting::put('company.name', 'Loja Teste')`;
      - afirmar `$texts[0] === 'Olá, Maria! Bem-vindo a Loja Teste.'`;
      - afirmar que `$texts[1]` começa com `'Com qual setor você quer falar?'` e contém `'1 - Vendas'`.
    - Novo `test_padrao_resposta_invalida_repete_o_menu_com_o_aviso_do_beta`: dentro do horário, `BotEngine::reply` com `'9'`. Se o teste não tiver um caminho direto pelo motor, usar o `BotSimulator` como no `SegmentTemplatesTest::walk`. Afirmar que entre os textos do bot está `'Resposta inválida.'` e que o ticket segue `PENDENTE`.
    - Novo `test_padrao_sem_nome_de_empresa_nao_deixa_variavel_crua`: fora do horário, sem `company.name`; o texto do bot não contém `'{{empresa}}'`.
    - Novo `test_padrao_traz_a_mensagem_de_encaminhamento_do_beta`: `WorkflowTemplates::graph('padrao')['settings']['mensagens']['encaminhamento_setor']` começa com `'Vou encaminhar você para o setor:'`.
  - `AutomationIndexTest`:
    - a ordem da galeria (linha ~75) sem "Atendimento com horário";
    - o teste das linhas ~118–130 deixa de esperar "Atendimento com horário". Ele passa a afirmar `assertDontSee('Atendimento com horário')` e que criar com `template = 'horario'` gera um rascunho com 1 bloco (em branco), sem erro.
- [ ] **Passo 2: rodar e ver falhar** (filtro das restrições globais). Esperado: FAIL nos testes alterados e novos.
- [ ] **Passo 3: implementar**: o `graph()` novo, remover o `withHours()` e o `WITH_HOURS`, ajustar descrição e docblocks (o docblock da classe diz que o padrão tem horário).
- [ ] **Passo 4: rodar e ver passar**: o mesmo filtro, depois `php artisan test --parallel --filter=Bot` e `--filter=Automation`. Esperado: PASS, inclusive `WorkflowPublisherTest`, `AutomationEditorTest` e `SegmentTemplatesTest` sem mudança.
- [ ] **Passo 5: commit**
  `git commit -m "feat: Atendimento padrão com o fluxo do beta (horário e menu de setores); sai o modelo com horário"`

### Depois da tarefa

- Portão 7 (comandos do `CLAUDE.md`), revisão final do branch (`revisor-final`).
- Conferência no servidor local: criar um workflow "Atendimento padrão" na galeria e rodar o simulador do editor com o relógio aberto e o fechado.
- Os specs e este plano vão no PR deste branch. O PR só se abre quando o dono pedir.
