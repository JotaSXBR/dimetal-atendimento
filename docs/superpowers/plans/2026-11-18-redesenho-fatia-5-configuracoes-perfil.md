# Redesenho, fatia 5: Configurações e Minha conta — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** As 11 abas de Configurações e as 4 de Minha conta no visual do Pencil: menu lateral em `flux:navlist` no visual do
Settings Nav Item, cabeçalho de página com o título da aba, e cada bloco num painel (`x-panel`) com cabeçalho e linhas, sem tirar
nenhum dado, ação ou estado.

**Architecture:** Um componente novo (`x-panel`), o `x-settings-page` reescrito (menu `flux:navlist`, largura nova) e reaproveitado
por Minha conta (o `pages/settings/layout` passa a delegar a ele). O resto é markup das views das abas. Nenhuma propriedade
Livewire, rota, consulta ou regra nova; a única adição de PHP é `Navigation::accountGroups()` (lista fixa dos 4 itens de Minha conta).

**Tech Stack:** Laravel 13, Livewire 4.4 (build CSP), Flux 2.21 free, Tailwind 4, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-17-redesenho-ui-pencil-design.md` (fatia 5).

## Global Constraints

- Só visual: nenhuma propriedade Livewire, rota, consulta ou regra nova. Nada some: todo campo, botão, aviso, estado condicional,
  `@error` e mensagem de "salvo" de hoje continua, no mesmo componente Livewire.
- Build CSP: nenhuma expressão Alpine nova em atributo. Manter os `x-data` (`sectorRow`, `signatureToolbar`, `updateConfirm`,
  `desktopAlerts`), `x-ref`, `x-model`, `wire:*`, `wire:key`, `wire:poll`, `wire:ignore`, `data-confirm*`, `data-test`, `data-*`
  existentes.
- Tokens de `resources/css/app.css`; nenhuma cor crua nova (os `zinc-*` das passkeys em `⚡security` viram tokens). Piso de 12 px;
  alvo mínimo de 24 px; itens do menu com 40 px de altura em toda largura.
- Largura: contêiner da página `max-w-[120rem]` (no lugar do `max-w-7xl`). Coluna de conteúdo: abas de lista (Usuários, Setores,
  Respostas rápidas, Etiquetas, Canais, Backups) ocupam a largura toda; abas de formulário (Horário, Empresa, Google, Retenção,
  Sistema e as 4 de Minha conta) param em `max-w-4xl`.
- Painel = `x-panel` (Task 1). As células de `flux:table` (se usada) seguem a lição da fatia 4: `w-full` com `min-w-*`, texto num
  `span` com token, `border-line!`, `caption` `sr-only`.
- Testes com `DB_DATABASE=fluxie_ui_test`; implementadores rodam os testes focados e o Pint nos arquivos tocados. Testes conferem
  estrutura e `data-*`; classe só quando a classe é o requisito.

## Decisões (não reabrir)

- Ruling: nada de layout em duas colunas (principal + lateral) como o Pencil antigo mostrava. Medido: em 1280 a coluna de
  conteúdo tem ~720 px; com uma lateral de 360 px a principal ficaria com ~340 px. O Pencil foi redesenhado em coluna única.
- Ruling: Usuários e Setores seguem abas separadas (rotas diferentes; juntar é não-meta). O "Equipe e setores" do Pencil virou
  "Config - Usuarios" e ganhou "Config - Setores".
- Ruling: Minha conta usa o mesmo arranjo das Configurações: menu à esquerda com o grupo "Minha conta" e o cabeçalho de página com
  o título da aba (Perfil, Segurança, Aparência, Meus alertas). Sai o cabeçalho grande "Minha conta" de
  `partials/settings-heading.blade.php` (o texto "Minha conta" fica como título do grupo do menu). Sai do desenho o que o app não
  tem: telefone, papel/setores somente leitura, sessões ativas, "Sair" no cabeçalho.
- Ruling: o menu passa a `flux:navlist` (spec da fatia 5) com `:current` explícito vindo de `$route` (numa ação Livewire a URL é
  `livewire/update`) e `aria-current="page"` no item atual; o título do grupo continua um `<p data-test="settings-group-title">`
  nosso (o `heading` do `flux:navlist.group` pinta `text-zinc-400`).
- Ruling: `x-panel` embrulha o `flux:card` do free se ele chegar ao visual do painel da fatia 4 com poucas sobrescritas (Task 1
  mede); senão é markup próprio com o mesmo `data-panel`, e o implementador registra a diferença medida no relatório.
- Saem do desenho por não existirem no app: CNPJ, razão social, IE, idioma, site, telefone e LGPD em Empresa; opt-out; busca e
  coluna "Usos" em Respostas; "Como aparece no atendimento"; uso de armazenamento em GB, "Backup e exportação" e Cancelar/Salvar em
  Retenção; "O que cada papel vê"; contagem de fila por setor; SLA de resposta seguinte e fechamento automático.

## Medidas do Pencil (`Dimetal.pen`; PNGs em
`C:/Users/bkpdi/AppData/Local/Temp/claude/abad7b6d92ced975/0cc90b53-26aa-4a61-b219-6ddb491b1462/scratchpad/pen-f5/final/`)

Frames: Canais `W41d3`, Horário `y3VaCY`, Usuários `ApJ6g`, Setores `powgD`, Respostas `r4DpF5`, Etiquetas `Xisl3`, Empresa
`fYtA2`, Google `o48gk`, Retenção `IXm82`, Backups `glz6y`, Sistema `ORBDS`, Perfil `XtMwm`, Segurança `gWdCU`, Aparência `rB9bk`,
Alertas `So4HJ` (o PNG tem o id do frame no nome).

| Parte | Medidas |
|---|---|
| Arranjo | `p-6`, menu 224 px (`lg:w-56`) + `gap-8` + conteúdo; abaixo de `lg` o menu rola na horizontal acima do conteúdo, como hoje |
| Menu | grupos com `gap-5`; título do grupo 12 px peso 600 `ink-3`; item 40 px, `rounded-lg`, `px-3`, `gap-2`, ícone 16 px, texto 14 px peso 500 `ink-2`, hover `bg-canvas text-ink`; atual `bg-accent-soft text-accent-content` |
| Cabeçalho | `x-page-header` (título da aba + subtítulo de hoje + ações à direita); conteúdo abaixo com `mt-6` e painéis com `space-y-5` |
| Painel (`x-panel`) | `rounded-xl border border-line bg-surface overflow-hidden`; cabeçalho `border-b border-line px-4 py-3.5`, título 13 px peso 600 `ink` (`text-sm font-semibold`, `h2`), subtítulo 12 px `ink-3`, ações à direita do título; corpo encostado nas bordas |
| Linha do painel | `px-4 py-3` com `border-b border-line` (a última sem borda): par rótulo `ink-2` à esquerda e valor mono 12 px `ink` à direita (`dl`), ou item com título 13 px peso 600 + linha 12 px `ink-3` e ações à direita |
| Formulário no painel | campos em grade `grid gap-4 p-4 md:grid-cols-2`; rodapé de ações `px-4 py-3` com `border-t border-line` (primário à esquerda, Cancelar `ghost`) |
| Interruptor com texto | linha `px-4 py-3`: título 13 px peso 500 + descrição 12 px `ink-3` à esquerda, `flux:switch` à direita |
| Aviso | bloco com tokens `warn-*` (`rounded-xl border-warn-line bg-warn-soft text-warn`, ícone 16 px, título 13 px peso 600 e texto 12 px) |
| Lista de Usuários | cabeçalho de colunas `bg-canvas` 12 px peso 600 `ink-3` (Usuário, Setores, Tickets, ações); linha com avatar 32 px (`x-avatar size="sm"`), nome peso 600 + papel e "Inativo" em `x-status-badge tone="neutral"`, e-mail 12 px `ink-3`; abaixo de `md` setores e tickets voltam para a linha do e-mail |

## Review Focus

- Estados condicionais de cada aba aparecem dentro do arranjo novo: QR/código de pareamento/desativado/confirmação no
  `channel-card`; conferência de restauração com palavra digitada, "Última tentativa falhou", "Bucket ilegível", "Restauração em
  andamento" em Backups; passos da atualização, confirmação e "Atualizando…" em Sistema; Google sem conta conectada (Tasks 3–5).
- Formulários abertos (novo/editar usuário, nova etiqueta, nova resposta, renomear setor) e erros por linha (`rename.*`,
  `remove.*`, `status.*`) continuam no lugar e com `role="alert"` (Task 2).
- Supervisor vê só os grupos Atendimento e Equipe; atendente continua barrado (Task 1).
- 390 px: menu rolando na horizontal sem estourar a página; tabelas e linhas com ações quebrando sem rolagem lateral (todas; conferência).
- Escuro: interruptores, painéis e o bloco de aviso legíveis (conferência).

---

### Task 1: Base: `x-panel`, menu em `flux:navlist`, largura e Minha conta no mesmo arranjo

**Files:**
- Create: `resources/views/components/panel.blade.php`
- Modify: `resources/views/components/settings-page.blade.php`, `resources/views/pages/settings/layout.blade.php`,
  `app/Support/Navigation.php`, `resources/views/pages/settings/⚡profile.blade.php`, `⚡security.blade.php`,
  `⚡appearance.blade.php`, `⚡alerts.blade.php` (só a troca do include/cabeçalho), `resources/views/partials/settings-heading.blade.php`
  (apagar se ficar sem uso)
- Test: `tests/Feature/Components/PanelTest.php` (novo), `tests/Feature/Layout/SettingsPageTest.php`,
  `tests/Feature/Layout/NavigationTest.php`

**Interfaces:**
- Produces: `<x-panel title="string" subtitle="?string">` com slot `actions` opcional e slot do corpo; raiz `data-panel`, título
  `h2`. `<x-settings-page route title subtitle :groups="?array" label="?string" width="'wide'|'form'">`: `groups` padrão
  `Navigation::settingsGroups(auth()->user())`, `label` padrão "Configuração" (`aria-label` do `nav`), `width` padrão `'form'`
  (`max-w-4xl`); `'wide'` = largura toda. `Navigation::accountGroups(): array<string, list<NavItem>>` →
  `['Minha conta' => [Perfil profile.edit user, Segurança security.edit shield-check, Aparência appearance.edit sun, Meus alertas alerts.edit bell]]`.
- Consumes: nada.

- [ ] **Step 1: Testes que falham**
  - `PanelTest::test_painel_com_titulo_subtitulo_e_acoes`: `data-panel`, `h2` com o título, subtítulo presente, slot `actions` no
    cabeçalho; sem `subtitle` não há subtítulo.
  - `SettingsPageTest::test_menu_usa_navlist_com_a_pagina_atual`: o `nav[@aria-label="Configuração"]` contém `[data-flux-navlist-item]`;
    só o item de `/configuracoes/canais` tem `aria-current="page"` e `data-current`; os títulos de grupo seguem em
    `data-test="settings-group-title"`; o contêiner tem `max-w-[120rem]`.
  - `SettingsPageTest::test_minha_conta_usa_o_mesmo_arranjo`: em `/settings/profile`, `nav[@aria-label="Minha conta"]` com os 4
    itens, "Perfil" atual, `data-page-header` com o título "Perfil"; o texto "Seu perfil, sua segurança e suas preferências" não
    aparece mais.
  - `NavigationTest::test_account_groups`: chaves e rotas acima.
  - Os testes de hoje do `SettingsPageTest` (grupos por papel, atendente barrado, sem menu pessoal) continuam valendo.
- [ ] **Step 2: Rodar e ver falhar** — `php artisan test --filter="PanelTest|SettingsPageTest|NavigationTest"`.
- [ ] **Step 3: Implementar.** `x-panel`: primeiro renderizar um `flux:card` (`:highlight="false"`, `bg-surface border-line
  shadow-none rounded-xl`) ao lado de um painel da fatia 4 (Relatórios) no servidor local e comparar raio, borda, fundo, sombra e
  padding claro/escuro; usar o `flux:card` se bater, senão markup próprio; comentário de cabeçalho com a decisão. Menu:
  `flux:navlist` por grupo, `flux:navlist.item :href :icon :current wire:navigate aria-current` com sobrescritas de token
  (`h-10!`, `text-ink-2!`, `data-current:bg-accent-soft!`, `data-current:text-accent-content!`, `hover:bg-canvas!`), mantendo a
  rolagem horizontal abaixo de `lg`. `pages/settings/layout` delega ao `x-settings-page` com `accountGroups()`, `label="Minha
  conta"`, título/subtítulo da aba (props `heading`/`subheading` de hoje). Trocar `width="3xl|5xl|6xl"` dos chamadores:
  Usuários, Setores, Respostas, Etiquetas, Canais e Backups → `wide`; o resto fica no padrão.
- [ ] **Step 4: Rodar e ver passar** — o filtro acima + `--filter="Settings|Profile|Security|Appearance|AlertSettingsPage|UsersPage|SectorsPage|QuickReplies|Tags|ChannelsScreen|BusinessHoursPage|CompanyPage|Google|RetentionPage|BackupsPage|SystemPage"`.
- [ ] **Step 5: Commit** — `feat: base das configurações com painel e menu do desenho`

### Task 2: Abas de lista: Usuários, Setores, Respostas rápidas, Etiquetas

**Files:**
- Modify: `resources/views/livewire/settings/users.blade.php`, `sectors.blade.php`, `quick-replies.blade.php`, `tags.blade.php`
- Test: `tests/Feature/**/UsersPageTest.php`, `SectorsPageTest.php`, `QuickRepliesTest.php`, `TagsTest.php` (os que renderizam
  cada componente; ajustar asserções de markup sem perder as de comportamento)

**Interfaces:** Consumes `x-panel`, `x-settings-page width="wide"` (Task 1).

- [ ] **Step 1: Testes que falham** (um por aba, nome `test_<aba>_no_painel_do_desenho`):
  - Usuários: formulário aberto (`newUser`) dentro de um `data-panel` com título "Novo usuário" (ou "Editar usuário" com
    `edit`), rodapé Salvar/Cancelar; a lista num `data-panel` com cabeçalho de colunas e `wire:key="user-{id}"`; papel e "Inativo"
    em `data-status-tone="neutral"`.
  - Setores: "Novo setor" num `data-panel`; lista num `data-panel` com `wire:key="sector-{id}"`, `x-data` `sectorRow` e os três
    `@error` por linha com `role="alert"`.
  - Respostas: formulário num `data-panel`; lista num `data-panel`; vazio com `data-empty-state-icon` (não compacto).
  - Etiquetas: um `data-panel` por escopo ("Do contato", "Da conversa") com o botão "Nova" nas ações do cabeçalho; vazio compacto
    de hoje dentro do painel.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** com as medidas (lista de Usuários com a grade de colunas da tabela de medidas, em `md` e acima).
- [ ] **Step 4: Rodar e ver passar** — os quatro testes + `--filter="Users|Sector|QuickRepl|Tag|UserAvatar|SetUserCanTransfer"`.
- [ ] **Step 5: Commit** — `feat: usuários, setores, respostas e etiquetas nos painéis do desenho`

### Task 3: Abas de formulário: Horário e SLA, Empresa e assinatura, Google Contatos, Anexos e retenção

**Files:**
- Modify: `resources/views/livewire/settings/business-hours-page.blade.php`, `company.blade.php`, `google-contacts.blade.php`,
  `retention.blade.php`
- Test: `BusinessHoursPageTest.php`, `CompanyPageTest.php`, `CompanyLogoTest.php`, os de `tests/Feature/Google/` que renderizam a
  página, `RetentionPageTest.php`

- [ ] **Step 1: Testes que falham** (`test_<aba>_no_painel_do_desenho`):
  - Horário: quatro `data-panel` ("Faixas de atendimento", "Feriados", "Prazo de primeira resposta (SLA)", "Resposta automática
    fora do horário"); cada dia numa linha com nome à esquerda e faixas à direita, `wire:key="day-{n}"`; "Fechado" no dia sem faixa.
  - Empresa: `data-panel` "Logotipo" e `data-panel` "Nome e assinatura" com o `x-data="signatureToolbar"`, o interruptor com texto
    e a prévia.
  - Google: com conexão, um `data-panel` "Conta do Google" com o e-mail, o selo de status, os três números num `dl` e
    Reconectar/Desconectar; sem conexão, o mesmo painel com "Nenhuma conta conectada." e o botão Conectar.
  - Retenção: `data-panel` com o `dl` dos quatro valores e `data-panel` do expurgo com o botão `danger`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** com as medidas (formulário no painel, interruptor com texto, rodapé de ações).
- [ ] **Step 4: Rodar e ver passar** — os testes + `--filter="BusinessHours|Company|Google|Retention"`.
- [ ] **Step 5: Commit** — `feat: horário, empresa, google e retenção nos painéis do desenho`

### Task 4: Canais e cartão do canal

**Files:**
- Modify: `resources/views/livewire/settings/channels.blade.php`, `resources/views/livewire/settings/channel-card.blade.php`
- Test: `ChannelsScreenTest.php`, `InactiveChannelTest.php`, `ChannelPhoneTest.php`, `StatusBadgeSettingsTest.php`

- [ ] **Step 1: Testes que falham**
  - `test_canais_no_painel_do_desenho`: aviso "Sem failover entre números" no bloco `warn` com `role="note"`; cada canal num
    `data-panel` (título = nome, subtítulo = telefone, selo no cabeçalho); "Adicionar número", "Webhook de entrada" e "Últimos
    eventos" em `data-panel`.
  - `test_cartao_do_canal_mantem_os_estados`: em `SCAN_QR_CODE`, inativo e com confirmação aberta (`ask('deactivate')`), os
    textos, botões e o bloco de confirmação de hoje continuam dentro do painel do canal; `wire:poll.5s="refreshStatus"` na raiz.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** no arranjo do frame `W41d3`: bloco QR + informações, seções separadas por `border-t border-line`
  (importar ao conectar; importar/recuperar; ações em duas linhas; "Mais opções"), grade `xl:grid-cols-2` de hoje.
- [ ] **Step 4: Rodar e ver passar** — `--filter="Channel|StatusBadgeSettings|InboxChannelBadge"`.
- [ ] **Step 5: Commit** — `feat: canais nos painéis do desenho`

### Task 5: Backup do banco e Sistema

**Files:**
- Modify: `resources/views/livewire/settings/backups.blade.php`, `resources/views/livewire/settings/system.blade.php`
- Test: `BackupsPageTest.php`, `BackupsRestoreTest.php`, `SystemPageTest.php`

- [ ] **Step 1: Testes que falham**
  - `test_backups_no_painel_do_desenho`: `data-panel` "Situação" com o `dl`; "Fazer um backup agora", "Backups guardados" (vazio
    com `data-empty-state-icon`), "Restaurar de um arquivo do seu computador" e "Bancos guardados" em `data-panel`; a conferência
    de restauração (`chooseBackup`) num `data-panel` com `aria-labelledby="restore-check"` e o campo da palavra; `data-restore-problem`
    mantido.
  - `test_sistema_no_painel_do_desenho`: `data-panel` "Versão" com o `dl`; "Consulta de novas versões" e "Conexão com o Coolify" em
    `data-panel`; com atualização aberta, `data-system-update`, `data-update-lost` e os `data-step` com `data-state`.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** com as medidas; os avisos de estado (falhou, ilegível, em andamento) viram painéis sem cabeçalho com o
  `x-status-badge` de hoje no topo, mantendo `role`.
- [ ] **Step 4: Rodar e ver passar** — `--filter="Backup|Restore|SystemPage|SystemUpdate"`.
- [ ] **Step 5: Commit** — `feat: backup e sistema nos painéis do desenho`

### Task 6: Minha conta: Perfil, Segurança, Aparência e Meus alertas

**Files:**
- Modify: `resources/views/pages/settings/⚡profile.blade.php`, `⚡security.blade.php`, `⚡appearance.blade.php`,
  `⚡alerts.blade.php`, `⚡delete-user-form.blade.php`, `resources/views/livewire/settings/two-factor/*` (se pintarem zinc cru)
- Test: `ProfileUpdateTest.php`, `SecurityTest.php`, `AppearanceTest.php`, `AlertSettingsPageTest.php`

**Interfaces:** Consumes `x-panel` e o `pages/settings/layout` da Task 1.

- [ ] **Step 1: Testes que falham** (`test_<aba>_no_painel_do_desenho`):
  - Perfil: `data-panel` "Dados da conta" (foto, Nome e E-mail em grade, Salvar com `data-test="update-profile-button"`); "Excluir
    conta" em `data-panel` quando `showDeleteUser`.
  - Segurança: `data-panel` "Atualizar senha" (`data-test="update-password-button"`), "Verificação em duas etapas" (com
    `canManageTwoFactor`) e "Passkeys" (com `canManagePasskeys`); sem `zinc-` no HTML da aba.
  - Aparência: `data-panel` "Tema" com o `flux:radio.group` segmentado de hoje.
  - Alertas: `data-panel` "Som por tipo", "Repetição e balão", "Volume e prévia", "Silenciar o alerta crítico"; o `x-data="desktopAlerts"`
    e `data-test="save-alerts-button"` mantidos.
- [ ] **Step 2: Rodar e ver falhar.**
- [ ] **Step 3: Implementar** com as medidas; textos de `__()` seguem como estão.
- [ ] **Step 4: Rodar e ver passar** — `--filter="Profile|Security|Appearance|AlertSettings|TwoFactor|Passkey|DeleteUser"`.
- [ ] **Step 5: Commit** — `feat: minha conta nos painéis do desenho`

### Task 7: Verificação da fatia (controlador)

- [ ] Portão 7 do `CLAUDE.md`; suíte com Redis num contêiner `redis-ui` na porta 6380.
- [ ] Conferência visual (`conferente-visual`, chrome-devtools): 720p, 1080p, 2K e 390 px com toque, claro e escuro, nas 15 abas e
  nos estados do Review Focus que o seed permite (formulários abertos, confirmação do canal, conferência de restauração).
