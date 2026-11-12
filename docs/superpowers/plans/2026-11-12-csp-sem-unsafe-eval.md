# CSP sem `unsafe-eval` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (inline, regra do projeto) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Todas as expressões de atributo Alpine/Livewire dentro do subconjunto do build CSP, uma chave `LIVEWIRE_CSP_SAFE` que liga o build CSP e tira `'unsafe-eval'` da política, e um guarda estático permanente.

**Architecture:** `config('livewire.csp_safe')` é o único ponto de verdade (build do Livewire + política). Lógica inline vira `Alpine.data` nomeado em `resources/js`; dados do servidor por `data-*`/JSON. Um teste varre as views e falha por arquivo:linha.

**Tech Stack:** Laravel 13, Livewire 4.4.8 (Alpine 3.15, `livewire.csp.js`), Flux 2, PHPUnit, Node `--test`, Chrome DevTools MCP.

**Spec:** `docs/superpowers/specs/2026-11-12-csp-sem-unsafe-eval-design.md` (inclui a gramática exata do parser instalado).

## Global Constraints

- Só o beta; nunca `main`/produção do Coolify. Servidor local de verificação: `dimetal_local`, porta 8123.
- Sem `eval`/`new Function`/`Function(...)` em JS do app. Sem desligar ou afrouxar o guarda para passar.
- Comportamento e visual idênticos; commit por componente. Atributos conhecidos por testes existentes (`x-data="composerMode($wire, ...)"`, `x-data="avatarCropper("`, `x-bind:style="style"`, `x-show="mode === 'reply' && ..."`, `x-on:error`) só mudam se o teste acompanhar.
- Subconjunto aceito (spec): sem `;` entre instruções, `if`, `const`/`let`, `await`/`async`, seta, `function`, crase, spread, `?.`, `??`, `new`, `typeof`, `in` (fora do `x-for`), método em `x-data` inline, globais do navegador, atribuição a propriedade de nó do DOM.
- JS do app: sem ponto e vírgula e aspas simples (estilo do repositório); `Alpine.data` registrado em `document.addEventListener('alpine:init', ...)`; novo arquivo importado em `resources/js/app.js`.
- Commits em português com prefixo; PR só no fim desta tarefa (autorizado pelo dono para este ciclo).

## Review Focus

- `x-on:*` só quebra no evento: o guarda estático cobre; cada componente tem roteiro de clique no Chrome com `LIVEWIRE_CSP_SAFE=true`.
- `x-model="selected.data[field.key]"` (editor) e `x-for` com `in`: confirmar no navegador com a chave ligada (o parser aceita `a[expr]`).
- `x-init`/handlers que dependiam de `$nextTick(() => ...)`: o método novo usa `await this.$nextTick()` ou `this.$nextTick(() => ...)` dentro do JS (liberado, é JS do app).
- `$wire` dentro de `Alpine.data`: acessar por `this.$wire`; propriedades Livewire possivelmente nulas (`$wire.sim`) viram getters que protegem o nulo.
- Chave desligada (padrão): app idêntico ao de hoje; teste da política cobre os dois estados.

---

### Task 1: Chave, política e guarda estático (RED de todo o ciclo)

**Files:**
- Modify: `config/livewire.php` (`csp_safe` → `env('LIVEWIRE_CSP_SAFE', false)`), `app/Http/Middleware/SecurityHeaders.php`, `.env.example`
- Create: `tests/Unit/AlpineCspSubsetTest.php`
- Test: `tests/Feature/Security/SecurityHeadersTest.php`

**Interfaces:**
- Produces: `config('livewire.csp_safe'): bool`; o guarda com `private const PENDENTES = [<caminhos relativos a resources/views>]` que as Tasks 2 a 7 esvaziam.

- [ ] **Step 1: RED, política.** Em `SecurityHeadersTest`: `test_unsafe_eval_so_com_o_build_alpine_normal` (padrão: `script-src` contém `'unsafe-eval'`; com `config(['livewire.csp_safe' => true])`: não contém) e `test_livewire_serve_o_build_csp_quando_a_chave_esta_ligada` (o HTML de `/login` referencia `livewire.js?id=...-csp` só com a chave ligada). Rodar; esperado: FAIL no primeiro.
- [ ] **Step 2: RED, guarda.** Criar `AlpineCspSubsetTest` com um teste que percorre `resources/views/**/*.blade.php`, extrai atributos de expressão (`x-data|x-init|x-show|x-text|x-html|x-for|x-if|x-effect|x-model*|x-bind:*|x-on:*|@evento` e `wire:click|show|model|text|submit|keydown|keyup|change` não-`.live` com valor, e `:atributo` apenas em tags HTML puras, nunca em `<x-*>`, `<flux:*>`, `<livewire:*>`), troca `{{ }}`, `{!! !!}` e `@js(...)` por `0`, remove literais de string e aplica as regras proibidas do spec. Cada violação sai como `arquivo:linha [atributo] regra`. Arquivos em `PENDENTES` são ignorados e há um segundo teste que exige que cada pendente **ainda tenha** violação (a lista não pode apodrecer). Gerar `PENDENTES` rodando o guarda sem a lista. Rodar; esperado: o primeiro passa (tudo pendente), a lista reflete os ~15 arquivos reais.
- [ ] **Step 3:** `config/livewire.php`: `'csp_safe' => (bool) env('LIVEWIRE_CSP_SAFE', false)`. `SecurityHeaders::policy()`: `unsafe-eval` só `unless (config('livewire.csp_safe'))`; atualizar o comentário. `.env.example`: `LIVEWIRE_CSP_SAFE=false` com uma linha explicando.
- [ ] **Step 4:** `php artisan test --filter=SecurityHeadersTest` e `--filter=AlpineCspSubsetTest`; esperado: verde.
- [ ] **Step 5:** `git add` e commit `feat: chave LIVEWIRE_CSP_SAFE e guarda estático das expressões Alpine`.

### Task 2: Cabeçalho global (sino e banner)

**Files:**
- Modify: `resources/views/livewire/alerts/bell.blade.php` (`@click` com `;` e `:style` com crase), `resources/views/livewire/alerts/banner.blade.php:29` (`x-init` com seta e `setTimeout`)
- Create: `resources/js/alerts-header.js` (ou dentro de `alerts.js` se for mais coeso) com `Alpine.data('alertBell')` e `Alpine.data('recoveryBanner')`
- Modify: `resources/js/app.js`, `tests/Unit/AlpineCspSubsetTest.php` (tirar os dois da `PENDENTES`)

**Interfaces:**
- Produces: `alertBell` (estado `open`, `x`, `y`; `toggle(el)` mede `getBoundingClientRect`; getter `menuStyle` = `left: ${x}px; top: ${y}px`), `recoveryBanner` (`init()` agenda `this.$wire.dismissRecovered()` em 10 s).

- [ ] **Step 1: RED.** Remover `bell` e `banner` de `PENDENTES`; rodar o guarda; esperado: FAIL com as linhas.
- [ ] **Step 2:** Implementar os componentes preservando o comportamento (posição do popover abaixo do botão com 8 px de folga; recolher some em 10 s). `:style="menuStyle"`, `@click="toggle($el)"`.
- [ ] **Step 3:** Guarda e testes afetados verdes (`git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`).
- [ ] **Step 4 (navegador, chave ligada):** em qualquer tela, o sino abre e posiciona; o banner de recuperação some sozinho.
- [ ] **Step 5:** commit `refactor: sino e banner em Alpine.data (sem expressão fora do subconjunto)`.

### Task 3: Inbox e Histórico (painel, compositor, miniatura, player)

**Files:**
- Modify: `livewire/inbox/inbox.blade.php` (linhas ~8, 319, 392, 400, 418, 426, 450), `livewire/history/index.blade.php:7`, `livewire/inbox/quote-preview.blade.php:29`, `livewire/inbox/audio-player.blade.php:24,36`
- Create: `resources/js/ticket-details.js` (`Alpine.data('ticketDetails')`: `details` lido de `localStorage 'ticketDetailsOpen'`, `sheet`, `toggle()`); estender `composer-mode.js`/`attach-tray.js` com os métodos que faltam (`onInput($event)` = `remember($event); sync()`, `pickFiles($event)` limpa o input em JS, `escape()` fecha respostas rápidas e emite `composer-focus`, `quickInsert($el)`, `enter($event)`, `focusField()`)
- Modify: `resources/js/audio-player.js` (getters `progressPercent`, `barStyle(bar)`), `resources/js/app.js`, guarda
- Test: `tests/Feature/Livewire/InboxQuickRepliesButtonTest.php:108-109` (atualizar a asserção de `field()?.focus()` para o método novo), `InboxComposerLayoutTest`/`InboxComposerModeTest` se citarem atributo alterado

**Interfaces:**
- Consumes: `composerMode`, `attachTray` existentes (mesmos nomes e argumentos).
- Produces: `ticketDetails`; miniatura sem atribuição a DOM (`onerror` vira método `hideBroken($el)` ou classe condicional).

- [ ] **Step 1: RED.** Tirar os 4 arquivos da `PENDENTES` e ajustar o teste de `InboxQuickRepliesButtonTest` para o markup novo; rodar; esperado: FAIL.
- [ ] **Step 2:** Converter cada atributo (um método por comportamento; `if (!$event.shiftKey) {...}` do Enter vira `enter($event)`; `$event.target.value = ''` vira `pickFiles($event)`).
- [ ] **Step 3:** Testes afetados e guarda verdes; `node --test tests/js` se houver lógica pura nova.
- [ ] **Step 4 (navegador, chave ligada):** conversa abre; painel de detalhes recolhe/expande e lembra no reload (`localStorage`); digitar mostra "digitando"; Enter envia e Shift+Enter quebra linha; anexo seleciona e limpa; respostas rápidas abrem, inserem e Esc fecha devolvendo o foco; miniatura quebrada some; player toca e a onda anima.
- [ ] **Step 5:** commit `refactor: Inbox e Histórico sem expressão fora do subconjunto Alpine CSP`.

### Task 4: Transcrição (menu da mensagem)

**Files:**
- Modify: `livewire/inbox/transcript.blade.php` (~80, 125, 171, 175, 193), `resources/js/` novo `message-menu.js`, `app.js`, guarda

**Interfaces:**
- Produces: `Alpine.data('messageMenu')` com `open`, `info`, `style`, `toggle()` (posiciona o menu: `top:0;left:0;visibility:hidden` e depois calcula, usando `$nextTick` e `getBoundingClientRect` dentro do JS), `copy(body)`, `noteFrom(body)`, `startEdit(body)`, `saveEdit(id, text)` (chama `this.$wire.$parent.editMessage(id, text)`, depois `editing = false`).

- [ ] **Step 1: RED.** Tirar `transcript.blade.php` da `PENDENTES`; esperado: FAIL.
- [ ] **Step 2:** Converter; o corpo da mensagem que hoje entra por `@js($message->body)` passa por argumento de método ou `data-body` (sem concatenar HTML). Manter `x-bind:style="style"` (teste `InboxReactTest:87`).
- [ ] **Step 3:** `php artisan test --filter=InboxTranscriptTest` e afetados; guarda verde.
- [ ] **Step 4 (navegador):** menu abre no lugar certo, não sai da tela; copiar põe no clipboard; "Nota" abre a nota com o texto; editar abre, salva e fecha; apagar pede confirmação.
- [ ] **Step 5:** commit `refactor: menu da mensagem em Alpine.data`.

### Task 5: Editor de automação

**Files:**
- Modify: `livewire/automation/editor.blade.php` (14 atributos: ~42, 64, 109, 119, 133, 135, 138, 139, 146, 149, 176 a 214, 244 e o `x-data` de abertura), `resources/js/flow-editor.js` (e `flow-editor/`), `app.js`, guarda
- Test: `tests/Feature/Livewire/AutomationEditorTest.php`, `AutomationSimulatorTest.php`, `AccessibleNamesTest.php` (se citarem o `x-data`), `tests/js` se a assinatura do componente mudar

**Interfaces:**
- Consumes: `flowEditor` atual (assinatura `($wire, payload)`).
- Produces: `flowEditor` sem argumentos no atributo (`x-data="flowEditor"`), lendo o payload de um `<script type="application/json" data-flow-payload>` (ou `data-payload` escapado) e o `$wire` por `this.$wire`; getters `simErrorsAny`, `simLog`, `simOutcome`, `simTrail`, `simSession` que protegem `$wire.sim` nulo; `menuStyle` (sem crase no atributo); `fieldValue(key)`/`setField(key, value)` se o `x-model` com `[chave]` não passar no navegador.

- [ ] **Step 1: RED.** Tirar `editor.blade.php` da `PENDENTES` e ajustar os testes que citam o `x-data` antigo; esperado: FAIL.
- [ ] **Step 2:** Mover o payload para fora do atributo; trocar cada `?.`/`??`/seta/crase por getter ou método; `x-for="(line, index) in simLog"`.
- [ ] **Step 3:** `php artisan test --filter=Automation` e `node --test tests/js`; guarda verde.
- [ ] **Step 4 (navegador, chave ligada):** zero `Alpine Expression Error`; abrir, arrastar e ligar blocos, editar campos de cada tipo (texto, número, seleção) e ver o valor persistir, desfazer/refazer, menu de contexto, Organizar, painel Testar (enviar mensagem de teste, ver trilha e desfecho), Salvar rascunho, Validar e Publicar.
- [ ] **Step 5:** commit por etapa (payload, simulador, campos) e um final `refactor: editor de automação no subconjunto Alpine CSP`.

### Task 6: Configuração (Setores, Empresa, Alertas)

**Files:**
- Modify: `livewire/settings/sectors.blade.php` (25, 27, 30), `livewire/settings/company.blade.php` (27, 41), `pages/settings/⚡alerts.blade.php` (95, 126), `resources/js/` novos `sector-row.js`, `signature-toolbar.js`, `alerts-settings.js`, `app.js`, guarda

**Interfaces:**
- Produces: `sectorRow` (`editing`, `name`, `start()` foca o campo, `cancel(original)`, `save(id)` chama `$wire.rename(id, name)` e fecha), `signatureToolbar` (`wrap(mark)` com `setRangeText`, mesmo comportamento), `alertsSettings` (`ask()` pede a permissão de notificação; `preview(kind)` dispara `alerts-preview`).

- [ ] **Step 1: RED.** Tirar os três da `PENDENTES`; esperado: FAIL.
- [ ] **Step 2:** Converter (o botão de crase passa `'```'` como argumento string: permitido, a crase está dentro de uma string; o guarda ignora literais de string).
- [ ] **Step 3:** Testes afetados (`SectorsPageTest`, `CompanyPageTest`, `AlertSettingsPageTest`...) e guarda verdes.
- [ ] **Step 4 (navegador):** renomear setor (Enter salva, Esc cancela); barra de formatação da assinatura envolve a seleção com `*`, `_`, `~`, crase tripla; testes de som dos alertas e pedido de permissão.
- [ ] **Step 5:** commit `refactor: Configuração (setores, empresa, alertas) no subconjunto Alpine CSP`.

### Task 7: Autenticação e segurança (passkeys e 2FA)

**Files:**
- Modify: `components/passkey-registration.blade.php` (6, 92), `components/passkey-verify.blade.php:14`, `pages/auth/two-factor-challenge.blade.php:6`, `pages/settings/⚡two-factor-setup-modal.blade.php` (191, 267), `resources/js/passkeys.js` (já existe) e novos `two-factor.js`, `app.js`, guarda

**Interfaces:**
- Produces: `passkeyRegistration`, `passkeyVerify`, `twoFactorChallenge`, `twoFactorSetup` (`copied`, `copy(key)`), cada um com os mesmos estados de hoje (`supported`, `loading`, `error`, `showForm`, `name`...); `window.Passkeys` continua sendo lido só dentro do JS.

- [ ] **Step 1: RED.** Tirar os quatro da `PENDENTES`; esperado: FAIL.
- [ ] **Step 2:** Converter preservando mensagens de erro e foco (`focusOtp`, `focusName`).
- [ ] **Step 3:** `php artisan test --filter=TwoFactor` e `--filter=Passkey`, afetados e guarda verdes.
- [ ] **Step 4 (navegador, chave ligada):** 2FA: ativar no usuário de teste, ver o modal, o QR e o botão copiar; gerar o código TOTP por script a partir do segredo gravado no banco de teste e confirmar; desafio de 2FA no login aceita código e código de recuperação; passkeys: telas iniciam sem erro e os botões respondem (o prompt do sistema fica para o dono).
- [ ] **Step 5:** commit `refactor: passkeys e 2FA no subconjunto Alpine CSP`.

### Task 8: Verificação geral com a chave ligada e documentação

**Files:**
- Modify: `tests/Unit/AlpineCspSubsetTest.php` (`PENDENTES` vazia e o teste de "lista não apodrece" passa por vazio), `docs/superpowers/reports/2026-11-12-csp-sem-unsafe-eval-levantamento.md` (correções do parser) ou novo relatório
- Create: `docs/superpowers/reports/2026-11-12-csp-sem-unsafe-eval.md`

- [ ] **Step 1:** `PENDENTES = []`; guarda verde, sem exceção.
- [ ] **Step 2:** Servidor local com `LIVEWIRE_CSP_SAFE=true CSP_MODE=enforce`; percorrer todas as telas (Fila, Contatos, Histórico, Relatórios, Auditoria, as 9 de Configuração, editor, login e 2FA) lendo o console; esperado: zero `Alpine Expression Error` e zero violações de CSP (exceto o `wss://localhost:8080` do ambiente).
- [ ] **Step 3:** Repetir os roteiros das Tasks 2 a 7 de uma vez com a chave ligada; registrar no ledger.
- [ ] **Step 4:** Relatório: o que mudou, a gramática real do parser (corrige o levantamento: `a[b]` e `a.b = ...` em objeto comum funcionam; `;`, `?.`, `if` não), como ligar a chave no beta (variável no Coolify + restart), rollback (`LIVEWIRE_CSP_SAFE=false`), e o roteiro de 2 passos dos passkeys para o dono.
- [ ] **Step 5:** commit `docs: relatório da CSP sem unsafe-eval e chave desligada por padrão`.

### Task 9: Portão 7, PR e CI

- [ ] **Step 1:** `vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`, `node --test tests/js`, `npm run build`, suíte com Redis (`CACHE_STORE=redis ... php artisan test --parallel`); todos com saída 0.
- [ ] **Step 2:** autorrevisão do diff inteiro (portão 6, autorizado pelo dono para este ciclo) registrada no ledger.
- [ ] **Step 3:** push, PR (corpo com o que muda, a chave, o roteiro de verificação e o que fica para depois), esperar o CI verde com `gh pr checks --watch`; **não fazer merge**; avisar o dono.
