# Navegação reorganizada e app em português — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, regra do `CLAUDE.md` do projeto) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Uma só estrutura de navegação (sidebar, cabeçalho das páginas de configuração e menu da conta), nomes novos, links no contexto e o app inteiro em português do Brasil.

**Architecture:** `App\Support\Navigation` é a lista única (rótulo, rota, portão, seção, grupo). A sidebar e a linha de irmãos de cada página de configuração (componente Blade `x-settings-page`) leem dela. Rotas, URLs e portões `can:*` não mudam. Traduções em `lang/pt_BR.json` mais os arquivos de grupo do Laravel.

**Tech Stack:** Laravel 13, Livewire 4, Flux, Pest/PHPUnit (`php artisan test`), Pint, Larastan, PHPMD.

**Spec:** `docs/superpowers/specs/2026-11-01-navegacao-reorganizada-design.md`

## Global Constraints

- Nenhuma capacidade nova ou removida. Permissões e portões das rotas inalterados (`auth`, `verified`, `can:supervisor`, `can:admin`, `password.confirm`).
- URLs e nomes de rota inalterados (`/configuracoes/*`, `/settings/*`, `configuracoes.google.callback` idêntico).
- Nomes finais: item **Fila** (grupo Atendimento); seção **Acompanhamento**; **Horário e SLA**; **Empresa e assinatura**; **Anexos e retenção**; **Minha conta**; **Meus alertas**. Seção **Configuração** com grupos **Atendimento**, **Equipe**, **Conexões**, **Empresa**.
- Linha de irmãos: só itens do grupo atual que o papel pode ver; oculta quando restar menos de 2.
- SLA continua em `CompanySettings` (sem migração). Empresa e Horário e SLA nunca sobrescrevem o valor um do outro.
- Commits em português com prefixo (`feat:`, `fix:`, `refactor:`, `test:`). Branch `feat/navegacao-reorganizada`; nunca `main`. Sem PR sem pedido: o dono já pediu o PR ao final desta entrega.
- Testes precisam de Postgres em 127.0.0.1:5432 (ver `CLAUDE.md`); rodar com `QUEUE_CONNECTION=database`.

## Review Focus

1. Supervisor em `/configuracoes/setores`, `/configuracoes/canais`, `/auditoria` continua com 403, e a sidebar/irmãos dele não listam essas páginas (Tasks 1, 2, 3).
2. Atendente não vê as seções Acompanhamento nem Configuração (Task 2).
3. Salvar Empresa não zera nem altera o SLA, e salvar o SLA não altera nome/assinatura (Task 5).
4. Evento de auditoria sem ticket ("Sistema") não gera link nem erro; ticket aberto vai à Fila, encerrado ao Histórico (Task 8).
5. Login com credencial errada e validação de formulário mostram mensagem em português (Task 9).

---

### Task 1: Lista central de navegação

**Files:**
- Create: `app/Support/Navigation.php`, `app/Support/NavItem.php`
- Test: `tests/Feature/Layout/NavigationTest.php`

**Interfaces:**
- Produces: `final class NavItem { public function __construct(public string $label, public string $route, public ?string $gate, public string $section, public ?string $group, public string $icon, public string $match) }` (`gate`: `null`, `'supervisor'` ou `'admin'`; `match`: padrão para `request()->routeIs()`).
- Produces: `Navigation::SECTION_ATENDIMENTO = 'Atendimento'`, `SECTION_ACOMPANHAMENTO = 'Acompanhamento'`, `SECTION_CONFIGURACAO = 'Configuração'`.
- Produces: `Navigation::items(): list<NavItem>` (todos, em ordem); `Navigation::tree(User $user): array<string, array<string, list<NavItem>>>` (seção → grupo (`''` quando sem grupo) → itens visíveis, sem seções vazias); `Navigation::find(string $routeName): ?NavItem`; `Navigation::siblings(User $user, string $routeName): list<NavItem>` (itens visíveis do mesmo grupo e seção, `[]` se houver menos de 2 ou a rota não estiver na lista).

- [ ] **Step 1: Write the failing test** `NavigationTest` (RefreshDatabase, `Navigation` resolvido do container). Casos, com a lista exata de rótulos esperada por papel:
  - `test_atendente_ve_so_a_secao_atendimento`: `array_keys(tree)` == `['Atendimento']`, rótulos `['Fila','Contatos','Histórico']`.
  - `test_supervisor_ve_relatorios_e_dois_itens_de_configuracao`: seções `['Atendimento','Acompanhamento','Configuração']`; Acompanhamento = `['Relatórios']`; Configuração grupos `['Atendimento'=>['Respostas rápidas'],'Equipe'=>['Usuários']]`.
  - `test_admin_ve_tudo_na_ordem_do_design`: Acompanhamento `['Relatórios','Auditoria']`; Atendimento `['Setores','Horário e SLA','Respostas rápidas']`; Equipe `['Usuários']`; Conexões `['Canais','Google Contatos']`; Empresa `['Empresa e assinatura','Anexos e retenção']`.
  - `test_irmaos_so_dentro_do_grupo_e_ocultos_com_menos_de_dois`: admin em `configuracoes.setores` → `['Setores','Horário e SLA','Respostas rápidas']`; supervisor em `configuracoes.respostas` → `[]`; admin em `configuracoes.usuarios` → `[]`.
  - `test_toda_rota_da_lista_existe_e_o_portao_confere_com_o_middleware`: para cada item, `Route::has($route)` e, para itens com portão, a rota carrega o middleware `can:<portão>`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Layout/NavigationTest.php` — Expected: FAIL (classe não existe).
- [ ] **Step 3: Implement** `NavItem` e `Navigation` em `app/Support/`. Ícones e `match` copiados da sidebar atual (`inbox`, `user-group`, `archive-box`, `chart-bar`, `shield-check`, `chat-bubble-left-right`, `squares-2x2`, `clock`, `bolt`, `users`, `building-office`, `link`, `trash`; `match` do Google é `configuracoes.google*`). Visibilidade: `$gate === null || $user->can($gate)`.
- [ ] **Step 4: Run** o mesmo comando — Expected: PASS.
- [ ] **Step 5: Commit** `feat: lista central de navegação por papel`.

### Task 2: Sidebar lê da lista

**Files:**
- Modify: `resources/views/layouts/app/sidebar.blade.php` (bloco `flux:sidebar.nav`)
- Modify: `tests/Feature/Layout/SidebarTest.php` (`test_atendente_so_ve_o_grupo_de_atendimento`, `test_supervisor_ve_gestao_e_admin_ve_os_subgrupos_de_configuracoes`)

**Interfaces:**
- Consumes: `Navigation::tree(User): array` (Task 1).

- [ ] **Step 1: Update tests first.** Atendente: contém `Fila`, `Contatos`, `Histórico`; não contém `Acompanhamento`, `Configuração`. Supervisor: contém `Acompanhamento`, `Relatórios`, `Respostas rápidas`, `Usuários`; não contém `Auditoria`, `Anexos e retenção`. Admin: contém `Auditoria`, `Horário e SLA`, `Empresa e assinatura`, `Anexos e retenção`, `Conexões`. Manter os testes de contadores e de aviso do WhatsApp como estão.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Layout/SidebarTest.php` — Expected: FAIL (nomes antigos).
- [ ] **Step 3: Implement.** O `@php` calcula `$tree = app(\App\Support\Navigation::class)->tree(auth()->user())`. Renderizar cada seção como `flux:sidebar.group` (Configuração: legenda `Configuração` e um `flux:sidebar.group` por grupo). Manter o selo `$counts['waiting']` e o texto "N aguardando / N com SLA estourado" no item com rota `inbox`. Nenhum `@can` fica na view.
- [ ] **Step 4: Run** `php artisan test tests/Feature/Layout tests/Feature/Livewire/CompanyPageTest.php` — Expected: PASS (`test_o_menu_se_chama_atendimento` segue valendo).
- [ ] **Step 5: Commit** `feat: sidebar com a lista central e nomes novos`.

### Task 3: Componente de página de configuração (6 páginas)

**Files:**
- Create: `resources/views/components/settings-page.blade.php`
- Modify: `resources/views/livewire/settings/{sectors,users,quick-replies,retention,google-contacts,company}.blade.php`
- Modify: `app/Livewire/Settings/{Sectors,Users,QuickReplies,Retention,GoogleContacts,Company}.php` (`#[Title]` com o nome novo)
- Test: `tests/Feature/Layout/SettingsPageTest.php`

**Interfaces:**
- Consumes: `Navigation::find()`, `Navigation::siblings()` (Task 1).
- Produces: `<x-settings-page title="…" subtitle="…" width="3xl|5xl|6xl">` com slot padrão (conteúdo) e slot nomeado `actions` (botão à direita, como o `x-page-header` de hoje). Rota atual lida de `request()->route()->getName()`. Mostra o caminho `Configuração › {grupo}`, a linha de irmãos (`<nav aria-label="Páginas de {grupo}">`, item atual com `aria-current="page"`) e o `x-page-header`.

- [ ] **Step 1: Write the failing test** `SettingsPageTest`: admin em `configuracoes.setores` vê `Configuração`, `Atendimento`, links para `Horário e SLA` e `Respostas rápidas` e `aria-current="page"` em `Setores`; supervisor em `configuracoes.respostas` não vê `<nav aria-label="Páginas de Atendimento">`; admin em `configuracoes.usuarios` também não.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Layout/SettingsPageTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** o componente e trocar, nas 6 views, `<section …><x-page-header …>` por `<x-settings-page …>`, mantendo o corpo, o botão de ação (slot `actions`) e a largura atual de cada página (Users `6xl`; as demais `3xl`). Títulos: `Empresa e assinatura`, `Anexos e retenção` (o texto de `page-header` também).
- [ ] **Step 4: Run** `php artisan test tests/Feature/Layout tests/Feature/Livewire` — Expected: PASS (corrigir asserts de título que mudaram).
- [ ] **Step 5: Commit** `feat: cabeçalho comum nas páginas de configuração`.

### Task 4: Canais e Horário saem do layout pessoal

**Files:**
- Modify: `resources/views/livewire/settings/channels.blade.php`, `resources/views/livewire/settings/business-hours-page.blade.php`
- Modify: `app/Livewire/Settings/Channels.php` (se tiver `#[Title]`), `app/Livewire/Settings/BusinessHoursPage.php` (`#[Title('Horário e SLA')]`)
- Test: `tests/Feature/Livewire/ChannelsScreenTest.php`, `tests/Feature/Livewire/BusinessHoursPageTest.php`

- [ ] **Step 1: Write the failing tests:** Canais: admin vê `Conexões`, links para `Google Contatos`, não vê `Perfil`/`Segurança`/`Aparência`. Horário: admin vê `Horário e SLA`, não vê `Perfil`.
- [ ] **Step 2: Run** os dois arquivos — Expected: FAIL.
- [ ] **Step 3: Implement.** Remover `@include('partials.settings-heading')` e `<x-pages::settings.layout>`; usar `<x-settings-page title="Canais" subtitle="Conexão do WhatsApp" width="5xl">` e `title="Horário e SLA"` (subtítulo: "Faixas por dia, feriados, resposta fora do horário e prazo de primeira resposta"). Conteúdo interno intacto.
- [ ] **Step 4: Run** `php artisan test tests/Feature/Livewire/ChannelsScreenTest.php tests/Feature/Livewire/BusinessHoursPageTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: Canais e Horário no cabeçalho comum de configuração`.

### Task 5: SLA vai para Horário e SLA

**Files:**
- Modify: `app/Settings/CompanySettings.php` (`save`), `app/Livewire/Settings/Company.php`, `app/Livewire/Settings/BusinessHoursPage.php`
- Modify: `resources/views/livewire/settings/company.blade.php`, `resources/views/livewire/settings/business-hours-page.blade.php`
- Test: `tests/Feature/Livewire/CompanyPageTest.php`, `tests/Feature/Livewire/BusinessHoursPageTest.php`, `tests/Feature/Inbox/SignatureTest.php` (se usar `save`)

**Interfaces:**
- Produces: `CompanySettings::save(array $values): void` grava **só as chaves presentes** em `$values` (`name`, `signatureEnabled`, `signatureTemplate`, `signaturePosition`, `slaMinutes`).
- Produces: `BusinessHoursPage::$slaMinutes` (`int`, padrão do `CompanySettings::slaMinutes()` no `mount`) e `saveSla(): void` (`authorize('admin')`, valida `required|integer|between:1,1440`, mensagem `O SLA vai de 1 a 1440 minutos.`, grava `CompanySettings::save(['slaMinutes' => …])`).

- [ ] **Step 1: Write/adjust failing tests.** `CompanyPageTest`: remover `slaMinutes` do salvar/validação; novo `test_salvar_empresa_nao_mexe_no_sla` (SLA 25 salvo antes, `save` da Empresa, `CompanySettings::slaMinutes() === 25`). `BusinessHoursPageTest`: `test_admin_salva_o_sla`, `test_sla_fora_de_1_a_1440_e_recusado` (0 e 1441), `test_salvar_o_sla_nao_mexe_na_assinatura`, `test_so_admin_salva_o_sla` (supervisor: 403 na rota).
- [ ] **Step 2: Run** esses dois arquivos — Expected: FAIL.
- [ ] **Step 3: Implement** `save` parcial; tirar `slaMinutes` do `Company` (propriedade, `mount`, validação, mensagem, chamada de `save`) e do formulário; adicionar o formulário de SLA e `saveSla` em `BusinessHoursPage`. Subtítulo de Empresa: "Nome e assinatura que aparecem nas mensagens."
- [ ] **Step 4: Run** `php artisan test tests/Feature/Livewire tests/Feature/Inbox/SignatureTest.php tests/Feature/Sla` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: prazo de primeira resposta em Horário e SLA`.

### Task 6: Minha conta (páginas pessoais)

**Files:**
- Modify: `resources/views/partials/settings-heading.blade.php`, `resources/views/pages/settings/layout.blade.php`, `resources/views/components/desktop-user-menu.blade.php`, `resources/views/layouts/app/sidebar.blade.php` (menu do avatar mobile)
- Modify: `resources/views/pages/settings/⚡{profile,security,appearance,alerts}.blade.php` (`#[Title]` em português)
- Test: `tests/Feature/Layout/AccountMenuTest.php`

- [ ] **Step 1: Write the failing test:** qualquer papel em `profile.edit` vê `Minha conta`, `Perfil`, `Segurança`, `Aparência`, `Meus alertas` e não vê `Settings`, `Profile`, `Security`, `Appearance`; o HTML da Fila contém `Minha conta` e `Sair`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Layout/AccountMenuTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** textos diretos em português nesses cinco arquivos (rótulos de navegação e cabeçalhos: `Minha conta`, `Gerencie seu perfil e as configurações da sua conta`, `Perfil`, `Segurança`, `Aparência`, `Meus alertas`, `Sair`); os demais textos das 4 páginas ficam para a Task 9. Manter `data-test="logout-button"`.
- [ ] **Step 4: Run** `php artisan test tests/Feature/Layout tests/Feature/Settings tests/Feature/Alertas` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: menu Minha conta em português`.

### Task 7: Código morto

**Files:**
- Delete: `resources/views/pages/auth/register.blade.php`, `resources/views/layouts/app/header.blade.php`
- Test: `tests/Feature/Layout/NavigationTest.php` (adicionar um caso)

- [ ] **Step 1: Confirm** com `codegraph_explore` e `grep` que nenhum teste, rota, view ou provider referencia `pages::auth.register`, `layouts.app.header` ou `x-layouts::app.header`. Se algo referenciar, parar e avisar o dono.
- [ ] **Step 2: Write the failing test** `test_nao_ha_rota_de_cadastro`: `Route::has('register')` é falso e `get('/register')` é 404.
- [ ] **Step 3: Delete** os dois arquivos.
- [ ] **Step 4: Run** `php artisan test tests/Feature/Auth tests/Feature/Layout` — Expected: PASS.
- [ ] **Step 5: Commit** `refactor: remove view de cadastro e layout de barra sem uso`.

### Task 8: Links no contexto

**Files:**
- Modify: `resources/views/livewire/reports/index.blade.php` (linhas de "Aguardando resposta além do SLA"), `resources/views/livewire/audit/index.blade.php` (coluna Ticket)
- Test: `tests/Feature/Reports/ReportsLinksTest.php` (criar), `tests/Feature/Livewire/AuditPageTest.php`

- [ ] **Step 1: Write the failing tests.** Relatórios: o nome do cliente de cada linha de espera é link para `route('inbox')`. Auditoria: evento de ticket **encerrado** → link `route('historico', ['ticketId' => $ticket->id])` no protocolo; evento de ticket **aberto** → `route('inbox')`; evento de sistema sem ticket mostra `—` sem link e a página abre normalmente.
- [ ] **Step 2: Run** esses testes — Expected: FAIL.
- [ ] **Step 3: Implement** os links com `wire:navigate`. Escolha por `$event->ticket->status === TicketStatus::ENCERRADO`.
- [ ] **Step 4: Run** `php artisan test tests/Feature/Reports tests/Feature/Livewire/AuditPageTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: Relatórios e Auditoria levam ao ticket`.

### Task 9: Tradução do app inteiro

**Files:**
- Create: `lang/pt_BR.json`, `lang/pt_BR/auth.php`, `lang/pt_BR/passwords.php`, `lang/pt_BR/validation.php`
- Modify: views que ainda tenham inglês depois das Tasks 3–7 (`pages/auth/*`, `pages/settings/⚡*`, `partials/*`, `components/*`)
- Test: `tests/Feature/Layout/TranslationTest.php`

**Interfaces:**
- `lang/pt_BR.json`: uma entrada por frase em inglês usada em `__('…')` no repositório (levantamento atual: ≈109 chaves; parâmetros `:time`, `:name` preservados).

- [ ] **Step 1: Write the failing tests** `TranslationTest`:
  - `test_toda_chave_usada_em_codigo_tem_traducao`: varre `app/` e `resources/views/` com a regex de `__('…')` e `__("…")` (ignora chaves com ponto no formato `grupo.chave`) e exige cada chave em `lang/pt_BR.json`.
  - `test_login_com_senha_errada_responde_em_portugues`: `post('/login', credenciais inválidas)` → erro em `email`, texto não contém `These credentials` nem `do not match`.
  - `test_telas_publicas_sem_ingles`: `get('/login')`, `get('/forgot-password')` e o desafio de 2FA (se acessível) não contêm `Forgot password`, `Log in`, `Email address`, `Reset password`, `Two-factor`.
  - `test_validacao_em_portugues`: `post('/login', [])` → mensagem de `email` igual a `O campo e-mail é obrigatório.`
- [ ] **Step 2: Run** `php artisan test tests/Feature/Layout/TranslationTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement.** Levantar as chaves com a mesma regex do teste, traduzir todas para pt_BR no JSON; criar `auth.php` (`failed`, `password`, `throttle`), `passwords.php` e `validation.php` (regras e `attributes` para `email`, `password`, `name`, `current_password`, `code`) em pt_BR. Textos próprios do app que sigam em inglês nas views entram direto em português na view. `pt_BR/pagination.php` fica como está.
- [ ] **Step 4: Run** `php artisan test` (suíte inteira) — Expected: PASS (corrigir asserts de testes antigos que dependem do texto em inglês).
- [ ] **Step 5: Commit** `feat: app inteiro em português (idioma pt_BR)`.

### Task 10: Verificação e PR

- [ ] **Step 1:** Subir o Postgres de teste (`docker run -d --name pg-test …`, ver `CLAUDE.md`) e rodar `QUEUE_CONNECTION=database composer test` (Pint, Larastan, PHPMD e testes). Ler a saída inteira de cada um.
- [ ] **Step 2:** Rodar `composer coverage` e conferir ≥ 80%.
- [ ] **Step 3:** Conferir à mão no navegador (servidor local, **não** rodar testes ao mesmo tempo): a Fila e a conta de um atendente, de um supervisor e de um admin — sidebar, irmãos, "Minha conta", Horário e SLA.
- [ ] **Step 4:** Revisão do branch inteiro com `superpowers:requesting-code-review` (o advisor serve) e correção dos achados críticos e importantes.
- [ ] **Step 5:** `docker rm -f pg-test`; `git push -u origin feat/navegacao-reorganizada`; abrir o PR em português contra `main` (sem citar pesquisa de referências). Mensagem final com "Rulings I made" e "Deferred minors".
