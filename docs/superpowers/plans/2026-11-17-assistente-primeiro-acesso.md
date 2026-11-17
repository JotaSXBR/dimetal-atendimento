# Primeiro acesso com código e assistente: plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** instalação nova pelo Coolify cria o admin em `/instalar` (com o código da instalação) e conclui empresa, segmento e
primeiro canal em `/primeiros-passos`, sem terminal; instalações existentes não mudam.

**Architecture:** um serviço `SetupState` guarda o estado em `settings` (`setup.concluido`, `setup.passo`, `setup.segmento`); um
middleware no grupo `web` redireciona enquanto a instalação não está concluída; dois componentes Livewire de página (`Setup\Install`
público, `Setup\FirstSteps` só admin) reaproveitam `CompanySettings`, `ReceivesAvatar`, `ChannelCard`, `DefaultSectors`,
`DefaultTags` e `WorkflowPublisher`.

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2, Fortify, PostgreSQL 18, Redis 8.

**Spec:** `docs/superpowers/specs/2026-11-17-assistente-primeiro-acesso-design.md`

## Global Constraints

- Sem e-mail, sem Google, sem fuso no assistente (spec, Não objetivos).
- Código: `SETUP_CODE` vindo de `${SERVICE_PASSWORD_SETUP}`; comparação `hash_equals`; 5 tentativas por minuto por IP.
- Chaves em `settings`: `setup.concluido` (bool), `setup.passo` (`empresa` | `segmento` | `canal`), `setup.segmento`
  (`distribuidora` | `em_branco`).
- Instalação concluída: `/instalar` e `/primeiros-passos` respondem 404; nada redireciona.
- CSP `enforce`: nenhum script inline novo; Alpine só como as telas atuais usam.
- Textos em português, no tom das telas atuais. Commits em português com prefixo.
- Testes que tocam banco usam `RefreshDatabase` (Postgres). Rodar os afetados pelo comando do CLAUDE.md.

## Review Focus

1. Código errado repetido: na 6ª tentativa no mesmo minuto a tela diz quanto esperar e nem compara o código (Tarefa 3).
2. Duas abas criando o admin ao mesmo tempo: só um admin nasce; a outra vê "a instalação já tem admin" (Tarefa 3).
3. Admin fecha o navegador no meio: ao entrar de novo cai no mesmo passo, e o segmento escolhido não reinstala (Tarefas 2 e 6).
4. Requisições do Livewire, do broadcasting e do webhook do WAHA durante a configuração não são redirecionadas (Tarefa 2).
5. Distribuidora que falha no meio não deixa setor, etiqueta nem workflow gravados, e a nova tentativa funciona (Tarefa 5).

---

### Task 1: Estado da instalação, migração e fim do agendador de padrões

**Files:**
- Create: `app/Services/Setup/SetupState.php`, `config/setup.php`,
  `database/migrations/2026_11_17_000001_mark_existing_installs_as_set_up.php`, `tests/Feature/Setup/SetupStateTest.php`
- Modify: `routes/console.php` (sai `install-default-sectors` e `install-default-tags`), `tests/TestCase.php`,
  `database/seeders/DatabaseSeeder.php`, `database/seeders/AmbienteDeTesteSeeder.php`, `.env.example` (`SETUP_CODE=`),
  `tests/Feature/Domain/DefaultSectorsTest.php`, `tests/Feature/Domain/DefaultTagsTest.php` (troca o teste do agendamento por
  `test_nao_ha_mais_tarefa_agendada_de_padroes`)

**Interfaces:**
- Produces: `final class SetupState` com `const CONCLUDED = 'setup.concluido'`, `const STEP = 'setup.passo'`,
  `const SEGMENT = 'setup.segmento'`, `const STEPS = ['empresa', 'segmento', 'canal']`;
  `static concluded(): bool`, `static markConcluded(): void`, `static hasAdmin(): bool` (usuário com `role = admin`, ativo ou não),
  `static step(): string` (padrão `empresa`), `static setStep(string $step): void` (recusa valor fora de `STEPS` com
  `InvalidArgumentException`), `static segment(): ?string`, `static setSegment(string $segment): void`.
  `concluded()` guarda `true` em `Cache::rememberForever('setup.concluido', ...)` só quando é verdadeiro (nunca guarda `false`).
- Produces: `config('setup.code')` = `env('SETUP_CODE', '')`.

- [ ] **Step 1: testes que falham** em `SetupStateTest`:
  - `test_banco_novo_nao_esta_concluido_e_comeca_na_empresa` (apaga a marca do `TestCase`, `concluded()` false, `step()` = `empresa`)
  - `test_marcar_concluido_vale_para_sempre` (`markConcluded()`, depois `Setting::query()->delete()`, `concluded()` segue true pelo cache)
  - `test_passo_invalido_e_recusado` (`setStep('qualquer')` lança `InvalidArgumentException`)
  - `test_has_admin_ignora_atendente_e_supervisor`
  - `test_migracao_marca_instalacao_com_usuario_e_instala_padroes_que_faltam`: banco com 1 usuário atendente e sem marcas; roda
    `require` do arquivo da migração e `->up()`; espera `setup.concluido` true, 5 setores e 15 etiquetas
  - `test_migracao_deixa_banco_vazio_sem_marca`
  - `test_nao_ha_mais_tarefa_agendada_de_padroes` (nos dois testes de domínio: nenhum evento com essas descrições)
- [ ] **Step 2: rodar e ver falhar**: `php artisan test tests/Feature/Setup/SetupStateTest.php` → classe não existe.
- [ ] **Step 3: implementar** `SetupState`, `config/setup.php` e a migração (`up()`: se `users` tem linha, `markConcluded()`,
  `DefaultSectors::install()`, `DefaultTags::install()`; `down()` vazio). Tirar as duas tarefas do `routes/console.php`.
  `TestCase::setUp`, depois do `parent::setUp()`: se a classe usa `RefreshDatabase`
  (`in_array(RefreshDatabase::class, class_uses_recursive($this), true)`), `Setting::put(SetupState::CONCLUDED, true, 'bool')`.
  Os dois seeders terminam com `SetupState::markConcluded()`.
- [ ] **Step 4: rodar** os testes da tarefa e `php artisan test --parallel` → tudo verde.
- [ ] **Step 5: commit** `feat: estado da instalação e fim do agendador de setores e etiquetas padrão`

### Task 2: Middleware que leva à configuração

**Files:**
- Create: `app/Http/Middleware/RedirectToSetup.php`, `tests/Feature/Setup/RedirectToSetupTest.php`
- Modify: `bootstrap/app.php` (`$middleware->web(append: [RedirectToSetup::class])`), `routes/web.php` (rotas `instalar` e
  `primeiros-passos` com nomes `instalar` e `primeiros-passos`; nesta tarefa podem apontar para um placeholder que a 3 e a 6 trocam)

**Interfaces:**
- Consumes: `SetupState::concluded()`, `SetupState::hasAdmin()`.
- Produces: rotas nomeadas `instalar` (sem `auth`) e `primeiros-passos` (`auth`, `can:admin`).

Regras, nesta ordem: concluída → segue; rota liberada → segue (`instalar`, `primeiros-passos`, `logout`, `livewire.*`, rotas de
broadcasting, `webhooks.waha`, `logo`, `restauracao*`, e o `/up` que já é fora do grupo); sem admin → `redirect()->route('instalar')`;
usuário logado admin → `redirect()->route('primeiros-passos')`; senão segue.

- [ ] **Step 1: testes que falham** (cada um apaga a marca do `TestCase`):
  - `test_sem_admin_raiz_e_login_vao_para_instalar` (`get('/')` e `get('/login')` → redirect `/instalar`)
  - `test_admin_logado_vai_para_primeiros_passos` (`actingAs(admin)->get('/inbox')` → redirect `/primeiros-passos`)
  - `test_atendente_nao_e_redirecionado` (`actingAs(atendente)->get('/inbox')` → 200)
  - `test_webhook_e_logo_passam_durante_a_configuracao` (`post('/webhooks/waha')` não é 302 para `/instalar`; `get('/marca/logo')` idem)
  - `test_instalacao_concluida_nao_redireciona` (com a marca, `get('/login')` → 200)
- [ ] **Step 2: rodar e ver falhar.**
- [ ] **Step 3: implementar** o middleware e registrar.
- [ ] **Step 4: rodar** a tarefa e os afetados; testes de HTTP sem `RefreshDatabase` que passarem a falhar (ex.:
  `LoginScreenTest`, `AppBootTest`) ganham `RefreshDatabase`. → verde.
- [ ] **Step 5: commit** `feat: instalação em configuração leva ao assistente`

### Task 3: `/instalar`, criação do primeiro admin com o código

**Files:**
- Create: `app/Livewire/Setup/Install.php`, `resources/views/livewire/setup/install.blade.php`, `tests/Feature/Setup/InstallTest.php`
- Modify: `routes/web.php` (rota `instalar` → `Install::class`)

**Interfaces:**
- Consumes: `SetupState`, `PasswordValidationRules`, `Auditor::record`, `AuditAction::UserCreated`.
- Produces: componente `App\Livewire\Setup\Install` com propriedades `code`, `name`, `email`, `password`,
  `password_confirmation` e ação `save()`.

Comportamento: `mount()` → `abort(404)` se concluída; `redirect()->route('login')` se já há admin. Layout `x-layouts::auth`, como o
login. `config('setup.code')` vazio → a tela mostra só o aviso com o comando
`php artisan app:create-user voce@exemplo.com "Seu Nome" --role=admin` e não tem formulário. `save()`: `RateLimiter` com chave
`setup-code:{ip}`, 5 por 60 s, mensagem "Muitas tentativas. Tente de novo em N segundos." no campo `code`; `hash_equals` com
erro "Código inválido." no campo `code`; valida `name` (obrigatório, máx. 255), `email` (obrigatório, e-mail, único em `users`) e
`password` (`passwordRules()`); numa transação com `pg_advisory_xact_lock(hashtext('setup.admin'))` confere `hasAdmin()` de novo
(se já tem: erro "A instalação já tem um administrador." e nada é criado); cria o usuário como o `CreateUser`
(`forceFill`: `role` admin, `active` true, `email_verified_at` agora); `Auditor::record(AuditAction::UserCreated, $user, $user->email,
['role' => 'admin'], 'Instalação')`; `Auth::login($user)`; `session()->regenerate()`; redireciona para `primeiros-passos`.

- [ ] **Step 1: testes que falham:**
  - `test_codigo_certo_cria_admin_loga_e_vai_para_primeiros_passos` (config `setup.code` = `abc123`; usuário admin ativo e
    verificado; `assertAuthenticatedAs`; redirect `/primeiros-passos`; 1 linha de auditoria `user.created` com `actor_label` `Instalação`)
  - `test_codigo_errado_nao_cria_nada` (erro em `code`, `User::count()` 0)
  - `test_sexta_tentativa_no_minuto_e_barrada_mesmo_com_codigo_certo` (5 erradas, a 6ª com o certo → erro de espera, 0 usuários)
  - `test_sem_codigo_configurado_mostra_o_comando_e_nao_tem_formulario` (`assertSee('app:create-user')`, `assertDontSee('wire:model="code"', false)`)
  - `test_com_admin_vai_para_o_login` e `test_concluida_responde_404`
  - `test_admin_criado_entre_a_tela_e_o_salvar_e_recusado` (cria um admin antes do `call('save')` → erro "já tem um administrador", 1 admin)
- [ ] **Step 2: rodar e ver falhar.**
- [ ] **Step 3: implementar** componente e view (campos Flux, como `pages::auth.login`).
- [ ] **Step 4: rodar** → verde.
- [ ] **Step 5: commit** `feat: primeiro admin pela tela /instalar com o código da instalação`

### Task 4: criação de canal compartilhada

**Files:**
- Create: `app/Actions/Channels/CreateChannel.php`, `tests/Feature/Channels/CreateChannelTest.php`
- Modify: `app/Livewire/Settings/Channels.php` (`addChannel` passa a chamar a ação)

**Interfaces:**
- Produces: `final class CreateChannel` com `__invoke(string $session, ?User $by): Channel`; valida com as mesmas regras e mensagens
  de hoje (`required`, `regex:/^[A-Za-z0-9_-]{1,60}$/`, `unique:channels,waha_session`) usando `Validator::make(...)->validate()`
  com o campo chamado `sessionName` (a `ValidationException` cai no mesmo campo da tela); cria o canal com `name` = `waha_session`
  = `$session` e grava `AuditAction::ChannelCreated`.

- [ ] **Step 1: testes que falham:** `test_cria_canal_e_audita` e `test_sessao_repetida_lanca_validacao_no_campo_session_name`.
- [ ] **Step 2: rodar e ver falhar.**
- [ ] **Step 3: implementar** e trocar o corpo de `Channels::addChannel` (mantém `$this->reset('sessionName')`).
- [ ] **Step 4: rodar** a tarefa, `SettingsAuditTest` e `ChannelsScreenTest` → verde.
- [ ] **Step 5: commit** `refactor: criação de canal numa ação compartilhada`

### Task 5: aplicar o segmento

**Files:**
- Create: `app/Services/Setup/ApplySegment.php`, `tests/Feature/Setup/ApplySegmentTest.php`

**Interfaces:**
- Consumes: `DefaultSectors::install()`, `DefaultTags::install()`, `WorkflowPublisher::create/publish`, `DefaultWorkflow::NAME`,
  `WorkflowTemplates::STANDARD`, `SetupState::setSegment/setStep`.
- Produces: `final class ApplySegment` com `const DISTRIBUIDORA = 'distribuidora'`, `const EM_BRANCO = 'em_branco'` e
  `__invoke(string $segment, User $by): void`; segmento desconhecido → `InvalidArgumentException`. Já aplicado
  (`SetupState::segment() !== null`) → não faz nada.

Tudo numa `DB::transaction`: Distribuidora → `DefaultSectors::install()`, `DefaultTags::install()`, workflow com nome
`DefaultWorkflow::NAME` procurado antes de criar (`create($name, $by, WorkflowTemplates::STANDARD)` se não existe), `publish` se
ainda não tem versão; Em branco → `Setting::put` das marcas `DefaultSectors::MARK` e `DefaultTags::MARK` (true, `bool`). Por
fim `setSegment($segment)` e `setStep('canal')`.

- [ ] **Step 1: testes que falham:**
  - `test_distribuidora_cria_setores_etiquetas_e_atendimento_padrao_publicado` (5 setores, 15 etiquetas, workflow com 1 versão, segmento e passo gravados)
  - `test_em_branco_nao_cria_nada_e_grava_as_marcas`
  - `test_segunda_chamada_nao_duplica`
  - `test_falha_no_meio_desfaz_tudo` (faz o `publish` lançar: `$this->mock(WorkflowPublisher::class)` com `create` real e
    `publish` lançando `InvalidFlowException`; espera 0 setores, 0 etiquetas, 0 workflows e `segment()` null)
  - `test_segmento_desconhecido_e_recusado`
- [ ] **Step 2: rodar e ver falhar.**
- [ ] **Step 3: implementar.**
- [ ] **Step 4: rodar** → verde.
- [ ] **Step 5: commit** `feat: segmento da instalação (Distribuidora ou Em branco)`

### Task 6: `/primeiros-passos`

**Files:**
- Create: `app/Livewire/Setup/FirstSteps.php`, `resources/views/livewire/setup/first-steps.blade.php`,
  `tests/Feature/Setup/FirstStepsTest.php`
- Modify: `routes/web.php` (rota `primeiros-passos` → `FirstSteps::class`, `auth` + `can:admin`)

**Interfaces:**
- Consumes: `SetupState`, `CompanySettings`, `ReceivesAvatar` (como em `Settings\Company`), `ApplySegment`, `CreateChannel`,
  `WorkflowPublisher::activate`, `ChannelCard`.
- Produces: componente com `name`, `sessionName` (padrão `principal`), `?int $channelId`, ações `saveCompany()`, `saveLogo()`,
  `removeLogo()`, `chooseSegment(string $segment)`, `createChannel()`, `skip()`, e o ouvinte
  `#[On('echo-private:inbox,.channel.status')] onChannelStatus(array $payload)`.

Comportamento: `mount()` → 404 se concluída; o passo vem de `SetupState::step()`, e o canal de um `Channel` já existente (volta
onde parou). `saveCompany()` valida `name` (obrigatório, máx. 120, mensagens da tela Empresa) e grava com
`CompanySettings::save` mantendo os campos de assinatura atuais; passo → `segmento`. `chooseSegment()` chama `ApplySegment`
(erro vira mensagem na tela, passo fica). `createChannel()` chama `CreateChannel`; com segmento Distribuidora, ativa o workflow
`DefaultWorkflow::NAME` no canal. A view mostra o `ChannelCard` do canal criado. `onChannelStatus()` conclui quando o canal deste
assistente está conectado (`isConnected()`); `skip()` conclui sem canal. Concluir = `SetupState::markConcluded()` e
`redirect()->route('inbox')`. Visual: layout do app, os três passos numerados no topo com o atual marcado.

- [ ] **Step 1: testes que falham:**
  - `test_atendente_nao_abre` (403) e `test_concluida_responde_404`
  - `test_empresa_grava_nome_e_avanca` (`CompanySettings::name()` = valor, `step()` = `segmento`)
  - `test_volta_no_passo_em_que_parou` (com `setup.passo` = `canal` e um canal existente, a tela mostra o cartão do canal)
  - `test_distribuidora_no_assistente_avanca_para_o_canal`
  - `test_criar_canal_na_distribuidora_ativa_o_atendimento_padrao` (`channel->workflow_id` = id do workflow)
  - `test_canal_conectado_conclui_e_vai_para_a_fila` (atualiza o canal para `WORKING` e chama `onChannelStatus(['channel_id' => id, 'status' => 'WORKING'])`)
  - `test_pular_conclui_sem_canal`
- [ ] **Step 2: rodar e ver falhar.**
- [ ] **Step 3: implementar.**
- [ ] **Step 4: rodar** → verde.
- [ ] **Step 5: conferência visual** (agente `conferente-visual`, servidor local, banco `dimetal_local` zerado só com migração e
  `SETUP_CODE` no `.env`): `/instalar` e os três passos em 720p, 1080p, 2K e celular, console sem erro de CSP.
- [ ] **Step 6: commit** `feat: primeiros passos do admin (empresa, segmento e canal)`

### Task 7: compose, fumaça, documentação e README

**Files:**
- Modify: `docker-compose.yml` (`SETUP_CODE: ${SERVICE_PASSWORD_SETUP}` na âncora `x-app-env`), `docker/smoke/compose-smoke.sh`
  (gera `SERVICE_PASSWORD_SETUP`; troca o passo 3: `/up` 200, `/login` 302 e `/instalar` 200), `docs/deploy.md`, `README.md`

`docs/deploy.md`, seção "2. Instalar": o passo 5 (terminal) vira "abra `https://seu.dominio`, digite o código (Coolify → Service
→ Environment → `SERVICE_PASSWORD_SETUP`), crie o admin e siga os primeiros passos"; o `app:create-user` fica como alternativa
de emergência. Linhas novas: o fuso vem de `APP_DISPLAY_TIMEZONE` (padrão `America/Sao_Paulo`), e "Connect to Predefined Network"
fica desligado (cada recurso com rede própria).

`README.md`: troca "Instalação no Coolify" por um passo a passo curto (requisitos; Service → Docker Compose Empty; colar o compose
da Release mais recente; domínio só no `app`; deploy; abrir a URL e usar o código; ler o QR), com link para `docs/deploy.md` nos
detalhes (atualização, backup, Google). "Rodando localmente" ganha a linha: o `db:seed` marca a instalação como concluída.

- [ ] **Step 1: rodar a fumaça antes** (`bash docker/smoke/compose-smoke.sh`) e ver o passo 3 falhar com `/login` 302.
- [ ] **Step 2: alterar** compose e fumaça; rodar de novo → passa.
- [ ] **Step 3: escrever** `docs/deploy.md` e `README.md`.
- [ ] **Step 4: commit** `docs: instalação pelo assistente no deploy e no README`
