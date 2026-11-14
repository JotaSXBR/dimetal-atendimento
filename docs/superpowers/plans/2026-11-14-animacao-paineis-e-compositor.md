# Animação dos painéis flutuantes e do compositor: plano de implementação

> **Para quem executa:** SUB-SKILL OBRIGATÓRIA: `superpowers:executing-plans` (execução inline, regra do dono). Os passos usam checkbox (`- [ ]`).

**Objetivo:** painéis flutuantes nascem do gatilho e o compositor cresce em vez de pular, sem animar fila, transcript nem ações de teclado.

**Arquitetura:** `x-transition` do Alpine com classes Tailwind nos painéis; `x-collapse` (plugin do Alpine presente no bundle CSP do Livewire) nas faixas de altura; a posição/origem do menu da mensagem sai para um módulo puro testável com `node --test`. Sem dependência nova. Testes de marcação em PHP (Livewire `->html()`) fixam os atributos; o feel check é no Chrome DevTools.

**Stack:** Laravel 13, Livewire 4.4 (build CSP do Alpine), Flux 2, Tailwind 4, `node --test` para JS.

**Spec:** `docs/superpowers/specs/2026-11-14-animacao-paineis-e-compositor-design.md`. Referências de apoio (locais, não versionadas, apagadas na Tarefa 7): `plans/001` a `plans/003`.

## Restrições globais

- Curva de painéis: `cubic-bezier(0.23, 1, 0.32, 1)`; entrada **150ms**, saída **100ms**; `opacity` + `scale .95→1`; nunca `scale(0)`.
- Faixas de altura: **200ms**. Só `opacity`, `transform` e altura (altura só via `x-collapse`).
- **Não alterar** `--ease-out` (`cubic-bezier(0.22, 1, 0.36, 1)`) nem `--duration-base` de `resources/css/app.css:37-38`.
- **Não animar:** fila e Histórico, bolhas e chegada de mensagens, atalhos de teclado, "digitando", SLA, `flux:dropdown`, toast. **"Respondendo a" não anima** (Alt+P).
- Build CSP do Alpine: nenhuma expressão nova fora do subconjunto (`tests/Unit/AlpineCspSubsetTest.php`); lógica em `resources/js`, atributos só com ternário/comparação/propriedade. Sem seta, sem crase, sem método em `x-data` inline.
- `prefers-reduced-motion` já zera transições em `app.css:123-131`; não adicionar classe extra.
- Origens: sino `top left`; emoji `bottom left`; reações `right` se `$out` senão `left`; menu da mensagem `top|bottom right` conforme abra abaixo ou acima.
- Textos e comentários em português. Commits em português com prefixo (`feat:`), um por tarefa.

## Review Focus

- Menu da mensagem perto do rodapé da conversa (abre acima): a origem deve ser `bottom right`, e o menu não pode piscar invisível.
- Abrir e fechar o mesmo painel em sequência rápida: não reinicia do zero nem deixa o painel preso aberto/fechado.
- Faixa de áudio fechada: botões e `<audio>` não recebem foco nem leitor de tela (`inert`/`aria-hidden`/`x-show`).
- Adicionar o 2º e o 3º arquivo à bandeja: a bandeja não reanima a cada arquivo (estado `shown` sobrevive ao morph).
- Conversa sem permissão de resposta: o compositor read-only não ganha atributos de animação que dependam de `x-data` ausente.

---

### Tarefa 1: Módulo puro de posicionamento do menu da mensagem

**Files:**
- Create: `resources/js/menu-placement.js`
- Create: `tests/js/menu-placement.test.mjs`
- Modify: `resources/js/message-menu.js:46-59` (`place()` passa a delegar)

**Interfaces:**
- Produces: `placeMenu({ button, menuWidth, menuHeight, area, viewportWidth, viewportHeight }, margin = 8, gap = 4): { top: number, left: number, above: boolean }`. `button` é `{top, bottom, right}`; `area` é `{top, bottom} | null`. `above` é verdadeiro quando o menu abre acima do botão.

- [ ] **Passo 1: teste falhando** em `tests/js/menu-placement.test.mjs` (estilo de `composer-insert.test.mjs`): (a) botão no alto com espaço abaixo → `{ above: false, top: button.bottom + 4 }` e `left = button.right - menuWidth` limitado a `margin`; (b) botão perto do rodapé da `area` → `above: true` e `top = button.top - menuHeight - 4`; (c) `area = null` usa a viewport; (d) menu mais largo que o espaço à esquerda → `left` limitado a `margin` (8); (e) menu mais alto que a área toda → `top` nunca menor que `area.top + margin`.
- [ ] **Passo 2: rodar** `node --test tests/js/menu-placement.test.mjs`. Esperado: FALHA (módulo inexistente).
- [ ] **Passo 3: implementar** `placeMenu` copiando a aritmética de `message-menu.js:49-56` (`floor`, `ceiling`, `below`, `wanted`, `top`, `left`) e devolvendo `above: wanted !== below`.
- [ ] **Passo 4: rodar** o teste. Esperado: PASSA.
- [ ] **Passo 5: usar o módulo** em `message-menu.js`: `place()` lê os `getBoundingClientRect()`/`offsetWidth`/`offsetHeight`, chama `placeMenu` e grava `this.style = \`top:${top}px;left:${left}px;transform-origin:${above ? 'bottom' : 'top'} right\`` (a origem vai dentro de `style`, que já é bindado). Nada de template-string em **atributo Blade**: isto é JS (a restrição CSP vale só para atributos).
- [ ] **Passo 6: rodar** `node --test tests/js` e `npm run build`. Esperado: verdes.
- [ ] **Passo 7: commit** `feat: posição do menu da mensagem em módulo testável, com a origem da animação`.

### Tarefa 2: Menu da mensagem anima a partir do botão

**Files:**
- Modify: `resources/js/message-menu.js` (estado `placed`)
- Modify: `resources/views/livewire/inbox/transcript.blade.php:149`
- Create: `tests/Feature/Livewire/InboxMotionMarkupTest.php`

**Interfaces:**
- Consumes: `place()` da Tarefa 1.
- Produces: propriedade Alpine `placed` em `messageMenu`; `InboxMotionMarkupTest` (helpers `inbox()` e `ticketComMensagem()`), reaproveitado pelas Tarefas 3 a 5.

- [ ] **Passo 1: teste falhando** `test_o_menu_da_mensagem_tem_entrada_pelo_placed_e_saida_por_x_transition`: monta ticket do atendente logado com uma `Message` de entrada (`Message::factory()->for($ticket)`), `Livewire::actingAs(...)->test(Inbox::class)->call('select', id)->html()`, e afirma que o trecho do `x-ref="menu"` contém `x-bind:class="placed`, `x-transition:leave="transition duration-100` e `ease-[cubic-bezier(0.23,1,0.32,1)]`. Copiar o `setUp` de `InboxComposerLayoutTest`.
- [ ] **Passo 2: rodar** `php artisan test --filter=InboxMotionMarkupTest`. Esperado: FALHA.
- [ ] **Passo 3: JS.** Em `messageMenu`: `placed: false`; em `toggle()`, ao abrir, `this.placed = false` junto do `style` escondido; no fim de `place()`, `this.placed = true`; ao fechar, `placed` volta a `false`.
- [ ] **Passo 4: Blade.** No div `x-ref="menu"` (`:149`): manter `x-show`, `x-cloak`, `x-bind:style`; acrescentar `x-bind:class="placed ? 'opacity-100 scale-100' : 'opacity-0 scale-95'"`, a classe fixa `transition duration-150 ease-[cubic-bezier(0.23,1,0.32,1)]`, e a **saída** por `x-transition:leave="transition duration-100 ease-out"`, `x-transition:leave-start="opacity-100 scale-100"`, `x-transition:leave-end="opacity-0 scale-95"`. A medição por `offsetWidth/offsetHeight` não muda com `scale`.
- [ ] **Passo 5: rodar** `php artisan test --filter=InboxMotionMarkupTest` e `vendor/bin/phpunit tests/Unit/AlpineCspSubsetTest.php`. Esperado: PASSAM.
- [ ] **Passo 6: commit** `feat: menu da mensagem nasce do botão (entrada após posicionar, saída curta)`.

### Tarefa 3: Sino, emoji e reações rápidas

**Files:**
- Modify: `resources/views/livewire/alerts/bell.blade.php:15`
- Modify: `resources/views/livewire/inbox/inbox.blade.php:439`
- Modify: `resources/views/livewire/inbox/transcript.blade.php:200`
- Test: `tests/Feature/Livewire/InboxMotionMarkupTest.php`

**Interfaces:** consome `InboxMotionMarkupTest` da Tarefa 2.

- [ ] **Passo 1: testes falhando** em `InboxMotionMarkupTest`: `test_o_sino_cresce_do_canto_superior_esquerdo` (renderiza `Livewire::actingAs($user)->test(Bell::class)->html()`, afirma `origin-top-left`, `x-transition:enter="transition duration-150` e ausência de `x-transition.opacity`); `test_o_emoji_sobe_do_botao` (HTML da Inbox contém `origin-bottom-left` no painel `x-ref="panel"`); `test_a_barra_de_reacao_abre_do_lado_da_bolha` (mensagem de saída → `origin-right`; mensagem de entrada → `origin-left`, dentro de `data-testid="quick-react"`).
- [ ] **Passo 2: rodar** o filtro. Esperado: FALHA.
- [ ] **Passo 3: aplicar** nos três elementos os seis atributos `x-transition:enter/enter-start/enter-end/leave/leave-start/leave-end` com os valores das Restrições globais (enter `transition duration-150 ease-[cubic-bezier(0.23,1,0.32,1)]` de `opacity-0 scale-95` a `opacity-100 scale-100`; leave `transition duration-100 ease-out`), mais a classe de origem: sino `origin-top-left` (trocando `x-transition.opacity`); emoji `origin-bottom-left`; reações `{{ $out ? 'origin-right' : 'origin-left' }}`. Não tocar o botão `x-show="! open"` ao lado das reações.
- [ ] **Passo 4: rodar** `php artisan test --filter=InboxMotionMarkupTest`, `AlpineCspSubsetTest` e `php artisan test --filter=Alert`. Esperado: PASSAM (se algum teste do sino procurava `x-transition.opacity`, atualizar o assert).
- [ ] **Passo 5: commit** `feat: sino, emoji e reações rápidas nascem do gatilho`.

### Tarefa 4: Faixas de gravação e prévia de áudio

**Files:**
- Modify: `resources/views/livewire/inbox/inbox.blade.php:397-411`
- Test: `tests/Feature/Livewire/InboxMotionMarkupTest.php`

**Interfaces:** consome `InboxMotionMarkupTest`.

- [ ] **Passo 1: teste falhando** `test_as_faixas_de_audio_abrem_pela_altura`: o HTML da Inbox contém, para `phase === 'recording'` e `phase === 'preview'`, um wrapper com `x-collapse.duration.200ms` e a faixa interna com `mb-2` (a margem **dentro** do wrapper, não nele).
- [ ] **Passo 2: rodar.** Esperado: FALHA.
- [ ] **Passo 3: implementar:** cada faixa vira `<div x-show="phase === '...'" x-collapse.duration.200ms><div class="mb-2 ...classes atuais da faixa...">…</div></div>`; sai o `x-show`/`x-cloak` da faixa interna. Manter `role="timer"` e o `<p x-show="error" x-cloak>` fora do escopo.
- [ ] **Passo 4: rodar** o filtro, `AlpineCspSubsetTest` e `php artisan test --filter=InboxVoice`. Esperado: PASSAM.
- [ ] **Passo 5: commit** `feat: faixas de gravação e prévia de áudio abrem pela altura`.

### Tarefa 5: Bandeja de anexos (altura, com queda para fade)

**Files:**
- Modify: `resources/js/attach-tray.js` (novo `Alpine.data('revealOnInsert')`)
- Modify: `resources/views/livewire/inbox/inbox.blade.php:374-377`
- Test: `tests/Feature/Livewire/InboxMotionMarkupTest.php`

**Interfaces:**
- Produces: `Alpine.data('revealOnInsert', () => ({ shown: false, init() { this.$nextTick(() => { this.shown = true; }); } }))` (registrado em `alpine:init`, dentro do `document.addEventListener` existente).

- [ ] **Passo 1: teste falhando** `test_a_bandeja_de_anexos_entra_pela_altura_e_o_respondendo_a_nao_anima`: com um anexo na bandeja (usar o `attach()` do `InboxAttachmentsTest`), o HTML contém wrapper `x-data="revealOnInsert"` + `x-show="shown"` + `x-collapse.duration.200ms`; e o bloco `Respondendo a` (com `replyTo` definido) **não** contém `x-collapse` nem `starting:`.
- [ ] **Passo 2: rodar.** Esperado: FALHA.
- [ ] **Passo 3: implementar:** wrapper `<div x-data="revealOnInsert" x-show="shown" x-collapse.duration.200ms>` em volta do `<ul>`; o `<ul>` mantém `x-show="!voiceBusy"`, `wire:key` nos `<li>` e `mb-2`. Registrar `revealOnInsert` em `attach-tray.js`.
- [ ] **Passo 4: rodar** o filtro, `AlpineCspSubsetTest` e `php artisan test --filter=InboxAttachments`. Esperado: PASSAM.
- [ ] **Passo 5: validar no navegador (Chrome DevTools)** com servidor local (receita no `CLAUDE.md`/memória "servidor local com banco próprio"): anexar 1, 2 e 3 arquivos. **Queda:** se a bandeja pisca, reinicia ao anexar o 2º arquivo ou não anima, **trocar** por `starting:opacity-0 starting:-translate-y-1 transition-[opacity,translate] duration-200 ease-[cubic-bezier(0.23,1,0.32,1)]` no `<ul>`, remover o wrapper e o `revealOnInsert`, ajustar o teste e anotar `Desvio:` no ledger.
- [ ] **Passo 6: commit** `feat: bandeja de anexos entra pela altura`.

### Tarefa 6: Validação visual no navegador

**Files:** nenhum (só verificação).

- [ ] **Passo 1:** subir o servidor local com banco próprio, abrir uma conversa em atendimento e conferir com o Chrome DevTools: origem de cada painel (menu abaixo e perto do rodapé, emoji, reações de entrada e de saída, sino), 10% de velocidade, `prefers-reduced-motion`, e que a última mensagem continua visível enquanto as faixas abrem.
- [ ] **Passo 2:** gravar um áudio de teste: faixa vermelha e prévia abrem e fecham sem salto; `Tab` não entra nos botões escondidos; descartar várias vezes seguidas não reinicia do zero.
- [ ] **Passo 3:** se a curva padrão do `x-collapse` não agradar, trocar as faixas pelo grid `0fr→1fr` do `plans/002` (nova tarefa com RED) e registrar `Desvio:`.
- [ ] **Passo 4:** mostrar ao dono os resultados (capturas) e registrar `Task 6: complete` no ledger.

### Tarefa 7: Fechamento da fatia

**Files:**
- Delete: `plans/` (diretório local, não versionado)

- [ ] **Passo 1:** apagar `plans/` (referências usadas; o plano formal é este documento).
- [ ] **Passo 2:** portão 6 e 7 do `CLAUDE.md`: `requesting-code-review` (perguntar ao dono sobre o revisor), depois `verification-before-completion` (Pint, Larastan, PHPMD, `node --test tests/js`, `npm run build`, suíte com Redis). Ler o código de saída de cada um.
- [ ] **Passo 3:** portão 8: `finishing-a-development-branch` (PR só se o dono pedir).
