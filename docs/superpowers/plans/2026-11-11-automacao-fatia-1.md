# Automação, fatia 1: número com assinatura e assinatura sem linha em branco

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O menu do bot e o `#sair` entendem a escolha do cliente mesmo com a assinatura do sistema dele junto, e a assinatura do atendente deixa de pular uma linha.

**Architecture:** Uma classe pura, `App\Services\Bot\ChoiceReply`, concentra a regra "que número ou saída o cliente quis dizer" e é usada por `ChoiceNode::answer` e `BotEngine::receive`. A assinatura do atendente muda só em `Signature::place`, que a prévia da tela Empresa já reaproveita.

**Tech Stack:** Laravel 13, PHPUnit, PostgreSQL 18.

**Spec:** `docs/superpowers/specs/2026-11-11-automacao-leva-b-design.md` (seção "Fatia 1")

## Global Constraints

- Postgres de teste no ar antes de qualquer teste (receita em "Stack e comandos" do `CLAUDE.md`), senão o teste trava.
- RED/GREEN só com os afetados: `php artisan test --filter=<Classe ou método>`; a suíte inteira só no portão 7.
- Número: o texto inteiro, aparado, é só 1 a 3 dígitos; senão, as linhas que são só 1 a 3 dígitos: todas com o mesmo valor valem esse valor; nenhuma, ou valores diferentes, é resposta inválida.
- `#sair`: vale se o texto inteiro ou **qualquer linha** é `#sair` (sem diferenciar maiúscula); vence o número.
- Telefone, CEP e frases não viram opção (a linha precisa ser só um número curto). O texto da assinatura é ignorado.
- Assinatura do atendente: separada do texto por **uma quebra de linha** (`\n`), nas duas posições (`inicio` e `fim`). Mensagens já enviadas não mudam.
- Bloco **Pergunta** não muda (não objetivo da spec): continua guardando o texto inteiro.
- Commits em português com prefixo, um por tarefa, terminados com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Sem PR até o dono pedir.

## Review Focus

- `"1\n2"` (valores diferentes): resposta inválida, nada escolhido. Teste na Tarefa 1 (unidade) e na 2 (motor).
- Assinatura com telefone, CEP ou "Rua 10, 200" junto de um "1": escolhe 1, e sem o "1" nada é escolhido. Teste na Tarefa 1.
- Quebra `\r\n` e linhas vazias entre assinatura e número (`"*#Jessé:*\r\n\r\n1\r\n"`): escolhe 1. Teste na Tarefa 1.
- `#sair` com assinatura e com número na mesma mensagem: sai (vence o número); `#sair` colado a outras palavras na mesma linha não vale. Teste nas Tarefas 1 e 2.
- Número com quatro dígitos, "0" e `"1 "` com espaço interno ("1 2"): inválidos; repetido igual (`"1\n1"`) vale 1. Teste na Tarefa 1.
- Mensagem que não é texto (áudio, figurinha) continua contando como opção inválida (`test_audio_no_menu_conta_como_opcao_invalida` já cobre; precisa seguir verde).

---

### Task 1: `ChoiceReply`, a regra do número e do `#sair`

**Files:**
- Create: `app/Services/Bot/ChoiceReply.php`
- Test: `tests/Unit/Bot/ChoiceReplyTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `final class App\Services\Bot\ChoiceReply` com `public static function number(string $text): ?int` (o número escolhido, ou `null` se não há escolha clara; `"0"` devolve `0`) e `public static function wantsOut(string $text): bool`. Só texto puro, sem banco.

- [ ] **Step 1: Write the failing test** `ChoiceReplyTest` (`PHPUnit\Framework\TestCase`, com `#[DataProvider]`):
  - `number()` devolve: `'1'`→1; `' 2 '`→2; `"*#Jessé:*\n1"`→1; `"1\n*#Jessé:*"`→1; `"*#Jessé:*\r\n\r\n1\r\n"`→1; `"Fulano\nTel 3333-4444\n1"`→1; `"1\n1"`→1; `"0"`→0.
  - `number()` devolve `null`: `''`; `'oi'`; `"*#Jessé:*\nopa"`; `"1\n2"`; `'1234'`; `'1 2'`; `"Rua 10, 200"`; `"Meu CEP 14010-000\nobrigado"`; `"3333-4444"`.
  - `wantsOut()` verdadeiro para: `'#sair'`; `'  #SAIR '`; `"*#Jessé:*\n#sair"`; `"#sair\n1"`; falso para: `''`; `'sair'`; `'#sair agora'`; `"quero #sair do plano"`; `'1'`.
- [ ] **Step 2: Run** `php artisan test --filter=ChoiceReplyTest`. Expected: FAIL (classe não existe).
- [ ] **Step 3: Implement** `ChoiceReply` em `app/Services/Bot/ChoiceReply.php`: normaliza o texto em linhas (`preg_split('/\R/u', ...)`, `trim` em cada, descarta as vazias); `number`: se o texto aparado inteiro bate `^\d{1,3}$` devolve o inteiro; senão filtra as linhas que batem `^\d{1,3}$`, deduplica os valores inteiros e devolve o valor se sobrou exatamente um, `null` caso contrário; `wantsOut`: alguma linha (ou o texto inteiro) igual a `#sair` com `mb_strtolower`. Docblock de uma linha dizendo que a assinatura que o sistema do cliente acrescenta é ignorada.
- [ ] **Step 4: Run** `php artisan test --filter=ChoiceReplyTest`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: regra que lê o número e o #sair mesmo com a assinatura do cliente`.

### Task 2: motor e menu usam a regra nova

**Files:**
- Modify: `app/Services/Bot/Nodes/ChoiceNode.php:44` (o `preg_match` do número), `app/Services/Bot/BotEngine.php:75` (o teste do `#sair`)
- Test: `tests/Feature/Bot/EngineCoreTest.php`

**Interfaces:**
- Consumes: `ChoiceReply::number(string): ?int` e `ChoiceReply::wantsOut(string): bool` (Tarefa 1).
- Produces: nenhum símbolo novo; o menu e o `#sair` passam a aceitar o texto com assinatura.

- [ ] **Step 1: Write the failing tests** em `EngineCoreTest` (usam `simple()`, `say()`, `botTexts()` e `event()` já existentes):
  - `test_numero_com_assinatura_do_cliente_escolhe_o_setor`: `say($session, "*#Jessé:*\n2")` deixa o ticket `AGUARDANDO` no setor `suporte` e o evento `bot_handoff` com `reason` `fluxo`; repetir com `"2\n*#Jessé:*"` em outro ticket não é necessário (a unidade cobre).
  - `test_valores_diferentes_na_mesma_mensagem_contam_como_opcao_invalida`: `say($session, "1\n2")` mantém o ticket `PENDENTE`, `attempts` igual a 1 e `botTexts()[2]` contém "Opção inválida".
  - `test_assinatura_sem_numero_conta_como_opcao_invalida`: `say($session, "*#Jessé:*\nopa")` mesmo resultado do anterior.
  - `test_sair_numa_linha_com_assinatura_vai_a_fila_padrao`: `say($session, "*#Jessé:*\n#sair")` → ticket `AGUARDANDO` no setor `vendas`, evento `bot_handoff` com `reason` `sair`.
  - `test_sair_vence_o_numero_na_mesma_mensagem`: `say($session, "2\n#sair")` → setor `vendas` (padrão), não `suporte`.
- [ ] **Step 2: Run** `php artisan test --filter=EngineCoreTest`. Expected: os cinco novos FAIL, os existentes PASS.
- [ ] **Step 3: Implement** em `ChoiceNode::answer`: `$number = ChoiceReply::number($text) ?? 0;` no lugar do `preg_match`; em `BotEngine::receive`: `if (ChoiceReply::wantsOut($text))` no lugar de `mb_strtolower($text) === '#sair'`. Mensagem que não é texto continua com `$text = ''` (já é assim), logo cai em inválida.
- [ ] **Step 4: Run** `php artisan test --filter=EngineCoreTest` e `php artisan test --filter=Bot`. Expected: PASS (inclui `test_audio_no_menu_conta_como_opcao_invalida` e `test_sair_em_qualquer_caixa_vai_a_fila_padrao`).
- [ ] **Step 5: Commit** `fix: menu e #sair do bot entendem a assinatura do cliente`.

### Task 3: assinatura do atendente sem linha em branco

**Files:**
- Modify: `app/Services/Inbox/Signature.php:32-35` (`place`) e o comentário da classe (linha 10)
- Test: `tests/Feature/Inbox/SignatureTest.php` e os testes afetados que fixam o texto assinado

**Interfaces:**
- Consumes: nada.
- Produces: `Signature::place(string $text, string $signature, string $position): string` junta com `"\n"` (antes `"\n\n"`), nas duas posições.

- [ ] **Step 1: Update the tests first** em `SignatureTest`: trocar `"\n\n"` por `"\n"` nas quatro expectativas (`test_assinatura_no_inicio_como_no_chatwoot`, `test_assinatura_no_fim_com_setor`, `test_envio_pelo_painel_grava_o_texto_ja_assinado`, `test_marcadores_no_nome_do_atendente_nao_desfazem_a_formatacao`). Rodar os afetados (`git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`; se a lista for grande demais para o comando, as pastas `tests/Feature/Inbox` e `tests/Feature/Livewire`) e ajustar do mesmo jeito qualquer outro teste que fixe a assinatura com linha em branco (`StartConversationTest`, `CompanyPageTest` e vizinhos).
- [ ] **Step 2: Run** `php artisan test --filter=SignatureTest`. Expected: FAIL nas quatro expectativas (recebe `\n\n`).
- [ ] **Step 3: Implement** `Signature::place` com `"\n"` e atualizar o docblock da classe ("linha em branco" vira "quebra de linha").
- [ ] **Step 4: Run** `php artisan test --filter=SignatureTest` e depois `php artisan test --parallel tests/Feature/Inbox` e `php artisan test --parallel tests/Feature/Livewire`. Expected: PASS.
- [ ] **Step 5: Commit** `fix: assinatura do atendente cola no texto, sem linha em branco`.

### Task 4: verificação (portão 7)

**Files:** nenhum.

- [ ] **Step 1: Run** `vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`, `node --test tests/js`, `npm run build` e a suíte com Redis (receita no `CLAUDE.md`). Ler o código de saída de cada um. Expected: todos 0 e suíte verde.
- [ ] **Step 2:** Se o Pint reformatar algo, commitar `style:` à parte. Nada a commitar se tudo passar.
