# Menores adiados da Leva A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar três arestas da entrega de imagens e da transferência de tickets deixadas pela Leva A (#92).

**Architecture:** A regra de cache imutável passa a viver só em `AvatarStorage::response` (que recebe a versão, não um booleano). A conferência do setor na hora de gravar vira um helper no model `Sector` (`lockForReceiving`), usado por `TransferTicket` e `StartConversation` dentro das suas transações.

**Tech Stack:** Laravel 13, PHPUnit, PostgreSQL 18 (`FOR SHARE`).

**Spec:** `docs/superpowers/specs/2026-11-10-menores-leva-a-design.md`

## Global Constraints

- Postgres de teste no ar antes de qualquer teste (`docker run ... postgres:18-alpine`, receita em "Stack e comandos" do `CLAUDE.md`), senão o teste trava.
- RED/GREEN só com os afetados: `php artisan test --filter=<Classe ou método>`; a suíte inteira só no portão 7.
- Comportamento visível não muda: mesma mensagem de recusa ("Escolha um setor que receba tickets e que você atenda." em `StartConversation`), mesmo retorno `false` em `TransferTicket`.
- `*` no `If-None-Match` continua respondendo 200 com corpo (decisão da spec).
- Commits em português com prefixo (`fix:`), um por tarefa, terminados com a linha `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus

- Foto de usuário com `avatar_path` e sem `avatar_version`, URL sem `v`: não pode vir `immutable` (hoje vem). Teste na Tarefa 2.
- Logo com arquivo e sem `company.logo_version`, URL sem `v`: idem. Teste na Tarefa 2.
- `If-None-Match` com lista mista (`"outro", W/"x"`): 304. Teste na Tarefa 1.
- Setor apagado entre a leitura e a gravação em `TransferTicket`: `false`, ticket intocado, nenhum evento. Teste na Tarefa 3.
- Setor desativado (não apagado) na mesma janela: mesmo resultado, pelo mesmo helper. Testes nas Tarefas 3 e 4.
- `FOR SHARE` de fato pedido ao banco: teste na Tarefa 3 captura o SQL de `Sector::lockForReceiving` e exige `for share`. Limite assumido: o efeito da trava entre dois processos fica sem teste (precisaria de processo filho, como `ClaimConcurrencyTest`); é garantia do Postgres, e o portão 6 confere o código.
- Recusa dentro da transação não pode gastar o ritmo do contato e do canal: teste na Tarefa 4.

---

### Task 1: ETag fraco no `If-None-Match`

**Files:**
- Modify: `app/Services/Media/AvatarStorage.php:48`
- Test: `tests/Feature/Media/AvatarDeliveryTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `AvatarStorage::response` aceita `W/"x"` e listas no `If-None-Match`.

- [ ] **Step 1: Write the failing test** `test_etag_fraco_e_lista_de_etags_tambem_dao_304` em `AvatarDeliveryTest`: cria usuário com `setAvatar($this->webp())`, pega o `ETag` do primeiro GET e, para cada cabeçalho em `['W/'.$etag, '"outro", W/'.$etag]`, `assertStatus(304)`; no fim, `If-None-Match: *` deve dar `assertOk()` (fixa a decisão da spec).
- [ ] **Step 2: Run** `php artisan test --filter=test_etag_fraco_e_lista_de_etags_tambem_dao_304`. Expected: FAIL (200 em vez de 304).
- [ ] **Step 3: Implement** em `AvatarStorage::response`: comparar `$etag` com `request()->getETags()` depois de tirar o prefixo `W/` de cada item (`preg_replace('#^W/#', '', $tag)`); comentar em uma linha que a comparação fraca é a válida em `If-None-Match` e que `*` fica sem 304 de propósito.
- [ ] **Step 4: Run** `php artisan test --filter=AvatarDeliveryTest`. Expected: tudo PASS (inclui `test_304_sai_antes_de_abrir_o_objeto_no_disco`).
- [ ] **Step 5: Commit** `fix: ETag fraco no If-None-Match do avatar e do logo devolve 304`.

### Task 2: Cache imutável só com versão gravada

**Files:**
- Modify: `app/Services/Media/AvatarStorage.php:38-44` (assinatura de `response`), `app/Http/Controllers/AvatarController.php:24`, `app/Http/Controllers/LogoController.php:18`
- Test: `tests/Feature/Media/AvatarDeliveryTest.php`

**Interfaces:**
- Consumes: Tarefa 1 (mesmo método).
- Produces: `AvatarStorage::response(string $path, bool $public, ?string $version = null): Response`. Imutável só se `$version !== null && request()->query('v') === $version`. O parâmetro `bool $versioned` deixa de existir.

- [ ] **Step 1: Write the failing tests** em `AvatarDeliveryTest`:
  - `test_foto_sem_versao_gravada_nunca_fica_imutavel`: `setAvatar`, depois `User::query()->whereKey($user->id)->update(['avatar_version' => null])`; GET `route('avatar', ['users', $user->id])` deve vir `assertOk()` com `Cache-Control` contendo `no-cache` e sem `immutable`.
  - `test_logo_sem_versao_gravada_nunca_fica_imutavel`: `CompanySettings::setLogo($this->webp())`, depois `Setting::put('company.logo_version', null)`; GET `route('logo')` com a mesma asserção. Importar `App\Models\Setting`.
- [ ] **Step 2: Run** `php artisan test --filter=sem_versao_gravada_nunca_fica_imutavel`. Expected: os dois FAIL (vem `immutable`).
- [ ] **Step 3: Implement** a nova assinatura em `AvatarStorage::response` (calculando `$versioned` lá dentro), atualizar o docblock, e trocar nos dois controllers `versioned: request()->query('v') === ...` por `version: $user->avatar_version` e `version: CompanySettings::logoVersion()`.
- [ ] **Step 4: Run** `php artisan test --filter=AvatarDeliveryTest` e `--filter=UserAvatarTest`. Expected: PASS.
- [ ] **Step 5: Commit** `fix: avatar e logo só ficam imutáveis quando há versão gravada e a URL a traz`.

### Task 3: `TransferTicket` revalida o setor dentro da transação

**Files:**
- Modify: `app/Models/Sector.php` (novo método), `app/Actions/Inbox/TransferTicket.php:22-26`
- Test: `tests/Feature/Inbox/RoutingActionsTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `Sector::lockForReceiving(int $id): ?Sector` (estático). Relê a linha com `sharedLock()`, já sem os apagados (SoftDeletes), e devolve `null` se não existe mais ou se `status->receivesTickets()` é falso. Só vale dentro de `DB::transaction`. A Tarefa 4 o usa.

- [ ] **Step 1: Write the failing tests** em `RoutingActionsTest` (modelo `Sector` já carregado, banco alterado depois, ação chamada com o modelo velho; mesmas três asserções do `test_transferir_para_setor_desativado_e_recusado`: retorno `false`, `sector_id` do ticket inalterado, zero eventos):
  - `test_transferir_para_setor_apagado_depois_de_carregado_e_recusado`: `Sector::query()->whereKey($stale->id)->delete()` (soft delete).
  - `test_transferir_para_setor_desativado_depois_de_carregado_e_recusado`: `Sector::query()->whereKey($stale->id)->update(['status' => SectorStatus::DESATIVADO])`.
  - `test_setor_para_receber_e_relido_com_for_share`: dentro de `DB::transaction`, com `DB::enableQueryLog()`, chamar `Sector::lockForReceiving($sector->id)` e afirmar que alguma consulta do log contém `for share`; fora desse caso, o retorno é o `Sector` para ativo e para fora do menu, e `null` para desativado, apagado e id inexistente.
- [ ] **Step 2: Run** `php artisan test --filter=RoutingActionsTest`. Expected: os três novos FAIL (os dois primeiros devolvem `true`; o terceiro não acha o método).
- [ ] **Step 3: Implement** `Sector::lockForReceiving` e, em `TransferTicket::__invoke`, substituir a checagem `! $to->status->receivesTickets()` de antes da transação por `if (Sector::lockForReceiving($to->id) === null) { return false; }` como primeira linha da closure do `DB::transaction` (a trava do setor vem antes da do ticket, na mesma ordem do `DeleteSector`). Atualizar o docblock da classe se citar o setor "que não recebe tickets".
- [ ] **Step 4: Run** `php artisan test --filter=RoutingActionsTest` e, pelos afetados, `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`. Expected: PASS.
- [ ] **Step 5: Commit** `fix: transferir relê o setor com trava dentro da transação e recusa o apagado ou desativado`.

### Task 4: `StartConversation` revalida o setor dentro da transação

**Files:**
- Modify: `app/Actions/Inbox/StartConversation.php:59-66`
- Test: `tests/Feature/Inbox/StartConversationTest.php`

**Interfaces:**
- Consumes: `Sector::lockForReceiving(int $id): ?Sector` (Tarefa 3).
- Produces: `StartConversation` lança `ConversationNotStarted('Escolha um setor que receba tickets e que você atenda.')` quando o setor some ou deixa de receber entre o `guard` e a gravação, sem criar nada.

- [ ] **Step 1: Write the failing tests** em `StartConversationTest`, usando o helper `refuses(callable, 'Escolha um setor')` (que já confere que nada foi criado): cria `$stale = Sector::factory()->create()`, `$this->agent->sectors()->attach($stale)`, altera o banco (soft delete e, em outro teste, `status => DESATIVADO`) e chama `$this->start(sector: $stale)`. Nomes: `test_setor_apagado_depois_do_guard_nao_cria_nada` e `test_setor_desativado_depois_do_guard_nao_cria_nada`.
  - Nos dois testes acima, afirmar também que o ritmo não foi gasto: `RateLimiter::tooManyAttempts('start-conversation:channel:'.$channel->id, 1)` falso e `RateLimiter::attempts('start-conversation:contact:'.$contact->id)` igual a 0 (limpar as chaves no início do teste).
- [ ] **Step 2: Run** `php artisan test --filter=depois_do_guard_nao_cria_nada`. Expected: os dois FAIL (o ticket é criado).
- [ ] **Step 3: Implement** na closure de `DB::transaction`, logo após o `pg_advisory_xact_lock` e antes do `openTicket`: `if (Sector::lockForReceiving($sector->id) === null) { throw new ConversationNotStarted('Escolha um setor que receba tickets e que você atenda.'); }`. A mensagem repete a do `guard`; extrair para uma constante privada da classe usada nos dois pontos. Antes de lançar, devolver o ritmo já reservado por `reservePace`: método privado `releasePace(Contact, Channel)` com `RateLimiter::clear($channelKey)` e `RateLimiter::decrement($contactKey, 3600)` (as chaves viram constantes/helper compartilhados com `reservePace`).
- [ ] **Step 4: Run** `php artisan test --filter=StartConversationTest` e `--filter=NewConversation`. Expected: PASS.
- [ ] **Step 5: Commit** `fix: nova conversa relê o setor com trava dentro da transação`.

### Task 5: Verificação do ramo (portão 7)

**Files:** nenhum.

- [ ] **Step 1: Run** `vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`, `node --test tests/js`, `npm run build`, e a suíte com Redis (receita no `CLAUDE.md`). Ler o código de saída de cada um. Expected: todos 0 e suíte verde.
- [ ] **Step 2:** Se o Pint reformatar algo, commitar `style:` à parte. Nada a commitar se tudo passar.
