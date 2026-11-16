# Menores, leva 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zerar `docs/superpowers/menores-pendentes.md`: U2, U6, U10, U11, L1–L9 e os dois menores do prazo de apagar.

**Architecture:** Correções pontuais nos arquivos que já existem. A única peça nova é a limpeza de rascunhos (U11): JS no `composerMode` + um método Livewire que responde quais tickets estão encerrados.

**Tech Stack:** Laravel 13, Livewire 4.4 (build CSP), Flux 2, Alpine (subconjunto CSP), PHPUnit, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-16-menores-leva-2-design.md`

## Global Constraints

- Branch `fix/menores-leva-2`. Commits em português (`fix:`/`test:`/`docs:`/`style:`) com a linha `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Alpine só no subconjunto CSP (guarda `tests/Unit/AlpineCspSubsetTest.php`); acesso ao storage sempre com try/catch.
- `<button>` cru novo → `tests/Unit/ViewConventionsTest.php`; cores só por token (`ThemeTokensTest`).
- PHPMD: `TooManyPublicMethods` 15 e `ExcessiveClassComplexity` 50 (o `Inbox` está perto do limite: método novo vai num trait/serviço).
- Ao fechar cada item, tirá-lo de `docs/superpowers/menores-pendentes.md` no mesmo commit.
- Rascunho vence em **7 dias**; rascunho de ticket **encerrado** é apagado ao abrir o Inbox.
- Arquivos em LF.

## Review Focus

1. U11: rascunho no formato antigo (texto puro, sem data) não pode sumir nem quebrar: é lido como texto e ganha data na próxima gravação.
2. U11: o método do servidor recebe ids do navegador: só inteiros, no máximo 200, e só devolve tickets que o usuário pode ver (`readableBy`); id de ticket alheio não vaza estado.
3. U11: storage bloqueado ou JSON corrompido numa chave `rascunho:*` não derruba o Inbox.
4. U10: a largura mínima só vale para bolhas com menu; bolha de sistema/nota não muda.
5. Prazo: 48 h exatas ainda permite apagar e 15 min exatos ainda permite editar (`gte` inclusivo).

---

### Task 1: U11 — rascunhos vencem em 7 dias e somem com o ticket encerrado

**Files:**
- Modify: `resources/js/composer-mode.js` (`load`/`save` com `{text, at}`; função pura de limpeza exportada)
- Create (se ajudar o teste): `resources/js/draft-store.js` com as funções puras
- Modify: `app/Livewire/Inbox/Concerns/ComposesMessages.php` (método público novo) e a view que inicia a limpeza (`resources/views/livewire/inbox/inbox.blade.php`)
- Test: `tests/js/draft-store.test.mjs`, `tests/Feature/Livewire/InboxDraftCleanupTest.php`

**Interfaces:**
- Produces: `closedDraftTickets(array $ids): array` (Livewire, no trait `ComposesMessages`) → lista de ids (int) encerrados que o usuário vê; ignora não inteiros e corta em 200.
- Produces (JS puro): `parseDraft(raw: string|null): {text: string, at: number|null}`; `staleDraftKeys(entries: Array<[key, raw]>, userId, now, maxAgeMs): string[]`; `draftTicketIds(keys, userId): number[]`. Chave segue `rascunho:{userId}:{ticketId}:{mode}`.

- [ ] **Step 1: Testes que falham**
  - JS: `parseDraft('oi')` → `{text:'oi', at:null}`; `parseDraft('{"text":"oi","at":1}')` → `{text:'oi', at:1}`; JSON inválido → texto cru; `staleDraftKeys` devolve só chaves do usuário com `at` < now − 7 dias (formato antigo sem data não vence pela idade); `draftTicketIds` extrai ids únicos do usuário.
  - PHP: `test_responde_so_os_encerrados_que_o_usuario_ve` (um aberto, um encerrado visível, um encerrado de setor alheio → só o visível); `test_ignora_ids_invalidos_e_corta_em_200`.
- [ ] **Step 2: Rodar e ver falhar** (`node --test tests/js`, `php artisan test --filter=InboxDraftCleanupTest`).
- [ ] **Step 3: Implementar** as funções puras, `save` gravando `JSON.stringify({text, at: Date.now()})`, `load` via `parseDraft`, e a limpeza uma vez por carga do Inbox (`init` de um `Alpine.data` existente ou novo): remove as vencidas e chama `$wire.closedDraftTickets(ids)` e remove as chaves desses tickets. Tudo com try/catch.
- [ ] **Step 4: Rodar e ver passar**, mais `AlpineCspSubsetTest`, `npm run build`, phpstan/phpmd.
- [ ] **Step 5: Commit** `fix: rascunhos vencem em 7 dias e somem com o ticket encerrado (U11)` (tira U11 do arquivo).

### Task 2: U10 — chevron do menu não cobre o horário nas bolhas curtas

**Files:**
- Modify: `resources/views/livewire/inbox/transcript.blade.php` (a bolha que contém o `messageMenu`, ~136)
- Test: `tests/Feature/Livewire/InboxTranscriptTest.php`

- [ ] **Step 1: Teste que falha** `test_bolha_com_menu_tem_largura_minima`: a bolha de uma mensagem com menu leva a classe de largura mínima escolhida (ex.: `min-w-28`); a bolha em modo leitura (sem menu) não leva.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Corrigir** com a menor largura mínima que deixe horário + botão (24 px) lado a lado sem sobreposição; só onde o menu existe.
- [ ] **Step 4: Rodar e ver passar** (transcript, `BubbleColorsTest`, `ThemeTokensTest`), `npm run build`. Conferência visual fica com o controlador.
- [ ] **Step 5: Commit** `fix: chevron do menu não cobre o horário nas bolhas curtas (U10)` (tira U10).

### Task 3: L1 — contagem de setores com os casos que faltavam

**Files:**
- Modify: `app/Livewire/Automation/Index.php` (`summary`)
- Test: `tests/Feature/Livewire/AutomationIndexTest.php`

- [ ] **Step 1: Testes que falham** `test_setor_id_string_no_rascunho_conta_uma_vez` (fixo `'5'` e menu com o setor 5 → conta uma vez); `test_setor_desativado_ou_apagado_nao_conta_no_menu`; `test_setor_fixo_apagado_nao_conta` (Transferir para setor soft-deleted, sem menu → `0` setores, card sem "setor").
- [ ] **Step 2: Rodar e ver falhar** (o último falha hoje).
- [ ] **Step 3: Corrigir**: ids fixos normalizados para int e filtrados pelos setores existentes (uma consulta por render, junto da dos ids do menu).
- [ ] **Step 4: Rodar e ver passar** (arquivo inteiro), phpstan.
- [ ] **Step 5: Commit** `fix: card do fluxo não conta setor apagado e normaliza ids (L1)` (tira L1).

### Task 4: L2 — enquadramento do editor com teste

**Files:**
- Modify: `resources/js/flow-editor/editor.js` (`fitView`), `resources/js/flow-editor/appearance.js`
- Test: `tests/js/flow-appearance.test.mjs`

- [ ] **Step 1: Teste que falha** para a decisão hoje inline no `fitView`: função pura `fitPlan({k, height, inset, positions})` → `{scale, translate}` (usa `fitScale`, `insetScale`, `anchorTranslate`); casos: fluxo pequeno (k=1, só desloca `inset/2`), fluxo médio sem piso, fluxo alto que cai no piso (Início a `inset + 24`).
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Extrair** a decisão para `fitPlan` e fazer o `fitView` só aplicar o resultado; sem mudar o comportamento.
- [ ] **Step 4: Rodar e ver passar** (`node --test tests/js`), `npm run build`.
- [ ] **Step 5: Commit** `test: enquadramento do editor numa função pura testada (L2)` (tira L2).

### Task 5: L3–L6 — trava da restauração e ajustes de estilo

**Files:**
- Modify: `app/Livewire/Settings/Backups.php` (TTL `restore-dispatch` 30 → 120)
- Modify: `resources/js/composer-mode.js:45` (se a Task 1 não tiver reescrito), `resources/js/ticket-details.js:26`, `tests/js/storage-blocked.test.mjs`
- Test: `tests/Feature/Livewire/BackupsRestoreTest.php`

- [ ] **Step 1: Teste que falha** `test_trava_de_despacho_dura_120_segundos` (lock adquirido com 120 s: confira pelo `Cache::lock` falso ou pela constante pública `Backups::DISPATCH_LOCK_SECONDS = 120`).
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Corrigir** a TTL; `if/else` no lugar do ternário; `catch` com comentário de uma linha; o teste JS guarda e restaura `globalThis.window`/`document` (`after`/`finally`).
- [ ] **Step 4: Rodar e ver passar** (`BackupsRestoreTest`, `node --test tests/js`), pint, phpmd.
- [ ] **Step 5: Commit** `fix: trava de despacho da restauração em 120 s e ajustes de estilo (L3-L6)` (tira L3–L6).

### Task 6: L7 — seed

**Files:**
- Modify: `database/seeders/Cenarios/UsuariosDeTeste.php`
- Test: `tests/Feature/Seeders/AmbienteDeTesteSeederTest.php`

- [ ] **Step 1: Testes que falham** `test_usuarios_de_teste_sozinho_tem_setores` (rodar só `UsuariosDeTeste` em banco vazio → admin com todos os setores, atendente com 2); trocar o assert "sobra etiqueta" por existir etiqueta de cada escopo **não aplicada** ao contato/ticket do cenário.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Corrigir**: `UsuariosDeTeste` chama `SectorSeeder` se não houver setor.
- [ ] **Step 4: Rodar e ver passar**, pint, phpstan.
- [ ] **Step 5: Commit** `fix: seed de usuários de teste funciona sozinho (L7)` (tira L7).

### Task 7: L8, L9 e o prazo de apagar

**Files:**
- Modify: `resources/views/livewire/inbox/inbox.blade.php` (span do SLA; linha `header-line`)
- Modify: `app/Policies/MessagePolicy.php` (texto do aviso de apagar)
- Test: `tests/Feature/Livewire/InboxHeaderTest.php`, `tests/Feature/Livewire/InboxHeaderLineTest.php`, `tests/Feature/Policies/MessagePolicyTest.php` (ou o teste de policy existente), `tests/Feature/Livewire/InboxEditRevokeTest.php`

- [ ] **Step 1: Testes que falham**
  - `test_sla_do_cabecalho_nao_quebra_linha` (span do SLA com `whitespace-nowrap`).
  - `test_linha_de_metadados_mostra_o_texto_inteiro_no_title` (`title` igual ao texto da linha).
  - `test_aviso_de_apagar_usa_o_prazo_da_constante` (o texto do aviso contém `Message::REVOKE_WINDOW_HOURS / 24` dias, sem número fixo no código).
  - `test_apagar_vale_com_48_horas_exatas` e `test_editar_vale_com_15_minutos_exatos` (com `travelTo`/`Carbon::setTestNow`, nunca `travel()` com cache — ver CLAUDE.md).
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Corrigir** as duas classes/atributos e o texto do aviso a partir da constante.
- [ ] **Step 4: Rodar e ver passar** os arquivos tocados, pint, phpstan.
- [ ] **Step 5: Commit** `fix: SLA e metadados do cabeçalho e aviso de apagar pela constante (L8, L9)`; no mesmo commit, tirar L8, L9 e fechar U2 e U6 no arquivo de menores com o motivo (U2 conferido no navegador em 16/11; U6 só nomenclatura).

### Task 8: Trecho citado não vaza da bolha (botão do menu sobre a citação)

Causa (conferência no navegador + leitura): em `resources/views/livewire/inbox/quote-preview.blade.php`, o `<span class="truncate">` do trecho fica dentro de `flex items-center gap-1` sem `min-w-0`; como item flex ele não encolhe abaixo do conteúdo, o texto passa da largura do bloco citado e da reserva `pe-9` da bolha e fica por baixo do botão do menu (e a bolha com citação estica até o máximo).

**Files:** Modify `resources/views/livewire/inbox/quote-preview.blade.php`; Test `tests/Feature/Livewire/InboxTranscriptTest.php` (ou o teste da citação existente); `docs/superpowers/menores-pendentes.md` (tirar o item da citação).

- [ ] **Step 1: Teste que falha** `test_trecho_citado_encolhe_e_trunca`: o span do trecho no preview da citação leva `min-w-0` junto do `truncate`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Corrigir** com `min-w-0` no span do trecho (e no do autor, se for item flex sem ele); nada mais muda. A barra "Respondendo a…" usa o mesmo partial: conferir que segue igual.
- [ ] **Step 4: Rodar e ver passar** (transcript, testes da citação, `ThemeTokensTest`, `ViewConventionsTest`), `npm run build`. Conferência visual com o controlador.
- [ ] **Step 5: Commit** `fix: trecho citado trunca dentro da bolha e não passa sob o botão do menu`.

### Verificação final (controlador, portão 7)

`vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`, `node --test tests/js`, `npm run build` e a suíte com Redis, lendo o código de saída de cada um. Conferência visual (U10, U11, cabeçalho) pelo `conferente-visual` no servidor local com o seed, e o servidor parado no fim.
