# UX fatias 4 e 5: compositor do Inbox, correções medidas e QA de acessibilidade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A barra de mensagem do Inbox vira uma linha larga (`[emoji] [anexo] [respostas rápidas] [campo] [mic ou Enviar]`), a assinatura passa a ser sempre ativa, as telas ganham as correções que a auditoria mediu e o app passa no QA de acessibilidade (WCAG 2.2 AA) nos dois temas.

**Architecture:** O compositor continua sendo o `<form data-testid="composer-form">` de `resources/views/livewire/inbox/inbox.blade.php`, com Alpine (`composerMode`, `attachTray`, `voiceRecorder`, `typingPresence`) e Livewire como hoje. Inserção de texto no cursor (emoji e resposta rápida) é uma função pura testável (`resources/js/composer-insert.js`) chamada por um método novo de `composerMode`. O seletor de emoji é o web component `emoji-picker-element`, carregado sob demanda, com dados em português servidos do próprio app. A auditoria vira um script versionado que se roda antes e depois.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2.21 (grátis), Alpine, Tailwind 4, `emoji-picker-element` (Apache-2.0), PHPUnit, `node --test`, Lighthouse (DevTools MCP).

**Spec:** `docs/superpowers/specs/2026-11-11-ux-compositor-e-qa-design.md`.

## Global Constraints

- Um PR só; sem mudar regra de negócio, dados, rotas, portões `can:*`, tempo real, a Fila nem o fluxo de envio (limites, fila de saída, citação, bandeja de anexos, gravação de áudio, "digitando…").
- Uma dependência npm nova permitida: `emoji-picker-element` (Apache-2.0). Nenhuma outra, nenhuma no Composer. Dados do emoji servidos do próprio app: **nenhuma requisição a CDN, fonte ou imagem de terceiro**.
- Cores só pelos tokens do app (`ThemeTokensTest` continua verde); botões só `flux:button` (regra de `docs/ui-botoes.md`, `ViewConventionsTest` com a contagem de botões crus atualizada com motivo se mudar).
- Todo botão só com ícone: `aria-label` e tooltip (`title`); alvo de pelo menos 32 px; foco visível; nada novo anima fora de `motion-safe`.
- Assinatura: vale quando a empresa a liga (`CompanySettings::signatureEnabled()`); a escolha por atendente sai do código e da tela; a coluna `users.sign_messages` **fica** (sem migração destrutiva).
- Modo Nota privada: somem anexo e mic; ficam emoji e respostas rápidas; o botão final é "Salvar nota". Enter envia, Shift+Enter quebra linha, `/atalho` + Enter continuam como hoje.
- Textos em português. Commits em português com prefixo, um por tarefa. Sem referência a projetos de terceiros em código, commit e PR.
- Edição com a ferramenta `Edit`; script Python no Windows grava CRLF (Pint reprova): `sed -i 's/\r$//'`. Blade condensado na leitura: original por `caveman_retrieve`.
- Testes: Postgres de teste no ar (o `pg-test`, porta 5432; o openbot foi desinstalado) e `php artisan test --parallel`; JS com `node --test tests/js`.

## Review Focus

1. Emoji ou resposta rápida inseridos com texto já digitado: entram **na posição do cursor**, sem apagar o que existe, e no modo Nota vão ao rascunho da nota. (Task 3)
2. Mic só vira Enviar com texto **não vazio** (só espaços não conta) ou arquivo na bandeja; depois de enviar volta a ser mic; ao trocar de ticket com rascunho salvo já mostra Enviar. (Task 2)
3. A assinatura continua aplicada com `users.sign_messages = false` no banco e na mensagem inicial de "Nova conversa". (Task 1)
4. O seletor de emoji não faz nenhuma requisição externa, abre no tema certo (claro ou escuro) e fecha com Esc ou clique fora devolvendo o foco ao campo. (Task 4)
5. O atalho `/` continua listando sugestões e o Enter não envia a mensagem enquanto se escolhe no popover de respostas rápidas. (Task 3)

---

### Task 1: Assinatura sempre ativa

**Files:**
- Modify: `app/Services/Inbox/Signature.php:16-29` (`apply`), `app/Livewire/Inbox/Concerns/ComposesMessages.php` (remover `$sign`, `mountComposesMessages`, `toggleSign`), `resources/views/livewire/inbox/inbox.blade.php` (remover o botão de assinatura, ~linha 409)
- Modify (texto): a cópia da tela Empresa e assinatura que cita "por quem envia" (`resources/views/livewire/settings/company.blade.php`, se existir)
- Test: `tests/Feature/Inbox/SignatureTest.php` e os testes que citam `sign_messages` ou `toggleSign` (`grep -rln "sign_messages\|toggleSign" tests`)

**Interfaces:**
- Produces: `Signature::apply(string $text, User $author, ?Sector $sector): string` — mesma assinatura; deixa de ler `$author->sign_messages`. `ComposesMessages` perde `public bool $sign` e `toggleSign()`.

- [ ] **Step 1: Write the failing tests** em `SignatureTest`: `test_a_assinatura_vale_mesmo_com_sign_messages_falso_no_banco` (usuário com `sign_messages = false`, assinatura ligada na empresa → `apply` devolve o texto assinado); ajustar `test_desligada_no_geral_ou_pelo_atendente_ou_sem_texto` para só os casos "desligada na empresa" e "sem texto" (o caso "pelo atendente" some). Em `InboxReplyTest` (ou o teste de envio existente): `test_enviar_assina_sem_nenhum_controle_na_tela` (a tela renderizada não contém `Assinatura ligada` nem `toggleSign`, e a mensagem enviada sai assinada). Em `StartConversationTest`: o caso do atendente com `sign_messages = false` também assina.
- [ ] **Step 2: Run** `php artisan test --filter='SignatureTest|InboxReplyTest|StartConversationTest'` → Expected: FAIL nos três testes novos.
- [ ] **Step 3: Implement** as remoções acima e `apply` sem a condição do atendente; marcar `users.sign_messages` como sem uso num comentário do `User` (a coluna fica).
- [ ] **Step 4: Run** a mesma linha mais `php artisan test --filter=Inbox` → Expected: PASS. `grep -rn "toggleSign\|\$sign\b" app resources tests` → sem resultado.
- [ ] **Step 5: Commit** `feat: a assinatura das mensagens é sempre ativa, sem botão no compositor`.

### Task 2: Compositor em uma linha

**Files:**
- Modify: `resources/views/livewire/inbox/inbox.blade.php:~355-430` (o `<form data-testid="composer-form">`), `resources/js/composer-mode.js`
- Create: `tests/Feature/Livewire/InboxComposerLayoutTest.php`

**Interfaces:**
- Produces (markup): dentro do form, **linha 1** `data-testid="composer-modes"` com as abas (sempre em linha própria, sem a lógica `@max-xl`); **linha 2** `data-testid="composer-tools"` com, na ordem: botão Emoji (`aria-label="Emoji"`, placeholder até a Task 4), Anexar (`aria-label="Anexar arquivo"`), Respostas rápidas (`aria-label="Respostas rápidas"`, placeholder até a Task 3), o campo (`flex-1`, `min-w-0`) e, à direita, **mic ou Enviar**.
- Produces (Alpine, `composerMode`): `filled: boolean` (o campo tem texto não vazio) e `sync(): void` (recalcula `filled` do campo); chamado em `set()`, em `remember()`, em todo `input` do campo e no `.then()` do `submit()` (`this.$nextTick(() => this.sync())`). Função pura exportada de `resources/js/composer-insert.js` (Task 3): nenhuma aqui.
- Mic/Enviar: sem arquivo na bandeja (`$attachments === []`, decidido no Blade) mostra o mic quando `!filled` e o Enviar (`aria-label="Enviar"`, `type=submit`, `icon="paper-airplane"`) quando `filled`; com arquivo na bandeja só o Enviar. No modo Nota some o mic e o anexo, fica "Salvar nota" como hoje.

- [ ] **Step 1: Write the failing tests** em `InboxComposerLayoutTest` (Livewire, atendente responsável, ticket em atendimento): `test_a_linha_de_ferramentas_tem_emoji_anexo_respostas_campo_e_mic_nessa_ordem` (posições relativas dos `aria-label`/placeholder no HTML), `test_nao_ha_botao_de_assinatura`, `test_com_arquivo_na_bandeja_so_o_enviar_aparece_e_nao_o_mic` (anexo de teste via `WithFileUploads` do Livewire), `test_sem_arquivo_o_html_traz_mic_e_enviar_controlados_por_filled`, `test_as_abas_ficam_em_linha_propria_acima_das_ferramentas` (`composer-modes` antes de `composer-tools` no HTML). Atualizar os testes de `InboxReplyTest`/`InboxAttachmentsTest` que dependam da ordem antiga.
- [ ] **Step 2: Run** `php artisan test --filter=InboxComposerLayoutTest` → Expected: FAIL.
- [ ] **Step 3: Implement** o markup e o estado `filled`/`sync()` em `composer-mode.js`; remover do form os `@max-xl` e o `basis-full` das abas; o campo ganha `min-w-0 flex-1` e perde `min-w-28`. Em 1280 px (painel de contato aberto) o campo deve passar de 500 px (verificar na Task 9).
- [ ] **Step 4: Run** `php artisan test --filter='InboxComposerLayoutTest|InboxReplyTest|InboxAttachmentsTest|ViewConventionsTest'` e `npm run build` → Expected: PASS / exit 0. Conferir no navegador (dois temas, 1280 e 1920): digitar mostra Enviar, apagar volta ao mic, só espaços fica no mic (Review Focus 2).
- [ ] **Step 5: Commit** `feat: compositor do Inbox em uma linha, com mic que vira Enviar`.

### Task 3: Respostas rápidas por botão e inserção no cursor

**Files:**
- Create: `resources/js/composer-insert.js`, `tests/js/composer-insert.test.mjs`
- Modify: `resources/js/composer-mode.js`, `resources/js/app.js` (import, se o projeto importa cada arquivo ali), `app/Livewire/Inbox/Concerns/ComposesMessages.php` (propriedade `public bool $showQuickReplies = false;`, método `toggleQuickReplies(): void`, `allQuickReplies(): Collection`), `resources/views/livewire/inbox/inbox.blade.php`
- Test: `tests/Feature/Livewire/InboxQuickRepliesButtonTest.php`

**Interfaces:**
- Produces (JS puro): `insertAt(value: string, start: number, end: number, text: string): { value: string, caret: number }` — troca o trecho `[start, end)` por `text` e devolve o novo valor e a posição do cursor logo depois do texto inserido. `start`/`end` fora do intervalo são limitados a `[0, value.length]`.
- Produces (Alpine): `composerMode.insert(text: string): void` — lê `selectionStart/End` do campo, aplica `insertAt`, grava o valor, move o cursor, dispara o evento `input` sintético (como `set()`), chama `sync()`, devolve o foco ao campo e `save()` no rascunho do **modo atual**.
- Produces (PHP): `ComposesMessages::allQuickReplies(): Collection<int, QuickReply>` = `QuickReply::forUser($this->user())->orderBy('shortcut')->limit(50)->get()`; `toggleQuickReplies()` inverte `$showQuickReplies` (autoriza `reply`). O popover lista cada resposta como `<button type="button" data-body="{{ $r->body }}" x-on:click="insert($el.dataset.body); $wire.set('showQuickReplies', false)">` com `/atalho` e o início do texto; vazio mostra "Nenhuma resposta rápida. Cadastre em Configuração." O botão tem `aria-expanded` e abre/fecha com Esc e clique fora.

- [ ] **Step 1: Write the failing tests:** JS (`composer-insert.test.mjs`): `insertAt: no meio, no início, no fim, com seleção substituída e com índices fora do intervalo` (assertar `value` e `caret`); PHP (`InboxQuickRepliesButtonTest`): `test_o_botao_abre_a_lista_so_com_as_respostas_do_usuario_e_as_compartilhadas` (use `QuickReply::forUser`), `test_lista_vazia_orienta_a_cadastrar`, `test_o_popover_nao_envia_a_mensagem` (abrir e escolher não chama `send`), `test_o_atalho_barra_continua_listando_sugestoes` (Review Focus 5, regressão).
- [ ] **Step 2: Run** `node --test tests/js` e `php artisan test --filter=InboxQuickRepliesButtonTest` → Expected: FAIL.
- [ ] **Step 3: Implement** conforme as interfaces; `useQuickReply` e o `/` ficam intactos. Enter dentro do popover não envia (o popover está fora do `<form>` submit ou o botão é `type="button"`).
- [ ] **Step 4: Run** os dois comandos e `npm run build` → Expected: PASS. No navegador: texto digitado + resposta rápida = inserida no cursor sem apagar; no modo Nota entra no rascunho da nota (Review Focus 1).
- [ ] **Step 5: Commit** `feat: botão de respostas rápidas no compositor, com inserção no cursor`.

### Task 4: Emoji com dados em português locais

**Files:**
- Modify: `package.json` e `package-lock.json` (`emoji-picker-element`), `resources/js/app.js`, `resources/views/livewire/inbox/inbox.blade.php`, `resources/js/composer-mode.js` (só se faltar algo para foco/Esc)
- Create: `resources/js/emoji-picker.js` (Alpine `emojiPicker`), `public/vendor/emoji/pt.json` (cópia de `emoji-picker-element-data/pt/emojibase/data.json`), `tests/Feature/EmojiDataTest.php`

**Interfaces:**
- Consumes: `composerMode.insert(text)` (Task 3).
- Produces: componente Alpine `emojiPicker`: `open: boolean`, `toggle()`, `close()`; na primeira abertura importa dinamicamente `emoji-picker-element` e cria o `<emoji-picker>` com `dataSource: '/vendor/emoji/pt.json'`, `locale: 'pt'`, textos em português (`i18n` do pacote; se o pacote tiver `i18n/pt`, usar), classe `dark`/`light` conforme o tema do app (`document.documentElement.classList.contains('dark')`, reavaliada a cada abertura); no evento `emoji-click` chama `insert(event.detail.unicode)` e **mantém o seletor aberto** só se Shift estiver pressionado, senão fecha. O elemento fica sob `wire:ignore`. Esc e clique fora fecham e devolvem o foco ao campo. O botão tem `aria-label="Emoji"`, `aria-expanded`, `aria-haspopup="dialog"`.

- [ ] **Step 1: Write the failing test** `EmojiDataTest`: `test_os_dados_do_seletor_estao_no_app_e_em_portugues` (o arquivo `public/vendor/emoji/pt.json` existe, é JSON de lista não vazia e ao menos um item traz anotação em português, por exemplo o rosto sorrindo); `test_o_compositor_nao_referencia_cdn_nenhum` (o HTML do Inbox e `resources/js/emoji-picker.js` não contêm `cdn.jsdelivr`, `unpkg`, `cloudflare` nem outro host externo).
- [ ] **Step 2: Run** `php artisan test --filter=EmojiDataTest` → Expected: FAIL.
- [ ] **Step 3: Implement:** `npm install emoji-picker-element` (confirmar no lock que não puxa dependências extras: `npm view emoji-picker-element dependencies` vazio), copiar o JSON `pt` de `emoji-picker-element-data` (instalado só para copiar e **não** mantido como dependência: `npm install --no-save`) para `public/vendor/emoji/pt.json` e versioná-lo; escrever `emoji-picker.js` e o botão no compositor (entre o campo e o anexo, ordem da Task 2). Registrar no `.gitattributes`/`.gitignore` o que for preciso para o arquivo de dados não ser ignorado.
- [ ] **Step 4: Run** `php artisan test --filter=EmojiDataTest`, `node --test tests/js`, `npm run build`, `npm ci --dry-run` → Expected: PASS / exit 0. No navegador, com a aba de rede aberta: abrir o seletor e inserir um emoji **sem** nenhuma requisição fora do próprio host; claro e escuro; Esc devolve o foco ao campo (Review Focus 4).
- [ ] **Step 5: Commit** `feat: seletor de emoji no compositor com dados em português servidos pelo app`.

### Task 5: Celular (360 px) e Nota privada

**Files:**
- Modify: `resources/views/livewire/inbox/inbox.blade.php`
- Test: `tests/Feature/Livewire/InboxComposerLayoutTest.php` (acrescentar)

**Interfaces:**
- Produces: abaixo do ponto de quebra estreito já usado no compositor (`@max-md`), o botão "Respostas rápidas" deixa de aparecer e entra no lugar o botão "Mais" (`aria-label="Mais ações do compositor"`, ícone `plus`) que abre **o mesmo popover** da Task 3 (cabeçalho "Respostas rápidas"); emoji e anexo seguem visíveis. No modo Nota privada o markup esconde anexo e mic (`x-show="mode === 'reply'"`, como hoje) e mostra emoji e respostas rápidas.

- [ ] **Step 1: Write the failing tests:** `test_em_tela_estreita_ha_um_botao_mais_que_abre_as_respostas_rapidas` (o HTML traz os dois gatilhos, cada um com a classe de visibilidade responsiva esperada), `test_modo_nota_esconde_anexo_e_mic_e_mantem_emoji_e_respostas` (os `x-show` corretos).
- [ ] **Step 2: Run** o arquivo → Expected: FAIL.
- [ ] **Step 3: Implement** o gatilho responsivo reaproveitando o popover.
- [ ] **Step 4: Run** o arquivo e `ViewConventionsTest` → PASS. No navegador em 360 px: o campo não fica espremido, nada rola na horizontal.
- [ ] **Step 5: Commit** `feat: compositor no celular com menu Mais e modo Nota sem anexo e mic`.

### Task 6: Script de auditoria e correções medidas das telas

**Files:**
- Create: `docs/superpowers/audit/ux-audit.js` (o script de iframes descrito na spec, devolvendo a lista de achados por tela, largura e tema)
- Modify (conforme o que a auditoria apontar): `resources/views/livewire/history/index.blade.php` (filtros), `resources/views/livewire/contacts/index.blade.php` (rótulo da busca), `resources/views/livewire/settings/channel-card.blade.php` ou a tela de Canais (rótulo de "dias"), `resources/views/livewire/settings/company.blade.php` (botões B/I/S do editor da assinatura), `resources/views/components/settings-page.blade.php` e as telas de Automação (larguras)
- Test: `tests/Feature/Livewire/AccessibleNamesTest.php`

**Interfaces:**
- Produces: `ux-audit.js` — corpo de função assíncrona para colar em `evaluate_script` na página já logada; devolve `{ rotas: { [rota]: { [largura]: { tema: { overflowX, semNome, pequenos, semAlt, h1 } } } } }`.

- [ ] **Step 1: Write the failing tests** em `AccessibleNamesTest`: `test_busca_de_contatos_tem_nome_acessivel`, `test_busca_do_historico_tem_nome_acessivel`, `test_campo_dias_da_importacao_tem_nome_acessivel` (cada um acha o campo pelo `aria-label` ou `<label for>`), `test_botoes_do_editor_da_assinatura_tem_alvo_de_ao_menos_32px_e_aria_label`.
- [ ] **Step 2:** rodar o script de auditoria no navegador (baseline "antes": 16 rotas × 5 larguras × 2 temas) e colar o resultado no ledger; rodar os testes → Expected: FAIL.
- [ ] **Step 3: Implement** os rótulos e alvos; no Histórico, tornar os filtros legíveis (um campo por linha na coluna de 360 px, ou bloco recolhível "Filtros" com o resumo dos ativos; escolher o mais simples) e conferir que a conversa ao lado não fica vazia de modo desproporcional; nas telas de Configuração e Automação, medir a largura útil em 1280 e 1920 e ajustar `max-w` só se sobrar vazio maior que ~25% da largura do conteúdo; o editor de automação em 360 px fica como está (ferramenta de desktop; anotar no ledger).
- [ ] **Step 4: Run** `php artisan test --filter='AccessibleNamesTest|HistoryIndex|ContactsIndex|ViewConventionsTest'` e o script de novo ("depois": nenhum controle sem nome, nenhum alvo < 24 px fora dos aprovados, sem rolagem horizontal) → PASS.
- [ ] **Step 5: Commit** `fix: nomes acessíveis, alvos e filtros do Histórico apontados pela auditoria`.

### Task 7: Menores adiados das fatias anteriores

**Files:**
- Modify: o botão "Mais" da Fila (`aria-label` com o nome visível), a faixa de navegação do hub em `resources/views/components/settings-page.blade.php`, `resources/css/app.css` (token `ink-foreground`), o slot de ícone da sidebar (`icon:variant`), o ponto de SLA da sidebar (`ring-zinc`), a ordenação de etiquetas (`app/Livewire/...` do Inbox/Tags)
- Test: `tests/Feature/Livewire/InboxQueueCardTest.php` e `tests/Unit/ThemeTokensTest.php` (acrescentar)

**Interfaces:**
- Produces: botão "Mais" da Fila com `aria-label` que **começa** com o texto visível ("Mais" ou o texto exato que ele mostra) — WCAG 2.5.3; etiquetas ordenadas sem diferenciar acento nem caixa (usar `Str::ascii` na chave de ordenação, ou a collation do banco se já existir).

- [ ] **Step 1: Write the failing tests:** `test_o_rotulo_acessivel_do_botao_mais_contem_o_texto_visivel`; `test_etiquetas_ordenam_sem_diferenciar_acento` (Ágil, Beta, Aéreo → Aéreo, Ágil, Beta); `ThemeTokensTest`: `test_nao_ha_token_sem_uso` (ou remover `ink-foreground` do CSS e provar que nenhuma view o cita); `test_pontos_de_status_usam_token_e_nao_ring_zinc`.
- [ ] **Step 2: Run** os arquivos → Expected: FAIL.
- [ ] **Step 3: Implement** cada um; no hub, a faixa de navegação no celular quebra em linhas ou ganha indicador sem exigir rolagem horizontal da página (a rolagem interna da faixa pode ficar se não rolar o documento: a auditoria mede `overflowX` do documento em 360 px).
- [ ] **Step 4: Run** `php artisan test --filter='InboxQueueCardTest|ThemeTokensTest|ViewConventionsTest'` → PASS; auditoria em 360 px sem `overflowX` nas telas de Configuração.
- [ ] **Step 5: Commit** `fix: menores adiados da UX (rótulo do Mais, etiquetas sem acento, tokens e hub no celular)`.

### Task 8: QA de acessibilidade e tema claro

**Files:**
- Modify: o que o Lighthouse apontar (views, `resources/css/app.css` pelos tokens)
- Create: `docs/superpowers/audit/2026-11-11-lighthouse.md` (tabela tela × tema × pontuação e a lista do que ficou fora)

**Interfaces:**
- Consumes: `mcp__chrome-devtools__lighthouse_audit` (modo navegação, `device: desktop` e `mobile`), uma vez por tela e por tema (claro e escuro, trocando `localStorage['flux.appearance']`).

- [ ] **Step 1:** rodar Lighthouse de acessibilidade em cada tela da auditoria (o Inbox com um ticket aberto incluído; o editor de automação fica de fora, porque o Lighthouse não alcança o canvas) nos dois temas e anotar pontuação e falhas em `2026-11-11-lighthouse.md`. Expected: lista de falhas (se houver).
- [ ] **Step 2:** para cada falha de contraste, nome acessível, alvo ou ordem de foco, escrever primeiro o teste que a fixa quando o problema é de markup (Livewire/Blade), ou o par de tokens em `ThemeTokensTest` quando é de cor, e ver o teste falhar.
- [ ] **Step 3: Implement** as correções **só com tokens do app**.
- [ ] **Step 4:** teclado no compositor (Review Focus 4 e 5): Tab percorre emoji, anexo, respostas rápidas, campo, mic/Enviar; Enter envia; Shift+Enter quebra linha; Esc fecha emoji e popover e devolve o foco ao campo; nada novo anima sem `motion-safe` (`grep -rn "animate-" resources/views` só em `motion-safe:` ou nos estados já aprovados). Rodar o Lighthouse de novo: meta de pelo menos 95 de acessibilidade nas telas onde ele mede; anotar as que ele não alcança.
- [ ] **Step 5: Commit** `fix: QA de acessibilidade (Lighthouse e teclado) nos dois temas`.

### Task 9: Verificação final nas três resoluções

**Files:**
- Modify: nenhum (só ajustes de `fix:` se algo aparecer)

- [ ] **Step 1:** com o servidor local (`dimetal_local`), conferir o Inbox em 1280, 1920, 2560 e 360 px, nos dois temas: campo do compositor com mais de 500 px em 1280 com o painel de contato aberto, sem rolagem horizontal, sem botão sobreposto; Nota privada; emoji; respostas rápidas; envio de texto, anexo e áudio (pelo menos abrir a gravação); assinatura aplicada.
- [ ] **Step 2:** rodar o script de auditoria completo ("depois") e o `ThemeTokensTest`; colar os resultados no ledger.
- [ ] **Step 3:** depois do merge e do deploy (a pedido do dono), repetir o passo 1 no beta.
