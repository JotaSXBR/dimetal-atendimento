# Menores baratos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar os menores baratos de `docs/superpowers/menores-pendentes.md` (B1, B3, B4, B5, U1–U12) e o clique duplo da restauração, cada um com teste.

**Architecture:** Correções pontuais nos componentes Livewire e views que já existem; nenhuma classe nova, exceto um helper puro de JS se o enquadramento do editor pedir. Cada tarefa tira o seu item de `menores-pendentes.md` no próprio commit.

**Tech Stack:** Laravel 13, Livewire 4.4 (build CSP), Flux 2, Alpine (subconjunto CSP), Rete no editor, PHPUnit, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-16-menores-baratos-design.md`

## Global Constraints

- Branch `chore/claude-md` (escolha do dono). Commits em português com prefixo `fix:`/`test:`.
- Alpine só no subconjunto CSP: sem seta, `?.`, `??`, `;`, crase, globais; lógica nova em `Alpine.data` (guarda: `tests/Unit/AlpineCspSubsetTest.php`).
- `<button>` cru novo exige entrada em `tests/Unit/ViewConventionsTest.php`; preferir `flux:button`.
- Nada de cor fora dos tokens (`tests/Unit/ThemeTokensTest.php`).
- Mudança só em Blade: rodar o teste do componente (o `codegraph affected` dá zero).
- Ao fechar um item, removê-lo da tabela de `docs/superpowers/menores-pendentes.md` no mesmo commit.

## Review Focus

1. B5: o poll de 5 s continua atualizando o status do canal (texto "Conectado", QR) com "Mais opções" aberto; preservar o `<details>` não pode congelar o conteúdo de dentro.
2. B1: quando o seletor não existe (ticket encerrado, ficha só leitura) ou está escondido, o foco não pode ir para um elemento invisível nem dar erro de JS.
3. B4a: setor desativado ou apagado (soft delete) não conta; setor fixo de Transferir que também está no menu conta uma vez só.
4. Restauração: o segundo pedido não pode marcar a restauração em andamento como falha nem sobrescrever o progresso.
5. B4b: fluxo pequeno (só o Início) e fluxo grande com zoom no piso continuam enquadrados como hoje, só sem o Início sob a barra.

---

### Task 1: B5 — "Mais opções" do card do canal não fecha sozinho

**Files:**
- Modify: `resources/views/livewire/settings/channel-card.blade.php:160` (o `<details>`; a raiz tem `wire:poll.5s="refreshStatus"` na linha 19)
- Test: `tests/Feature/Livewire/ChannelCardTest.php` (criar se não existir; senão acrescentar)
- Modify: `docs/superpowers/menores-pendentes.md` (tirar B5)

**Interfaces:** nenhuma.

- [ ] **Step 1: Teste que falha** — `test_mais_opcoes_preserva_o_estado_aberto_entre_os_polls`: renderiza `Livewire::test(ChannelCard::class, ['channel' => $channel])` e confere no HTML que o `<details>` de "Mais opções" leva o atributo que preserva o `open` no morph (`wire:ignore.self`, ou o mecanismo escolhido no passo 3, desde que confirmado na fonte do Livewire em `vendor/livewire/livewire`).
- [ ] **Step 2: Rodar e ver falhar** — `php artisan test --filter=test_mais_opcoes_preserva_o_estado_aberto_entre_os_polls` → FAIL.
- [ ] **Step 3: Corrigir** — antes, confirmar na fonte do Livewire 4.4 que `wire:ignore.self` mantém os atributos do próprio elemento e continua morfando os filhos. Se não mantiver, usar Alpine (`x-data` com `open` ligado a `:open`) dentro do subconjunto CSP.
- [ ] **Step 4: Rodar e ver passar**, mais `tests/Feature/Livewire/*Channel*`.
- [ ] **Step 5: Conferir no navegador** (servidor local `dimetal_local`, porta 8123, ver memória servidor-local): abrir "Mais opções", esperar 15 s, segue aberto e o status ainda atualiza.
- [ ] **Step 6: Commit** `fix: Mais opções do card do canal não fecha a cada atualização do status`

### Task 2: B1 — foco volta ao seletor depois de remover uma etiqueta

**Files:**
- Modify: `app/Livewire/Inbox/TicketTags.php` (`removeTag`), `resources/views/livewire/inbox/ticket-tags.blade.php`
- Modify: `app/Livewire/Contacts/ContactCard.php` (`removeTag`), `resources/views/livewire/contacts/contact-card.blade.php`
- Modify (se preciso): `resources/js/app.js` ou o arquivo onde ficam os `Alpine.data`
- Test: `tests/Feature/Livewire/TicketTagsTest.php`, `tests/Feature/Livewire/ContactCardTagsTest.php`
- Modify: `docs/superpowers/menores-pendentes.md` (tirar B1)

**Interfaces:**
- Produces: evento de navegador `tag-removed` (sem parâmetros) despachado por `TicketTags::removeTag` e `ContactCard::removeTag` quando a remoção acontece de fato.

- [ ] **Step 1: Testes que falham** — `test_remover_etiqueta_pede_o_foco_de_volta_ao_seletor` nos dois arquivos: remove uma etiqueta aplicada e `->assertDispatched('tag-removed')`; e `test_remover_etiqueta_que_nao_estava_nao_pede_foco` → `->assertNotDispatched('tag-removed')`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Corrigir** — `$this->dispatch('tag-removed')` só quando removeu. Na view, o bloco das etiquetas escuta `tag-removed` (no próprio componente, não `.window`, para não roubar o foco de outro painel) e foca o seletor "Adicionar…" depois que o morph terminar (`resources/js/shown.js` mostra o padrão de esperar o elemento visível). Se o seletor não existir (só leitura), não faz nada.
- [ ] **Step 4: Rodar e ver passar**, mais `AlpineCspSubsetTest` e `ViewConventionsTest`.
- [ ] **Step 5: Conferir no navegador com teclado:** Tab até o X do chip, Enter, `document.activeElement` é o seletor (painel da conversa e card do contato).
- [ ] **Step 6: Commit** `fix: foco volta ao seletor ao remover etiqueta (conversa e contato)`

### Task 3: B4a — contagem de setores do card do fluxo

**Files:**
- Modify: `app/Livewire/Automation/Index.php:125-139` (`summary`)
- Test: `tests/Feature/Livewire/AutomationIndexTest.php`

**Interfaces:**
- Consumes: `Sector::scopeVisibleInMenu` (status `ATIVO`, sem apagados).

- [ ] **Step 1: Testes que falham:**
  - `test_menu_de_setores_conta_os_setores_que_o_menu_mostra`: 5 setores ativos, fluxo com `menu_setores` e um `transferir` para um deles → o card mostra `5 setores`.
  - `test_setor_fora_do_menu_em_transferir_soma_uma_vez`: 2 ativos + 1 `FORA_DO_MENU` usado num `transferir`, fluxo com `menu_setores` → `3 setores`.
  - `test_sem_menu_de_setores_conta_so_os_fixos`: dois `transferir` para o mesmo setor → `1 setor`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Corrigir** `summary(array $draft): array{nodes: int, sectors: int}`: com `menu_setores`, `sectors` = quantidade de ids distintos na união dos ids de `Sector::visibleInMenu()` com os `setor_id` fixos; sem ele, só os fixos. Consultar os ids do menu uma vez por render, não por workflow. Atualizar o docblock.
- [ ] **Step 4: Rodar e ver passar** (arquivo inteiro).
- [ ] **Step 5: Commit** `fix: card do fluxo conta os setores que o menu de setores mostra`

### Task 4: B4b — barra flutuante do editor não cobre o bloco Início

**Files:**
- Modify: `resources/js/flow-editor/editor.js:253-270` (`fitView`) e o helper puro que calcula a âncora (`anchorTranslate`, onde ele estiver)
- Modify (se a barra for medida): `resources/views/livewire/automation/editor.blade.php`
- Test: `tests/js/` (o arquivo que já testa `anchorTranslate`/`fitScale`, ou um novo)
- Modify: `docs/superpowers/menores-pendentes.md` (tirar B4 inteiro, junto da Task 3)

**Interfaces:** o helper puro ganha o recuo do topo como parâmetro (ex.: `anchorTranslate(positions, scale, insetTop)`), com padrão que mantém o comportamento atual nos chamadores que não passam.

- [ ] **Step 1: Teste JS que falha** — `o Início fica abaixo do recuo da barra`: com `insetTop = 56`, o `y` resultante põe o Início a 24 px abaixo do recuo (não do topo do canvas). E um teste que, sem recuo, o resultado é igual ao de hoje.
- [ ] **Step 2: Rodar e ver falhar** — `node --test tests/js`.
- [ ] **Step 3: Corrigir** — medir a altura da barra (ou usar a constante do layout, se for fixa) e passá-la ao helper; no enquadramento sem piso (`zoomAt`), garantir o mesmo recuo.
- [ ] **Step 4: Rodar e ver passar**, mais `npm run build`.
- [ ] **Step 5: Conferir no navegador** em 1280x720 e 1920x1080: abrir o fluxo "Padrão", "Organizar"/"Centralizar": o Início fica visível abaixo da barra.
- [ ] **Step 6: Commit** `fix: enquadramento do editor deixa o bloco Início abaixo da barra`

### Task 5: Restauração — clique duplo não despacha dois jobs

**Files:**
- Modify: `app/Livewire/Settings/Backups.php` (`restore`, `switchBack` e o `start` privado que despacha o `RestoreDatabaseJob`)
- Modify: `resources/views/livewire/settings/backups.blade.php` (botões de restaurar e voltar)
- Test: `tests/Feature/Livewire/BackupsRestoreTest.php`

**Interfaces:**
- Consumes: `RestoreProgress::isRunning(): bool`, `RestoreProgress::begin(string $title, array $keys): void`.

- [ ] **Step 1: Testes que falham:**
  - `test_segundo_pedido_seguido_nao_despacha_outro_job`: `Queue::fake()`, confirma e chama `restore` duas vezes → `Queue::assertPushed(RestoreDatabaseJob::class, 1)` e o segundo deixa `problem` com "Já há uma restauração em andamento.".
  - `test_pedido_com_restauracao_em_andamento_nao_mexe_no_progresso`: progresso `running` antes → `restore` não despacha e o estado do `RestoreProgress` não muda.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Corrigir** — no `start`, recusar quando `isRunning()` e, ao aceitar, marcar o progresso como em andamento antes de despachar (o job continua com o `Cache::lock` dele). Nos botões, `wire:loading.attr="disabled"` no alvo da ação.
- [ ] **Step 4: Rodar e ver passar** (arquivo inteiro e `BackupsPageTest`).
- [ ] **Step 5: Commit** `fix: restauração recusa o segundo pedido em vez de despachar outro job`
- Tirar a linha do clique duplo de `memory/pendencias-v1.md` é tarefa do controlador, não do implementador.

### Task 6: B3 — ações do cabeçalho não pulam ao abrir Detalhes (condicional)

**Files:**
- Investigar: o cabeçalho da conversa em `resources/views/livewire/inbox/` (ações com `flex-wrap`, `basis-56`; ver U9)
- Test: `tests/Feature/Livewire/` do Inbox (asserção de HTML da estrutura escolhida)
- Modify: `docs/superpowers/menores-pendentes.md` (tirar B3 e U9 se resolvidos)

- [ ] **Step 1: Reproduzir** no servidor local em 1280x720 e 1920x1080: abrir uma conversa, clicar em Detalhes, anotar o que muda de lugar e por quê (largura da coluna, quebra de linha).
- [ ] **Step 2: Decidir** — se a correção é de layout (reservar o lugar do botão Detalhes, não deixar as ações quebrarem linha), seguir; se pede redesenho do cabeçalho, PARAR, deixar B3 no arquivo com a causa e relatar ao controlador.
- [ ] **Step 3: Teste que falha** que fixe a estrutura nova (ex.: o botão Detalhes fica fora do grupo que quebra linha).
- [ ] **Step 4: Corrigir, ver passar, conferir no navegador nas duas resoluções e em 360 px.**
- [ ] **Step 5: Commit** `fix: ações do cabeçalho da conversa não mudam de lugar ao abrir Detalhes`

### Task 7: U1–U12 — reconferir os menores antigos de UX

**Files:**
- Modify: `docs/superpowers/menores-pendentes.md`
- Modify/Test: só os arquivos dos itens que ainda existem e forem baratos

- [ ] **Step 1: Reconferir cada U** no código atual (e no navegador quando for visual) e anotar: resolvido (por qual commit/arquivo), ainda existe e é barato, ou ainda existe e não é barato.
- [ ] **Step 2: Para cada barato que ainda existe:** teste que falha, correção, teste passando, commit próprio `fix: <item> (U<n>)`. Teto: até 5 linhas de produção por item; acima disso vai para "não barato".
- [ ] **Step 3: Atualizar `menores-pendentes.md`:** tirar os resolvidos e os corrigidos; os não baratos ficam com a causa em uma linha. Tirar também o B2 (fechado sem código: só a recuperação manual avisa com 0 mensagens, ver o spec).
- [ ] **Step 4: Commit** `docs: menores de UX reconferidos (U1-U12)`

### Task 8: Seed do ambiente de teste (cenários da conferência visual)

**Files:**
- Create: `database/seeders/AmbienteDeTesteSeeder.php`
- Create: `database/seeders/Cenarios/*.php` (uma classe por cenário, lista abaixo)
- Test: `tests/Feature/Seeders/AmbienteDeTesteSeederTest.php`
- Modify: `CLAUDE.md` (seção "Stack e comandos": uma linha)

**Interfaces:**
- Produces: `Database\Seeders\AmbienteDeTesteSeeder::run(): void` que chama, em ordem, `SectorSeeder`, `TagSeeder`, `DefaultWorkflowSeeder` e cada `Database\Seeders\Cenarios\*`; constantes públicas `ADMIN_EMAIL = 'dev@teste.local'`, `ATENDENTE_EMAIL = 'atendente@teste.local'`, `SENHA = 'secret123'`.
- Cada cenário é um `Seeder` com `run(): void`, usa as factories existentes e pode ser chamado sozinho (busca o canal/usuários pelo e-mail ou cria).

Cenários (nome da classe → o que precisa existir):
- `UsuariosDeTeste` → admin `ADMIN_EMAIL` em todos os setores; atendente `ATENDENTE_EMAIL` ativo em 2 setores.
- `CanalConectado` → um canal `WORKING` com telefone fictício.
- `TicketsEmTodosOsEstados` → ao menos um ticket `pendente`, `aguardando`, `em_atendimento` (do admin) e `encerrado`.
- `NomesLongos` → contato com nome de 60+ caracteres e empresa longa, com ticket aberto.
- `BolhasDeTamanhosVariados` → num ticket aberto: mensagem de entrada de uma palavra ("Oi"), uma de saída de uma palavra ("Ok"), uma longa (500+ caracteres) e uma com citação de outra.
- `EtiquetasDosDoisEscopos` → contato com etiqueta de escopo contato e ticket com etiqueta de escopo conversa, sobrando etiquetas disponíveis nos dois.
- `NotaInterna` → nota de equipe num ticket aberto.
- `AlertasDoSino` → para o admin: um alerta de SLA estourado de ticket aberto, um de ticket encerrado e um de recuperação manual com 0 mensagens.

- [ ] **Step 1: Testes que falham** em `AmbienteDeTesteSeederTest` (banco de teste vazio, `$this->seed(AmbienteDeTesteSeeder::class)`):
  - `test_cria_os_usuarios_de_teste`: os dois e-mails existem, o atendente tem 2 setores, `Hash::check(SENHA, ...)`.
  - `test_cobre_cada_cenario`: um assert por cenário da lista acima (contagens e valores exatos citados).
  - `test_rodar_duas_vezes_nao_duplica_usuarios_nem_canal`.
  - `test_recusa_rodar_em_producao`: com `app()->detectEnvironment(fn () => 'production')`, lança `RuntimeException` e nada é criado.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** o agregador (guarda de ambiente: só `local` e `testing`) e os cenários. Sem mídia real.
- [ ] **Step 4: Rodar e ver passar**, mais `pint --test`, `phpstan` nos arquivos novos e `phpmd analyze database/seeders --format text --ruleset phpmd.xml` só como aviso.
- [ ] **Step 5: CLAUDE.md**, em "Stack e comandos": `Ambiente de teste local: php artisan migrate:fresh --seed --seeder=AmbienteDeTesteSeeder (banco dimetal_local); cenário novo de conferência visual = classe nova em database/seeders/Cenarios/ chamada pelo agregador, com assert no AmbienteDeTesteSeederTest.`
- [ ] **Step 6: Commit** `feat: seed do ambiente de teste com cenários da conferência visual`

### Task 9: Apagar para todos respeita o prazo de 2 dias do WhatsApp (bug do dono, 16/11)

Causa (systematic-debugging): `MessagePolicy::revoke` não tem prazo ("o WhatsApp decide"), mas o WAHA aceitou apagar uma mensagem
de ontem. Prazos oficiais (faq.whatsapp.com, 16/11/2026): apagar para todos até 2 dias depois do envio; editar até 15 minutos
(já aplicado: `Message::EDIT_WINDOW_MINUTES = 15`).

**Files:**
- Modify: `app/Models/Message.php` (constante `REVOKE_WINDOW_HOURS = 48`, ao lado de `EDIT_WINDOW_MINUTES`)
- Modify: `app/Policies/MessagePolicy.php` (`revoke` com a janela; `edit` e `revoke` devolvem `Response::deny(<texto>)` quando o motivo é o prazo)
- Modify: `app/Livewire/Inbox/Concerns/ActsOnMessages.php` (`act`: negado pelo prazo vira aviso na tela, não erro 403)
- Test: `tests/Feature/Inbox/EditRevokeMessageTest.php` (ou o teste da policy existente)

- [ ] **Step 1: Testes que falham:**
  - `test_apagar_para_todos_vale_ate_2_dias`: mensagem própria do painel com `sent_at` = agora − 47 h → `can('revoke')` true; − 49 h → false.
  - `test_botao_apagar_some_depois_de_2_dias`: o transcript não mostra "Apagar para todos" na mensagem de 49 h e mostra na de 1 h.
  - `test_apagar_fora_do_prazo_avisa_sem_chamar_o_whatsapp`: chamar `revoke` numa mensagem de 49 h deixa `notice` = "O WhatsApp só permite apagar para todos até 2 dias depois do envio." e o WAHA não recebe requisição (`Http::assertNothingSent` ou equivalente).
  - `test_editar_fora_do_prazo_avisa`: `editMessage` numa mensagem de 16 min deixa `notice` = "O WhatsApp só permite editar até 15 minutos depois do envio." sem 403.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Corrigir** — janela na policy (`sent_at->gte(now()->subHours(Message::REVOKE_WINDOW_HOURS))`), `Response::deny` com os textos acima só para o motivo prazo (outros motivos continuam negando como hoje), e `act` usando `Gate::inspect` para transformar a negação por prazo em `notice`. Atualizar o comentário da policy. `RevokeTicketMessage` continua conferindo `can('revoke')`.
- [ ] **Step 4: Rodar e ver passar** (os testes de mensagem e do transcript), pint, phpstan, phpmd.
- [ ] **Step 5: Commit** `fix: apagar para todos só até 2 dias depois do envio (prazo do WhatsApp)`

### Verificação final (controlador, portão 7)

`vendor/bin/pint --parallel --test --cache-file=.pint.cache`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/phpmd analyze app --format text --ruleset phpmd.xml`, `node --test tests/js`, `npm run build` e a suíte com Redis (CLAUDE.md), lendo o código de saída de cada um.
