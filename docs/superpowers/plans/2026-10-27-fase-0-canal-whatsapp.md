# Fase 0 — Canal WhatsApp (WAHA/GOWS) — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** deixar de pé a base do produto (Laravel 13 + Livewire 4 + PostgreSQL 18) com login por
papel, os cinco setores nos três estados, configuração no banco e uma conexão WhatsApp real via
WAHA/GOWS que recebe eventos, mostra status/QR e envia texto de teste.

**Architecture:** o app Laravel recebe webhooks do WAHA (HMAC-SHA512), grava o evento bruto e
processa em fila; o painel lê o status da sessão e dispara ações por um cliente HTTP próprio,
usando configurações lidas do banco (não do `.env`). Deploy em Coolify 4.5.0, projetos `main` e
`beta` separados, só o app com domínio público.

**Tech Stack:** PHP 8.5, Laravel 13.39, Livewire 4.4.8, Flux 2.13, Tailwind 4.3, PHPUnit 12.5,
PostgreSQL 18.6, Redis 8.10, Node 24 LTS, WAHA 2026.9.2 (`:gows`), Coolify 4.5.0.

**Specs:**
- `docs/superpowers/specs/2026-10-27-whatsapp-atendimento-design.md` (seções 2, 6–9, 11, 14–17)
- `docs/superpowers/specs/waha-config.md` (matriz de ambiente do WAHA — leitura obrigatória antes
  das Tasks 9 e 11)

## Roadmap dos planos

| Plano | Escopo | Sai funcionando quando |
|---|---|---|
| **0 (este)** | Base, login, setores/papéis, configuração no banco, canal WAHA, webhook, envio de teste, deploy Coolify | Número conectado, evento chega, texto de teste sai no `main` e no `beta` |
| 1 | Contatos, mensagens, tickets, fila, assumir/transferir/encerrar, horário, mídia, backfill, inbox em tempo real | A operação atende sem o Cam Krolik |
| 2 | Motor de bot, construtor visual, publicação de versão, eventos | Pré-atendimento automático nos cinco setores |
| 3 | Relatórios, SLA vivo, busca, exportação, retenção de 90 dias, telas de empresa/SMTP/backup | A direção audita sozinha |

## Global Constraints

- Setores, nesta ordem: **Comercial, Financeiro, Vendas, Diretoria, SAC**.
- Estados de setor: `ativo` (menu + recebe), `fora_do_menu` (recebe, não aparece no menu),
  `desativado` (não recebe, some das listas, mantém histórico). Desativar com ticket aberto é
  bloqueado — a checagem entra no Plano 1, quando existir ticket.
- Papéis: `admin`, `supervisor`, `atendente`. Sem cadastro público; usuário criado por admin.
- WhatsApp via WAHA self-hosted, engine **GOWS**, imagem `devlikeapro/waha:gows`. Menu é texto.
- Conexão do número por **QR ou código de pareamento** (`POST /api/{session}/auth/request-code`);
  o QR nunca some da tela, porque o código nem sempre está disponível.
- **Backfill é opcional e desligado por padrão.** Quem conecta o número escolhe importar ou não e
  por quantos dias (sugestão 5). Nesta fase a escolha é gravada em `channels.backfill_days`; a
  importação em si roda no Plano 1, quando contatos/mensagens/tickets existirem.
- PostgreSQL 18, Redis 8, fuso `America/Sao_Paulo`, 1.000–3.000 mensagens/dia.
- Uma única empresa (Dimetal Distribuidora), uso interno, interface em português.
- **Configuração no banco:** no `.env` do app só `APP_KEY`, banco, Redis e domínio. WAHA, empresa,
  horário, SLA, retenção, backfill, respostas rápidas, SMTP e backup vão para a tabela `settings`
  e telas. Nesta fase só a tela do WAHA é entregue; as demais são Plano 3.
- Identidade: tinta `#101513`, secundário `#46514D`, apoio `#6F7A75`, linha `#E2E0D9`, canvas
  `#F5F4F0`, superfície `#FFFFFF`, acento `#12705A` (escuro `#0B4D3D`, suave `#E3F1EB`), aviso
  `#9A5B0B`, erro `#B42318`, bot `#4A5A66`. Inter na interface, JetBrains Mono em
  protocolo/telefone/horário. Espaçamento múltiplo de 4; raios 6/10/14/pill; densidade compacta.
- Código pequeno e de responsabilidade única: arquivo grande é sinal de erro, não de progresso.
  Comentário explica o porquê, nunca o quê.
- **PHPUnit 12.5 em classes**, não Pest. O starter kit oficial não usa mais Pest.
- Fora deste plano: tickets, protocolo, bot, mídia, Reverb, relatórios, telas de
  empresa/SMTP/backup. A **execução** do backfill também é Plano 1; aqui só se registra a escolha.
- `main` protegida: PR + CI verde. Revisor obrigatório só quando existir uma segunda conta no
  GitHub — o GitHub não permite autoaprovar o próprio PR. `beta` recebe push direto.

## Pesquisa e decisões técnicas

Busca no GitHub por cliente PHP de WAHA (regra do `AGENTS.md`):

| Projeto | Sinal | Leitura |
|---|---|---|
| `Orlinkzz/waha-php` | 8★, MIT, 2026 | Cliente fino; traz a sequência anti-ban `sendSeen → startTyping → delay → stopTyping → sendText` |
| `DenLopes/waha-laravel-sdk` | 2★ | SDK tipado, pouca adoção, cobre parte da API |
| `darkclow4/laravel-waha-messages` | 0★ | Pacote mínimo, sem manutenção |
| `RogerioAmaral/waha-php` | 0★ | Incompleto |

Nenhum tem adoção que sustente produção → **cliente HTTP próprio e fino** (`WahaClient`), sem
dependência externa. A sequência de digitação do `waha-php` é referência para o Plano 1.

WAHA pesquisado em `waha.devlike.pro/docs` (resultado em `specs/waha-config.md`). Três correções
que valem para o plano:

1. O webhook usa **HMAC-SHA512 do corpo bruto** (`X-Webhook-Hmac`), não header de segredo fixo.
2. O status de QR é **`SCAN_QR_CODE`**, não `SCAN_QR`.
3. Presença "online por alguns segundos e depois offline" é nativo:
   `WAHA_PRESENCE_AUTO_ONLINE_DURATION_SECONDS=25`. Não escrevemos código para isso; só mandamos
   `offline` depois de enviar, para o celular do atendente voltar a receber push.

Starter kit: `laravel/livewire-starter-kit` (Fortify, Flux, Tailwind 4, PHPUnit, Vite Plus). Telas
do produto em componentes class-based (`make:livewire ... --class`), porque são grandes demais
para single-file.

## Review Focus

Falhas que o design implica e nenhuma task cobre sozinha — cada uma tem teste na task dona:

1. **Segredo vazando:** `waha.api_key` e `waha.webhook_secret` não podem aparecer em texto puro no
   banco, em log ou na tela (Task 5).
2. **Webhook forjado:** corpo alterado com HMAC válido de outro corpo precisa falhar (Task 8).
3. **Webhook repetido:** retry do WAHA não pode duplicar evento nem voltar status antigo (Task 8).
4. **WAHA fora do ar:** tela e envio mostram erro legível, nunca 500 (Tasks 7, 9, 10).
5. **Texto com acento/emoji:** gravar e reenviar não pode corromper (Tasks 7 e 8).
6. **Sessão perdida no redeploy:** documentação e volume garantem que não se pede QR de novo
   (Task 11).
7. **Beta mandando mensagem real:** sessão e número separados, sem cruzamento de credenciais
   (Task 11).
8. **Código de pareamento indisponível:** quando o WAHA recusa o código, a tela ainda mostra o QR e
   não trava a conexão (Task 9).

## Estrutura de arquivos

```
compose.yaml                                   postgres 18 + redis 8 (dev local)
docker/postgres/init.sql                       cria dimetal_test
.github/workflows/ci.yml                       PHPUnit + Larastan + Pint
.github/dependabot.yml                         composer, npm, actions
phpstan.neon                                   Larastan nível 6
LICENSE                                        MIT
config/app.php                                 timezone America/Sao_Paulo
phpunit.xml                                    testes no PostgreSQL
resources/css/app.css                          tokens + fontes
resources/views/layouts/auth/simple.blade.php  shell do login
resources/views/pages/auth/login.blade.php     login conforme mockup
resources/views/components/ui/*.blade.php      field, button
app/Enums/UserRole.php                         admin/supervisor/atendente
app/Enums/SectorStatus.php                     ativo/fora_do_menu/desativado
app/Enums/ChannelStatus.php                    STOPPED..WORKING..FAILED
app/Models/Setting.php                         chave/valor, segredo cifrado
app/Models/Sector.php                          setor + escopos
app/Models/Channel.php                         número conectado
app/Models/User.php                            role, active, sectors()
app/Models/WahaEvent.php                       log bruto do webhook
app/Settings/WahaSettings.php                  leitura tipada das chaves do WAHA
app/Services/Waha/WahaClient.php               HTTP para o WAHA
app/Services/Waha/WahaRequestException.php     erro de chamada
app/Http/Controllers/Webhooks/WahaWebhookController.php
app/Jobs/HandleWahaEvent.php                   reage a session.status
app/Livewire/Settings/WhatsApp.php             tela Configurações > WhatsApp
app/Livewire/Settings/Channels.php             tela Configurações > Canais
database/seeders/{SectorSeeder,AdminUserSeeder}.php
routes/web.php                                 webhook + telas
tests/Feature/...                              um arquivo por task
```

---

## Task 1: Base local com a stack de 27/10/2026

**Files:**
- Create: `compose.yaml`, `docker/postgres/init.sql`, `tests/Feature/AppBootTest.php`
- Modify: `.env`, `.env.example`, `config/app.php`, `phpunit.xml`

**Interfaces:**
- Consumes: nada.
- Produces: app Laravel 13 rodando em `C:\Projetos\chatbot`, bancos `dimetal` e `dimetal_test` no
  PostgreSQL 18, `php artisan test` verde com PHPUnit.

- [ ] **Step 1: Subir Postgres 18 e Redis 8**

`compose.yaml`:

```yaml
services:
  postgres:
    image: postgres:18-alpine
    environment:
      POSTGRES_DB: dimetal
      POSTGRES_USER: dimetal
      POSTGRES_PASSWORD: secret
    ports: ["5432:5432"]
    volumes:
      - pgdata:/var/lib/postgresql/data
      - ./docker/postgres/init.sql:/docker-entrypoint-initdb.d/init.sql:ro
  redis:
    image: redis:8-alpine
    ports: ["6379:6379"]
volumes:
  pgdata:
```

`docker/postgres/init.sql`:

```sql
CREATE DATABASE dimetal_test;
```

Rode `docker compose up -d postgres redis` e confirme com `docker compose ps`.

- [ ] **Step 2: Criar o app**

O starter kit exige PHP 8.3+; usamos 8.5. Em `C:\Projetos`:

```powershell
composer global require laravel/installer
laravel new dimetal-tmp
```

Responda: starter kit **Livewire**, framework de teste **PHPUnit**, banco **PostgreSQL**,
instalar/buildar assets **sim**, inicializar git **não**.

```powershell
robocopy C:\Projetos\dimetal-tmp C:\Projetos\chatbot /E /MOVE /NFL /NDL /NJH /NJS
Remove-Item C:\Projetos\dimetal-tmp -Recurse -Force
```

Saída do robocopy entre 0 e 7 é sucesso.

- [ ] **Step 3: Apontar para PostgreSQL e fuso local**

No `.env` e `.env.example`:

```
APP_NAME="Dimetal Distribuidora"
APP_LOCALE=pt_BR
APP_FAKER_LOCALE=pt_BR
APP_TIMEZONE=America/Sao_Paulo
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=dimetal
DB_USERNAME=dimetal
DB_PASSWORD=secret
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Em `config/app.php`: `'timezone' => env('APP_TIMEZONE', 'America/Sao_Paulo')`.

Em `phpunit.xml`, troque o bloco SQLite por:

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_DATABASE" value="dimetal_test"/>
<env name="DB_USERNAME" value="dimetal"/>
<env name="DB_PASSWORD" value="secret"/>
<env name="QUEUE_CONNECTION" value="sync"/>
```

Rode `php artisan migrate` e `php artisan test`.

- [ ] **Step 4: Teste de guarda**

`tests/Feature/AppBootTest.php`:

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppBootTest extends TestCase
{
    public function test_login_responde_e_o_app_roda_no_fuso_de_sao_paulo(): void
    {
        $this->get('/login')->assertOk();

        $this->assertSame('America/Sao_Paulo', config('app.timezone'));
        $this->assertSame('America/Sao_Paulo', now()->timezoneName);
    }
}
```

Rode `php artisan test --filter=AppBootTest` e depois a suíte inteira.

- [ ] **Step 5: Commit**

```powershell
git init
git add -A
git commit -m "chore: base Laravel 13 com PHP 8.5, PostgreSQL 18 e fuso local"
```

---

## Task 2: Repositório público, CI e portões de qualidade

**Files:**
- Create: `LICENSE`, `.github/workflows/ci.yml`, `.github/dependabot.yml`, `phpstan.neon`
- Modify: `README.md`, `composer.json`

**Interfaces:**
- Consumes: app da Task 1.
- Produces: repositório público `dimetal-atendimento`, CI que roda em todo PR, Larastan nível 6,
  Pint, Dependabot, `main` protegida.

- [ ] **Step 1: Licença e identificação**

Crie `LICENSE` com o texto MIT padrão, ano 2026, titular `Dimetal Distribuidora`.

No `README.md`, troque o conteúdo do starter kit por: nome, uma frase sobre o que é, stack, como
rodar localmente (Task 1), e a frase "Projeto interno da Dimetal Distribuidora. Sem cadastro
público."

- [ ] **Step 2: Larastan nível 6**

`composer require --dev larastan/larastan:^3.9` já vem no starter kit; confirme. Crie
`phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app
        - routes
    level: 6
    treatPhpDocTypesAsCertain: false
```

No `composer.json`, adicione aos scripts:

```json
"lint": ["pint --test"],
"analyse": ["phpstan analyse --memory-limit=1G"]
```

Rode `composer analyse` e corrija o que aparecer antes de seguir.

- [ ] **Step 3: CI**

`.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [main, beta]
  pull_request:

jobs:
  tests:
    runs-on: ubuntu-latest

    services:
      postgres:
        image: postgres:18-alpine
        env:
          POSTGRES_DB: dimetal_test
          POSTGRES_USER: dimetal
          POSTGRES_PASSWORD: secret
        ports: ["5432:5432"]
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5
      redis:
        image: redis:8-alpine
        ports: ["6379:6379"]
        options: >-
          --health-cmd "redis-cli ping"
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    steps:
      - uses: actions/checkout@v4

      - name: PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: "8.5"
          extensions: pdo_pgsql, redis, bcmath, intl
          coverage: none

      - name: Node
        uses: actions/setup-node@v4
        with:
          node-version: "24"
          cache: npm

      - name: Composer
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Assets
        run: npm ci && npm run build

      - name: Ambiente
        run: |
          cp .env.example .env
          php artisan key:generate

      - name: Pint
        run: vendor/bin/pint --test

      - name: Larastan
        run: vendor/bin/phpstan analyse --memory-limit=1G --no-progress

      - name: PHPUnit
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: dimetal_test
          DB_USERNAME: dimetal
          DB_PASSWORD: secret
          REDIS_HOST: 127.0.0.1
          QUEUE_CONNECTION: sync
        run: php artisan test
```

- [ ] **Step 4: Dependabot**

`.github/dependabot.yml`:

```yaml
version: 2
updates:
  - package-ecosystem: composer
    directory: "/"
    schedule: { interval: weekly }
    open-pull-requests-limit: 5
  - package-ecosystem: npm
    directory: "/"
    schedule: { interval: weekly }
    open-pull-requests-limit: 5
  - package-ecosystem: github-actions
    directory: "/"
    schedule: { interval: weekly }
```

- [ ] **Step 5: Publicar e proteger**

```powershell
gh repo create dimetal-atendimento --public --source=. --remote=origin --push
```

Depois do primeiro CI verde:

```powershell
gh api -X PUT repos/:owner/dimetal-atendimento/branches/main/protection `
  -f "required_status_checks[strict]=true" `
  -f "required_status_checks[contexts][]=tests" `
  -f "enforce_admins=true" `
  -F "required_pull_request_reviews=null" `
  -F "restrictions=null"
```

Sem revisor obrigatório porque o GitHub não deixa aprovar o próprio PR. Quando houver uma segunda
conta, troque `-F "required_pull_request_reviews=null"` por
`-f "required_pull_request_reviews[required_approving_review_count]=1"`.

Crie a branch de trabalho: `git checkout -b beta && git push -u origin beta`.

- [ ] **Step 6: Commit**

```powershell
git add -A
git commit -m "chore: CI com PHPUnit, Larastan 6, Pint e Dependabot"
git push
```

---

## Task 3: Login conforme o mockup, com cadastro desligado

**Files:**
- Create: `resources/views/components/ui/field.blade.php`,
  `resources/views/components/ui/button.blade.php`, `tests/Feature/Auth/LoginScreenTest.php`
- Modify: `resources/css/app.css`, `resources/views/layouts/auth/simple.blade.php`,
  `resources/views/pages/auth/login.blade.php`, `config/fortify.php`,
  `tests/Feature/Auth/RegistrationTest.php`

**Interfaces:**
- Consumes: app da Task 1.
- Produces: `<x-ui.field name="email" label="E-mail" type="email" />`,
  `<x-ui.button variant="primary" type="submit">`; tokens Tailwind `ink`, `ink-2`, `ink-3`,
  `line`, `canvas`, `surface`, `accent`, `accent-deep`, `accent-soft`, `warn`, `danger`, `bot`;
  fontes `font-sans` (Inter) e `font-mono` (JetBrains Mono).

- [ ] **Step 1: Teste do login (falha antes)**

`tests/Feature/Auth/LoginScreenTest.php`:

```php
<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class LoginScreenTest extends TestCase
{
    public function test_login_interno_mostra_a_marca_e_nao_oferece_cadastro(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Dimetal Distribuidora')
            ->assertSee('Entrar')
            ->assertDontSee('Criar conta')
            ->assertDontSee('Esqueci minha senha');
    }

    public function test_cadastro_publico_nao_existe(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
```

Rode `php artisan test --filter=LoginScreenTest`: falha em "Dimetal Distribuidora".

- [ ] **Step 2: Fontes e tokens**

`npm install @fontsource/inter @fontsource/jetbrains-mono`.

Em `resources/css/app.css`, importe 400/500/600 de Inter e 400/500 de JetBrains Mono, e substitua o
`@theme` do starter kit (mantendo o resto):

```css
@theme {
    --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
    --font-mono: 'JetBrains Mono', ui-monospace, monospace;

    --color-ink: #101513;
    --color-ink-2: #46514D;
    --color-ink-3: #6F7A75;
    --color-line: #E2E0D9;
    --color-canvas: #F5F4F0;
    --color-surface: #FFFFFF;
    --color-accent: #12705A;
    --color-accent-deep: #0B4D3D;
    --color-accent-soft: #E3F1EB;
    --color-warn: #9A5B0B;
    --color-danger: #B42318;
    --color-bot: #4A5A66;
}
```

- [ ] **Step 3: Componentes mínimos**

`ui/field.blade.php`: props `name`, `label`, `type` (padrão `text`), `value`; label 13px
`text-ink-2`, input 36px, `border-line`, raio 10, `bg-surface`, foco `ring-accent`, erro em
`text-danger` 12px.

`ui/button.blade.php`: prop `variant` (`primary` padrão, `secondary`, `ghost`) e `type`
(`button` padrão); primary `bg-accent text-white hover:bg-accent-deep`, secondary
`border border-line text-ink`, ghost sem borda; 36px, raio 10, 13px/600.

- [ ] **Step 4: Shell e página**

`layouts/auth/simple.blade.php`: remova `class="dark"` e o tema escuro; fundo `bg-canvas`; marca
"Dimetal Distribuidora" no topo; card branco central de 400px, raio 14, borda `line`, padding 32;
rodapé com CNPJ `12.345.678/0001-90` e "Todo acesso é registrado na auditoria"; mantenha
`@fluxScripts` e o grupo de toast.

`pages/auth/login.blade.php`: título "Entrar", subtítulo "Use o acesso criado pelo administrador";
campos E-mail e Senha; checkbox "Manter conectado"; botão primary "Entrar" largura total; texto
"O acesso é criado pelo administrador. Precisa de um novo? Fale com Rafael Costa."; sem link de
cadastro, sem passkey, sem link de senha. Remova `<x-passkey-verify />` e o bloco de registro.

- [ ] **Step 5: Desligar cadastro e verificação**

Em `config/fortify.php`, remova `Features::registration()` e `Features::emailVerification()`.
Atualize `tests/Feature/Auth/RegistrationTest.php` para esperar 404 em `/register` (ou apague o
teste de cadastro, mantendo o de login).

Rode `php artisan test --filter="LoginScreenTest|RegistrationTest"`, `npm run build` e a suíte.

- [ ] **Step 6: Commit**

```powershell
git add -A
git commit -m "feat: login interno com identidade Dimetal e cadastro desligado"
```

---

## Task 4: Setores nos três estados, papéis e usuários

**Files:**
- Create: `app/Enums/UserRole.php`, `app/Enums/SectorStatus.php`, `app/Models/Sector.php`,
  `database/migrations/*_add_role_and_active_to_users_table.php`,
  `database/migrations/*_create_sectors_table.php`,
  `database/migrations/*_create_sector_user_table.php`, `database/factories/SectorFactory.php`,
  `database/seeders/SectorSeeder.php`, `database/seeders/AdminUserSeeder.php`,
  `app/Console/Commands/CreateUser.php`, `tests/Feature/Domain/SectorAndRoleTest.php`
- Modify: `app/Models/User.php`, `database/factories/UserFactory.php`,
  `app/Providers/AppServiceProvider.php`, `app/Providers/FortifyServiceProvider.php`,
  `database/seeders/DatabaseSeeder.php`, `.env.example`

**Interfaces:**
- Consumes: `User` do starter kit, app da Task 1.
- Produces:
  - `UserRole::ADMIN|SUPERVISOR|ATENDENTE` (`admin`, `supervisor`, `atendente`), `label(): string`.
  - `SectorStatus::ATIVO|FORA_DO_MENU|DESATIVADO` (`ativo`, `fora_do_menu`, `desativado`),
    `label(): string`, `appearsInMenu(): bool`, `receivesTickets(): bool`.
  - `Sector`: `name`, `menu_order`, `status` (cast), escopos `visibleInMenu()` e
    `receivingTickets()`.
  - `User::$role`, `User::$active`, `User::sectors()`, `isAdmin()`, `canSeeAllSectors()`.
  - Gates `admin` e `supervisor` (inclui admin).
  - `php artisan app:create-user {email} {name} {--role=atendente}`.

- [ ] **Step 1: Testes (falham)**

`tests/Feature/Domain/SectorAndRoleTest.php`:

```php
<?php

namespace Tests\Feature\Domain;

use App\Enums\SectorStatus;
use App\Enums\UserRole;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SectorAndRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_cria_os_cinco_setores_na_ordem_do_menu(): void
    {
        $this->seed();

        $this->assertSame(
            ['Comercial', 'Financeiro', 'Vendas', 'Diretoria', 'SAC'],
            Sector::orderBy('menu_order')->pluck('name')->all()
        );
    }

    public function test_tres_estados_controlam_menu_e_recebimento(): void
    {
        $this->assertTrue(SectorStatus::ATIVO->appearsInMenu());
        $this->assertTrue(SectorStatus::ATIVO->receivesTickets());

        $this->assertFalse(SectorStatus::FORA_DO_MENU->appearsInMenu());
        $this->assertTrue(SectorStatus::FORA_DO_MENU->receivesTickets());

        $this->assertFalse(SectorStatus::DESATIVADO->appearsInMenu());
        $this->assertFalse(SectorStatus::DESATIVADO->receivesTickets());
    }

    public function test_escopos_do_setor_respeitam_o_estado(): void
    {
        Sector::factory()->create(['name' => 'Ativo', 'status' => SectorStatus::ATIVO]);
        Sector::factory()->create(['name' => 'Oculto', 'status' => SectorStatus::FORA_DO_MENU]);
        Sector::factory()->create(['name' => 'Morto', 'status' => SectorStatus::DESATIVADO]);

        $this->assertSame(['Ativo'], Sector::visibleInMenu()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(
            ['Ativo', 'Oculto'],
            Sector::receivingTickets()->pluck('name')->all()
        );
    }

    public function test_gate_admin_separa_admin_de_supervisor_e_atendente(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $atendente = User::factory()->create(['role' => UserRole::ATENDENTE]);

        $this->assertTrue(Gate::forUser($admin)->allows('admin'));
        $this->assertFalse(Gate::forUser($supervisor)->allows('admin'));
        $this->assertTrue(Gate::forUser($supervisor)->allows('supervisor'));
        $this->assertFalse(Gate::forUser($atendente)->allows('supervisor'));
    }

    public function test_atendente_pode_pertencer_a_mais_de_um_setor(): void
    {
        $comercial = Sector::factory()->create(['name' => 'Comercial']);
        $vendas = Sector::factory()->create(['name' => 'Vendas']);
        $user = User::factory()->create(['role' => UserRole::ATENDENTE]);

        $user->sectors()->attach([$comercial->id, $vendas->id]);

        $this->assertCount(2, $user->fresh()->sectors);
    }

    /** @return array<string, array{UserRole}> */
    public static function papeisDeTeste(): array
    {
        return [
            'supervisor' => [UserRole::SUPERVISOR],
            'atendente' => [UserRole::ATENDENTE],
        ];
    }

    #[DataProvider('papeisDeTeste')]
    public function test_usuario_inativo_nao_consegue_entrar(UserRole $role): void
    {
        $user = User::factory()->create([
            'role' => $role,
            'password' => 'senha-forte',
            'active' => false,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'senha-forte']);

        $this->assertGuest();
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

`php artisan test --filter=SectorAndRoleTest` — falha por model/tabela inexistente.

- [ ] **Step 3: Migrações, enums e models**

Colunas:
- `users.role` string padrão `atendente` + índice; `users.active` boolean padrão `true`.
- `sectors`: `name` único, `menu_order` unsigned int padrão 0, `status` string padrão `ativo`,
  timestamps.
- `sector_user`: `sector_id`, `user_id`, chave única composta.

`SectorStatus`:

```php
enum SectorStatus: string
{
    case Ativo = 'ativo';
    case ForaDoMenu = 'fora_do_menu';
    case Desativado = 'desativado';

    public function label(): string
    {
        return match ($this) {
            self::Ativo => 'Ativo',
            self::ForaDoMenu => 'Fora do menu',
            self::Desativado => 'Desativado',
        };
    }

    public function appearsInMenu(): bool
    {
        return $this === self::Ativo;
    }

    public function receivesTickets(): bool
    {
        return $this !== self::Desativado;
    }
}
```

`Sector` com cast de `status` e escopos `scopeVisibleInMenu` (status `ativo`) e
`scopeReceivingTickets` (`whereIn('status', ['ativo', 'fora_do_menu'])`).

`User`: cast de `role` e `active`, `sectors()`, `isAdmin()`, `canSeeAllSectors()`.
`UserFactory` passa a declarar `role => UserRole::ATENDENTE`, `active => true` e
`email_verified_at => now()`.

- [ ] **Step 4: Gates e login de inativo**

`AppServiceProvider::boot()`:

```php
Gate::define('admin', fn (User $user) => $user->role === UserRole::Admin);
Gate::define('supervisor', fn (User $user) => $user->canSeeAllSectors());
```

`FortifyServiceProvider::boot()`: `Fortify::authenticateUsing()` que só devolve o usuário se
`Hash::check` passar **e** `$user->active` for `true`.

- [ ] **Step 5: Seeders e comando**

`SectorSeeder` cria/atualiza os cinco setores com `menu_order` 1..5 e `status` `ativo`.
`AdminUserSeeder` lê `ADMIN_EMAIL`/`ADMIN_PASSWORD`, não faz nada se ausentes, cria/atualiza com
papel `admin` e `email_verified_at = now()`. `DatabaseSeeder` chama os dois.

`app:create-user` cria usuário com `--role`, `email_verified_at = now()` e senha interativa.

- [ ] **Step 6: Rodar, semear e commitar**

`php artisan migrate`, `php artisan test --filter=SectorAndRoleTest`, `php artisan db:seed`.

```powershell
git add -A
git commit -m "feat: setores com tres estados, papeis e usuarios internos"
```

---

## Task 5: Configuração no banco + tela Configurações > WhatsApp

**Files:**
- Create: `database/migrations/*_create_settings_table.php`, `app/Models/Setting.php`,
  `app/Settings/WahaSettings.php`, `app/Livewire/Settings/WhatsApp.php`,
  `resources/views/livewire/settings/whatsapp.blade.php`,
  `tests/Feature/Settings/SettingStoreTest.php`, `tests/Feature/Livewire/WhatsAppSettingsTest.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: gates da Task 4.
- Produces:
  - `Setting::put(string $key, mixed $value, string $type = 'string'): void`
  - `Setting::get(string $key, mixed $default = null): mixed`
  - Tipos: `string`, `bool`, `int`, `json`, `secret` (secret cifrado com `Crypt`).
  - `WahaSettings` com `baseUrl(): string`, `apiKey(): string`, `webhookSecret(): string`,
    `sessionName(): string`, `isConfigured(): bool`, `save(...)`.
  - Rota `GET /configuracoes/whatsapp` (nome `configuracoes.whatsapp`), só admin.

- [ ] **Step 1: Testes do repositório de configuração**

`tests/Feature/Settings/SettingStoreTest.php`:

```php
<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Settings\WahaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_e_le_tipos_simples(): void
    {
        Setting::put('app.retention_days', 90, 'int');
        Setting::put('app.auto_close', true, 'bool');
        Setting::put('app.hours', ['mon' => '08:00-18:00'], 'json');

        $this->assertSame(90, Setting::get('app.retention_days'));
        $this->assertTrue(Setting::get('app.auto_close'));
        $this->assertSame(['mon' => '08:00-18:00'], Setting::get('app.hours'));
    }

    public function test_segredo_nao_fica_em_texto_puro_no_banco(): void
    {
        Setting::put('waha.api_key', 'chave-super-secreta', 'secret');

        $raw = DB::table('settings')->where('key', 'waha.api_key')->value('value');

        $this->assertNotSame('chave-super-secreta', $raw);
        $this->assertSame('chave-super-secreta', Setting::get('waha.api_key'));
    }

    public function test_waha_settings_le_as_chaves_com_prefixo(): void
    {
        Setting::put('waha.base_url', 'http://waha:3000');
        Setting::put('waha.session_name', 'main');
        Setting::put('waha.api_key', 'chave', 'secret');
        Setting::put('waha.webhook_secret', 'segredo', 'secret');

        $this->assertSame('http://waha:3000', WahaSettings::baseUrl());
        $this->assertSame('main', WahaSettings::sessionName());
        $this->assertSame('chave', WahaSettings::apiKey());
        $this->assertSame('segredo', WahaSettings::webhookSecret());
        $this->assertTrue(WahaSettings::isConfigured());
    }
}
```

- [ ] **Step 2: Teste da tela**

`tests/Feature/Livewire/WhatsAppSettingsTest.php`:

```php
<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Settings\WhatsApp;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WhatsAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_nao_entra_na_tela(): void
    {
        $user = User::factory()->create(['role' => UserRole::SUPERVISOR]);

        $this->actingAs($user)->get('/configuracoes/whatsapp')->assertForbidden();
    }

    public function test_admin_salva_e_testa_a_conexao(): void
    {
        Http::fake(['*/api/sessions*' => Http::response([], 200)]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($admin)->test(WhatsApp::class)
            ->set('baseUrl', 'http://waha:3000')
            ->set('sessionName', 'main')
            ->set('apiKey', 'chave')
            ->set('webhookSecret', 'segredo')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('http://waha:3000', Setting::get('waha.base_url'));
        $this->assertSame('chave', Setting::get('waha.api_key'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/sessions'));
    }

    public function test_url_invalida_nao_e_salva(): void
    {
        Http::fake();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($admin)->test(WhatsApp::class)
            ->set('baseUrl', 'nao-e-url')
            ->set('sessionName', 'main')
            ->set('apiKey', 'chave')
            ->set('webhookSecret', 'segredo')
            ->call('save')
            ->assertHasErrors('baseUrl');

        $this->assertNull(Setting::get('waha.base_url'));
        Http::assertNothingSent();
    }

    public function test_waha_fora_do_ar_nao_grava(): void
    {
        Http::fake(['*/api/sessions*' => Http::response('erro', 500)]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($admin)->test(WhatsApp::class)
            ->set('baseUrl', 'http://waha:3000')
            ->set('sessionName', 'main')
            ->set('apiKey', 'chave')
            ->set('webhookSecret', 'segredo')
            ->call('save')
            ->assertSet('errorMessage', fn ($message) => str_contains($message, 'WAHA'));

        $this->assertNull(Setting::get('waha.api_key'));
    }
}
```

- [ ] **Step 3: Rodar e ver falhar**

`php artisan test --filter="SettingStoreTest|WhatsAppSettingsTest"`.

- [ ] **Step 4: Implementar**

Migração `settings`: `key` string único, `value` text nullable, `type` string padrão `string`,
timestamps.

`Setting`:

```php
protected $fillable = ['key', 'value', 'type'];

public static function put(string $key, mixed $value, string $type = 'string'): void
{
    $stored = $type === 'secret' ? Crypt::encryptString((string) $value) : json_encode($value);

    static::updateOrCreate(['key' => $key], ['value' => $stored, 'type' => $type]);
}

public static function get(string $key, mixed $default = null): mixed
{
    $setting = static::where('key', $key)->first();

    if ($setting === null) {
        return $default;
    }

    return match ($setting->type) {
        'secret' => Crypt::decryptString($setting->value),
        default => json_decode($setting->value, true),
    };
}
```

`WahaSettings` é uma classe com métodos estáticos finos sobre `Setting`, sem cache (a tela lê
poucas chaves; cache só quando houver necessidade medida).

A checagem de conexão em `save()` usa `Http` direto, e não `WahaClient`, porque o cliente da Task 7
depende de um canal já configurado — invertê-los criaria dependência circular. São quatro linhas de
`Http::withHeaders(...)->timeout(5)->get(...)`.

Tela `App\Livewire\Settings\WhatsApp` (`make:livewire Settings/WhatsApp --class`): propriedades
`$baseUrl`, `$sessionName`, `$apiKey`, `$webhookSecret`, `$errorMessage`, `$saved`. `mount()` carrega
de `WahaSettings` (nunca imprime segredo; campo de segredo fica vazio e só é sobrescrito se
preenchido). `save()` valida `url`, `required|max:255`; faz `GET {baseUrl}/api/sessions` com
`X-Api-Key`; se falhar, `$errorMessage = 'Não foi possível falar com o WAHA: ...'` e **não grava**;
se passar, grava as quatro chaves. `apiKey` e `webhookSecret` são `required` no primeiro
preenchimento e `nullable` depois — vazio significa "manter o segredo atual". Rota com `auth` +
`can:admin`.

A tela tem uma nota fixa: "Estes valores precisam bater com as variáveis do container WAHA no
Coolify. Veja `docs/superpowers/specs/waha-config.md`." Campo de API key é `type="password"`.

- [ ] **Step 5: Rodar e commitar**

`php artisan test --filter="SettingStoreTest|WhatsAppSettingsTest"`.

```powershell
git add -A
git commit -m "feat: configuracao no banco e tela de conexao com o WAHA"
```

---

## Task 6: Modelo de canal

**Files:**
- Create: `app/Enums/ChannelStatus.php`, `app/Models/Channel.php`,
  `database/migrations/*_create_channels_table.php`, `database/factories/ChannelFactory.php`,
  `tests/Feature/Domain/ChannelTest.php`

**Interfaces:**
- Consumes: nada além do app.
- Produces: `Channel` com `name`, `waha_session` único, `phone`, `status` (cast), `last_event_at`,
  `backfill_days` (padrão 0 = desligado), `backfill_last_run_at`, `active`; `isConnected(): bool` e
  `normalizeChatId(string $phone): string`.
  `ChannelStatus::STOPPED|STARTING|SCAN_QR_CODE|PASSKEY_REQUIRED|WORKING|FAILED` com `label()`,
  `needsQr(): bool`, `isProblem(): bool`.

- [ ] **Step 1: Teste**

`tests/Feature/Domain/ChannelTest.php`:

```php
<?php

namespace Tests\Feature\Domain;

use App\Enums\ChannelStatus;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_canal_nasce_parado_e_com_backfill_desligado(): void
    {
        $channel = Channel::factory()->create();

        $this->assertSame(ChannelStatus::STOPPED, $channel->status);
        $this->assertSame(0, $channel->backfill_days);
        $this->assertFalse($channel->isConnected());
    }

    public function test_importacao_de_historico_e_opt_in_com_dias_escolhidos(): void
    {
        $channel = Channel::factory()->create(['backfill_days' => 5]);

        $this->assertSame(5, $channel->fresh()->backfill_days);
    }

    public function test_normaliza_telefone_para_chat_id(): void
    {
        $this->assertSame(
            '5516998124477@c.us',
            Channel::factory()->make()->normalizeChatId('+55 (16) 99812-4477')
        );
    }

    /** @return array<string, array{ChannelStatus, string}> */
    public static function rotulos(): array
    {
        return [
            'conectado' => [ChannelStatus::WORKING, 'Conectado'],
            'qr' => [ChannelStatus::SCAN_QR_CODE, 'Aguardando QR'],
            'falhou' => [ChannelStatus::FAILED, 'Falhou'],
        ];
    }

    #[DataProvider('rotulos')]
    public function test_cada_status_tem_rotulo_em_portugues(ChannelStatus $status, string $label): void
    {
        $this->assertSame($label, $status->label());
    }

    public function test_status_de_qr_pede_leitura_e_falha_merece_alerta(): void
    {
        $this->assertTrue(ChannelStatus::SCAN_QR_CODE->needsQr());
        $this->assertFalse(ChannelStatus::WORKING->needsQr());
        $this->assertTrue(ChannelStatus::FAILED->isProblem());
        $this->assertFalse(ChannelStatus::STARTING->isProblem());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

`php artisan test --filter=ChannelTest`.

- [ ] **Step 3: Implementar**

Migração com as colunas acima (`waha_session` único, `status` string padrão `STOPPED`,
`backfill_days` unsigned small int padrão **0**, datas nullable, `active` padrão true).

Enum string-backed com `label()`: STOPPED "Parado", STARTING "Iniciando", SCAN_QR_CODE "Aguardando
QR", PASSKEY_REQUIRED "Senha do aparelho", WORKING "Conectado", FAILED "Falhou". `needsQr()` cobre
`SCAN_QR_CODE`; `isProblem()` cobre `FAILED`.

`normalizeChatId` remove tudo que não é dígito e concatena `@c.us`.

- [ ] **Step 4: Rodar e commitar**

```powershell
git add -A
git commit -m "feat: modelo de canal de WhatsApp"
```

---

## Task 7: Cliente WAHA

**Files:**
- Create: `app/Services/Waha/WahaClient.php`, `app/Services/Waha/WahaRequestException.php`,
  `tests/Feature/Services/WahaClientTest.php`

**Interfaces:**
- Consumes: `Channel` (Task 6), `WahaSettings` (Task 5).
- Produces: `new WahaClient(Channel $channel)` com `session(): array`, `start(): void`,
  `stop(): void`, `restart(): void`, `logout(): void`, `qrDataUri(): ?string`,
  `requestPairingCode(string $phoneNumber): string`,
  `sendText(string $chatId, string $text): array`, `sendSeen(string $chatId): void`,
  `startTyping(string $chatId): void`, `stopTyping(string $chatId): void`,
  `setPresence(string $presence): void`.
  `WahaRequestException` com `statusCode` público e mensagem legível.

- [ ] **Step 1: Testes**

`tests/Feature/Services/WahaClientTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Models\Channel;
use App\Models\Setting;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WahaClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('waha.base_url', 'http://waha.test:3000');
        Setting::put('waha.api_key', 'test-key', 'secret');
        Setting::put('waha.session_name', 'main');
        Setting::put('waha.webhook_secret', 'segredo', 'secret');
    }

    public function test_envia_texto_com_a_sessao_e_a_api_key(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['id' => 'false_5516@c.us_ABC'], 201)]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        $response = (new WahaClient($channel))->sendText('5516998124477@c.us', 'Olá, ação e coração 🚀');

        $this->assertSame('false_5516@c.us_ABC', $response['id']);

        Http::assertSent(fn ($request) => $request->url() === 'http://waha.test:3000/api/sendText'
            && $request['session'] === 'main'
            && $request['chatId'] === '5516998124477@c.us'
            && $request['text'] === 'Olá, ação e coração 🚀'
            && $request->hasHeader('X-Api-Key', 'test-key'));
    }

    public function test_erro_do_waha_vira_excecao_legivel(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['message' => 'Session not found'], 500)]);
        $channel = Channel::factory()->create();

        $this->expectException(WahaRequestException::class);

        (new WahaClient($channel))->sendText('5516998124477@c.us', 'oi');
    }

    public function test_inicia_sessao_existente_quando_criar_responde_422(): void
    {
        Http::fake([
            '*/api/sessions' => Http::response(['message' => 'already exists'], 422),
            '*/api/sessions/main/start' => Http::response(['status' => 'STARTING'], 200),
        ]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        (new WahaClient($channel))->start();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'http://waha.test:3000/api/sessions/main/start');
    }

    public function test_qr_volta_como_data_uri_de_imagem(): void
    {
        Http::fake(['*/auth/qr*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
        $channel = Channel::factory()->create();

        $this->assertStringStartsWith(
            'data:image/png;base64,',
            (new WahaClient($channel))->qrDataUri()
        );
    }

    public function test_pede_codigo_de_pareamento_so_com_digitos(): void
    {
        Http::fake(['*/auth/request-code' => Http::response(['code' => 'ABCD-ABCD'], 200)]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        $code = (new WahaClient($channel))->requestPairingCode('+55 (16) 99812-4477');

        $this->assertSame('ABCD-ABCD', $code);
        Http::assertSent(fn ($request) => $request['phoneNumber'] === '5516998124477'
            && $request->url() === 'http://waha.test:3000/api/main/auth/request-code');
    }

    public function test_presenca_offline_e_enviada_no_corpo(): void
    {
        Http::fake(['*/presence' => Http::response([], 200)]);
        $channel = Channel::factory()->create();

        (new WahaClient($channel))->setPresence('offline');

        Http::assertSent(fn ($request) => $request['presence'] === 'offline'
            && $request->url() === 'http://waha.test:3000/api/main/presence');
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

`php artisan test --filter=WahaClientTest`.

- [ ] **Step 3: Implementar**

`WahaClient` monta `Http::baseUrl(WahaSettings::baseUrl())->withHeaders(['X-Api-Key' =>
WahaSettings::apiKey()])->acceptJson()->timeout(15)`. Cada método chama o endpoint e, se
`->failed()`, lança `WahaRequestException` com status e um trecho do corpo (máximo 200 caracteres).

`start()` faz `POST /api/sessions` com `{name, start: true}`; se responder 422, faz
`POST /api/sessions/{session}/start`. `stop()` e `logout()` tratam 404 como sucesso.
`qrDataUri()` devolve `data:image/png;base64,` + base64 do corpo, ou `null` se o status não for
imagem. `requestPairingCode()` envia só os dígitos do telefone para
`POST /api/{session}/auth/request-code` e devolve `code`; 422 vira `WahaRequestException` com
mensagem "Inicie a sessão antes de pedir o código".

- [ ] **Step 4: Rodar e commitar**

```powershell
git add -A
git commit -m "feat: cliente HTTP do WAHA lendo configuracao do banco"
```

---

## Task 8: Webhook com HMAC, log bruto e processamento em fila

**Files:**
- Create: `app/Models/WahaEvent.php`,
  `app/Http/Controllers/Webhooks/WahaWebhookController.php`, `app/Jobs/HandleWahaEvent.php`,
  `database/migrations/*_create_waha_events_table.php`,
  `tests/Feature/Webhooks/WahaWebhookTest.php`,
  `tests/Feature/Jobs/HandleWahaEventTest.php`
- Modify: `routes/web.php`, `bootstrap/app.php`

**Interfaces:**
- Consumes: `Channel`/`ChannelStatus` (Task 6), `WahaSettings` (Task 5).
- Produces: `POST /webhooks/waha` → 200 `{"ok":true}`; 401 sem HMAC válido; evento gravado em
  `waha_events` com `waha_event_id` único; `HandleWahaEvent::__construct(public int $wahaEventId)`
  que aplica `session.status` ao canal.

- [ ] **Step 1: Testes do webhook**

`tests/Feature/Webhooks/WahaWebhookTest.php`:

```php
<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\HandleWahaEvent;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\WahaEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WahaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('waha.webhook_secret', 'segredo', 'secret');
        Queue::fake();
    }

    /** @param array<string, mixed> $overrides */
    private function sendEvent(array $overrides = [], ?string $secret = 'segredo'): \Illuminate\Testing\TestResponse
    {
        $body = array_merge([
            'id' => 'evt_01',
            'event' => 'session.status',
            'session' => 'main',
            'engine' => 'GOWS',
            'payload' => ['status' => 'WORKING'],
        ], $overrides);

        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null) {
            $headers['HTTP_X_WEBHOOK_HMAC'] = hash_hmac('sha512', $json, $secret);
            $headers['HTTP_X_WEBHOOK_HMAC_ALGORITHM'] = 'sha512';
        }

        return $this->call('POST', '/webhooks/waha', [], [], [], $headers, $json);
    }

    public function test_sem_hmac_nao_grava_nada(): void
    {
        $this->sendEvent(secret: null)->assertUnauthorized();

        $this->assertSame(0, WahaEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_hmac_de_outro_corpo_nao_autoriza(): void
    {
        $json = '{"id":"evt_01","event":"session.status","session":"main"}';

        $this->call('POST', '/webhooks/waha', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', 'outro-corpo', 'segredo'),
        ], $json)->assertUnauthorized();

        $this->assertSame(0, WahaEvent::count());
    }

    public function test_grava_o_evento_enfileira_e_responde_rapido(): void
    {
        Channel::factory()->create(['waha_session' => 'main']);

        $this->sendEvent()->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame('session.status', WahaEvent::first()->event);
        Queue::assertPushed(HandleWahaEvent::class);
    }

    public function test_evento_repetido_nao_duplica(): void
    {
        Channel::factory()->create(['waha_session' => 'main']);

        $this->sendEvent()->assertOk();
        $this->sendEvent()->assertOk();

        $this->assertSame(1, WahaEvent::count());
    }

    public function test_sessao_desconhecida_nao_quebra(): void
    {
        $this->sendEvent(['session' => 'nao-existe'])->assertOk();

        $this->assertNull(WahaEvent::first()->channel_id);
    }

    public function test_texto_com_acento_e_emoji_fica_intacto(): void
    {
        $this->sendEvent([
            'event' => 'message',
            'payload' => ['body' => 'Orçamento da chapa 🚀 ação'],
        ])->assertOk();

        $this->assertSame(
            'Orçamento da chapa 🚀 ação',
            WahaEvent::first()->payload['payload']['body']
        );
    }
}
```

- [ ] **Step 2: Teste do job**

`tests/Feature/Jobs/HandleWahaEventTest.php`:

```php
<?php

namespace Tests\Feature\Jobs;

use App\Enums\ChannelStatus;
use App\Jobs\HandleWahaEvent;
use App\Models\Channel;
use App\Models\WahaEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleWahaEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_da_sessao_atualiza_o_canal(): void
    {
        $channel = Channel::factory()->create([
            'waha_session' => 'main',
            'status' => ChannelStatus::STARTING,
        ]);
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_01',
            'channel_id' => $channel->id,
            'event' => 'session.status',
            'session' => 'main',
            'payload' => ['payload' => ['status' => 'WORKING']],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($event->id))->handle();

        $this->assertSame(ChannelStatus::WORKING, $channel->fresh()->status);
        $this->assertNotNull($channel->fresh()->last_event_at);
    }

    public function test_status_desconhecido_e_evento_sem_canal_sao_ignorados(): void
    {
        $channel = Channel::factory()->create([
            'waha_session' => 'main',
            'status' => ChannelStatus::WORKING,
        ]);
        $unknown = WahaEvent::create([
            'waha_event_id' => 'evt_02',
            'channel_id' => $channel->id,
            'event' => 'session.status',
            'session' => 'main',
            'payload' => ['payload' => ['status' => 'ALGO_NOVO']],
            'received_at' => now(),
        ]);
        $orphan = WahaEvent::create([
            'waha_event_id' => 'evt_03',
            'channel_id' => null,
            'event' => 'session.status',
            'session' => 'sumiu',
            'payload' => [],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($unknown->id))->handle();
        (new HandleWahaEvent($orphan->id))->handle();

        $this->assertSame(ChannelStatus::WORKING, $channel->fresh()->status);
    }
}
```

- [ ] **Step 3: Rodar e ver falhar**

`php artisan test --filter="WahaWebhookTest|HandleWahaEventTest"`.

- [ ] **Step 4: Implementar**

Migração `waha_events`: `waha_event_id` string único, `channel_id` nullable com FK, `event`
indexado, `session` nullable, `payload` jsonb, `received_at`, timestamps.

Controller `__invoke(Request $request)`:

```php
$secret = WahaSettings::webhookSecret();
$raw = $request->getContent();
$signature = (string) $request->header('X-Webhook-Hmac', '');

if ($secret === '' || ! hash_equals(hash_hmac('sha512', $raw, $secret), $signature)) {
    abort(401);
}
```

Depois resolve o canal por `waha_session = $request->input('session')`, cria o `WahaEvent` com
`firstOrCreate` sobre `waha_event_id` (retry não duplica), dispara `HandleWahaEvent::dispatch()` só
quando acabou de criar, e devolve `['ok' => true]`.

Rota em `routes/web.php` fora do grupo `auth`; CSRF isento em `bootstrap/app.php`
(`validateCsrfTokens(except: ['webhooks/waha'])`).

Job: se `event === 'session.status'`, `ChannelStatus::tryFrom(data_get($payload, 'payload.status',
''))`; se válido e houver canal, atualiza `status` e `last_event_at`.

- [ ] **Step 5: Rodar e commitar**

```powershell
git add -A
git commit -m "feat: webhook do WAHA com HMAC, log bruto e processamento em fila"
```

---

## Task 9: Tela Configurações > Canais

**Files:**
- Create: `app/Livewire/Settings/Channels.php`,
  `resources/views/livewire/settings/channels.blade.php`,
  `tests/Feature/Livewire/ChannelsScreenTest.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `Channel` (Task 6), `WahaClient` (Task 7), `WahaEvent` (Task 8), gate `admin`
  (Task 4), `WahaSettings` (Task 5).
- Produces: rota `GET /configuracoes/canais` (`configuracoes.canais`), só admin; componente com
  `connect()`, `disconnect()`, `refreshStatus()`, `requestPairingCode()`, `$channel`, `$qr`,
  `$lastEvents`, `$errorMessage`, `$authMethod` (`qr` padrão, `code`), `$pairingPhone`,
  `$pairingCode`, `$backfill` (padrão `false`), `$backfillDays` (padrão 5).

- [ ] **Step 1: Testes**

`tests/Feature/Livewire/ChannelsScreenTest.php`:

```php
<?php

namespace Tests\Feature\Livewire;

use App\Enums\ChannelStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\Channels;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChannelsScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('waha.base_url', 'http://waha.test:3000');
        Setting::put('waha.api_key', 'test-key', 'secret');
        Setting::put('waha.session_name', 'main');
        Setting::put('waha.webhook_secret', 'segredo', 'secret');
    }

    /** @return array<string, array{UserRole}> */
    public static function papeisSemAcesso(): array
    {
        return [
            'supervisor' => [UserRole::SUPERVISOR],
            'atendente' => [UserRole::ATENDENTE],
        ];
    }

    #[DataProvider('papeisSemAcesso')]
    public function test_supervisor_e_atendente_nao_entram(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get('/configuracoes/canais')->assertForbidden();
    }

    public function test_admin_ve_o_canal_e_o_status(): void
    {
        Channel::factory()->create([
            'name' => 'WhatsApp principal',
            'status' => ChannelStatus::WORKING,
        ]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->actingAs($admin)->get('/configuracoes/canais')
            ->assertOk()
            ->assertSee('WhatsApp principal')
            ->assertSee('Conectado');
    }

    public function test_conectar_chama_o_waha_e_marca_iniciando(): void
    {
        Http::fake(['*/api/sessions*' => Http::response(['status' => 'STARTING'], 200)]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs($admin)->test(Channels::class)->call('connect');

        $this->assertSame(ChannelStatus::STARTING, $channel->fresh()->status);
    }

    public function test_waha_fora_do_ar_mostra_erro_e_nao_quebra(): void
    {
        Http::fake(['*/api/sessions*' => Http::response(['message' => 'fora do ar'], 500)]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs($admin)->test(Channels::class)
            ->call('connect')
            ->assertSet('errorMessage', fn ($message) => str_contains($message, 'WAHA'));
    }

    public function test_qr_aparece_quando_a_sessao_pede_leitura(): void
    {
        Http::fake([
            '*/api/sessions/main' => Http::response(['status' => 'SCAN_QR_CODE'], 200),
            '*/auth/qr*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
        ]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::SCAN_QR_CODE]);

        Livewire::actingAs($admin)->test(Channels::class)
            ->call('refreshStatus')
            ->assertSet('qr', fn ($qr) => str_starts_with($qr, 'data:image/png;base64,'));
    }

    public function test_codigo_de_pareamento_aparece_sem_esconder_o_qr(): void
    {
        Http::fake([
            '*/auth/request-code' => Http::response(['code' => 'ABCD-ABCD'], 200),
            '*/auth/qr*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
        ]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::SCAN_QR_CODE]);

        Livewire::actingAs($admin)->test(Channels::class)
            ->set('pairingPhone', '+55 (16) 99812-4477')
            ->call('requestPairingCode')
            ->assertSet('pairingCode', 'ABCD-ABCD')
            ->assertSet('qr', fn ($qr) => str_starts_with($qr, 'data:image/png;base64,'));

        Http::assertSent(fn ($request) => $request['phoneNumber'] === '5516998124477');
    }

    public function test_importacao_de_historico_vem_desligada_e_e_gravada_na_conexao(): void
    {
        Http::fake(['*/api/sessions*' => Http::response(['status' => 'STARTING'], 200)]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs($admin)->test(Channels::class)
            ->assertSet('backfill', false)
            ->assertSet('backfillDays', 5)
            ->set('backfill', true)
            ->call('connect');

        $this->assertSame(5, $channel->fresh()->backfill_days);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

`php artisan test --filter=ChannelsScreenTest`.

- [ ] **Step 3: Implementar**

`make:livewire Settings/Channels --class`.

`mount()` faz `Channel::firstOrCreate(['waha_session' => WahaSettings::sessionName()], ['name' =>
'WhatsApp principal'])` e carrega `$lastEvents` (10 mais recentes).

`connect()` valida `backfillDays` (`integer|min:1|max:365`) quando `$backfill` for true, grava
`backfill_days` no canal (`0` quando desmarcado), chama `start()`, passa o canal para `STARTING` e
limpa `$errorMessage`. `disconnect()` chama `stop()` e marca `STOPPED`. `refreshStatus()` chama
`session()`, mapeia o `status` para `ChannelStatus`, atualiza o canal e recarrega o QR quando o
status pede leitura. `requestPairingCode()` valida o telefone (12 ou 13 dígitos), chama
`WahaClient->requestPairingCode()`, guarda `$pairingCode` e mantém o QR carregado — o código é
alternativa, não substituição. `WahaRequestException` vira "Não foi possível falar com o WAHA:
{mensagem}" em `$errorMessage`.

Blade: cabeçalho "Canais" / "Conexão do WhatsApp"; card com nome, badge de status (ponto + texto),
telefone, último evento; botões "Conectar", "Desconectar"; quando `SCAN_QR_CODE`, um bloco
"Conectar o número" com duas abas — **QR** (imagem + "o QR é renovado automaticamente") e
**Código de pareamento** (campo telefone, botão "Gerar código", código em `font-mono` grande e a
instrução "WhatsApp → Aparelhos conectados → Conectar com número de telefone"); o QR continua
visível abaixo da aba de código. Antes de conectar, checkbox **"Importar histórico"** desmarcado e
campo de dias (`5`), com o aviso "A importação roda depois da conexão e não bloqueia o
atendimento". "Últimos eventos" com evento, sessão e hora em `font-mono`; aviso em `text-warn`.
`wire:poll.5s="refreshStatus"`.

Rota: `Route::get('/configuracoes/canais', Channels::class)->middleware(['auth',
'can:admin'])->name('configuracoes.canais');`

- [ ] **Step 4: Rodar e commitar**

```powershell
git add -A
git commit -m "feat: tela de canais com status, QR e eventos recentes"
```

---

## Task 10: Envio de texto de teste

**Files:**
- Modify: `app/Livewire/Settings/Channels.php`,
  `resources/views/livewire/settings/channels.blade.php`,
  `tests/Feature/Livewire/ChannelsScreenTest.php`

**Interfaces:**
- Consumes: `WahaClient::sendText()` e `setPresence()` (Task 7).
- Produces: `$testPhone`, `$testText`, `$sentMessage`, ação `sendTest()`.

- [ ] **Step 1: Testes (acrescentar ao arquivo da Task 9)**

```php
public function test_envia_texto_de_teste_normalizando_o_telefone(): void
{
    Http::fake([
        '*/api/sendText' => Http::response(['id' => 'ABC123'], 201),
        '*/presence' => Http::response([], 200),
    ]);
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::WORKING]);

    Livewire::actingAs($admin)->test(Channels::class)
        ->set('testPhone', '+55 (16) 99812-4477')
        ->set('testText', 'Teste do painel')
        ->call('sendTest')
        ->assertSet('sentMessage', 'Enviado: ABC123');

    Http::assertSent(fn ($request) => $request['chatId'] === '5516998124477@c.us'
        && $request['text'] === 'Teste do painel');
    Http::assertSent(fn ($request) => $request['presence'] === 'offline');
}

public function test_telefone_invalido_nao_chama_o_waha(): void
{
    Http::fake();
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);
    Channel::factory()->create(['waha_session' => 'main']);

    Livewire::actingAs($admin)->test(Channels::class)
        ->set('testPhone', '123')
        ->set('testText', 'oi')
        ->call('sendTest')
        ->assertHasErrors('testPhone');

    Http::assertNothingSent();
}
```

- [ ] **Step 2: Rodar e ver falhar**

`php artisan test --filter=ChannelsScreenTest`.

- [ ] **Step 3: Implementar**

Validação: `testPhone` obrigatório, dígitos em quantidade 12 ou 13; `testText` obrigatório, máximo
4096. `sendTest()` chama `sendText($channel->normalizeChatId($phone), $text)`, depois
`setPresence('offline')` (para o celular voltar a receber push) e define
`$sentMessage = 'Enviado: '.$response['id']`. `WahaRequestException` vira `$errorMessage`.

O formulário **não grava mensagem no banco** — é diagnóstico. Mensagens entram no Plano 1.

- [ ] **Step 4: Rodar e commitar**

```powershell
git add -A
git commit -m "feat: envio de texto de teste pela tela de canais"
```

---

## Task 11: Coolify `main`/`beta`, deploy e prova real

**Files:**
- Create: `docs/deploy.md`
- Modify: `README.md`, `.env.example`

**Interfaces:**
- Consumes: tudo acima.
- Produces: dois ambientes no Coolify funcionando, documentação de operação e checklist de prova.

- [ ] **Step 1: Documentar a topologia**

`docs/deploy.md` deve conter, em português:

1. **Projetos Coolify**: `dimetal-main` e `dimetal-beta`, cada um com `app`, `queue-worker`,
   `scheduler`, `postgres:18`, `redis:8`, `waha:gows`.
2. **Domínio**: só o `app` recebe domínio HTTPS. `postgres`, `redis` e `waha` sem domínio, na rede
   interna. O app acessa o WAHA por `http://<service-name>:3000`.
3. **Variáveis do app**: só `APP_KEY`, `APP_ENV`, `APP_URL`, `DB_*`, `REDIS_*`. Nada de `WAHA_*`
   no app — isso mora no banco.
4. **Variáveis do WAHA**: copiar a matriz da seção 2 de `docs/superpowers/specs/waha-config.md`,
   trocando `main`/`beta`, API key, HMAC e hostname do app.
5. **Volumes persistentes**: `waha-sessions` em `/app/.sessions` (sem isso, redeploy pede QR) e
   `waha-media` em `/app/.media`.
6. **Deploy**: push em `main` → produção; push em `beta` → beta. `main` só via PR.
7. **Primeiro deploy**: `php artisan migrate --force`, `php artisan db:seed --force`, criar admin
   com `php artisan app:create-user`, abrir Configurações → WhatsApp, preencher URL interna, API
   key e segredo HMAC, salvar (a tela testa), ir em Canais, marcar ou não "Importar histórico",
   conectar por QR **ou** por código de pareamento (o QR fica como alternativa).
8. **Atualização**: `git push` → Coolify reconstrói; o volume preserva a sessão. Migração roda no
   deploy.
9. **Diagnóstico**: logs do container `waha`, tela Canais, `GET /api/sessions` pelo app.

- [ ] **Step 2: README**

README com: o que é, stack, requisitos locais (Docker, PHP 8.5, Composer, Node 24), subir containers,
`php artisan migrate --seed`, `npm run dev`, `php artisan serve`, `php artisan queue:work`, e link
para `docs/deploy.md` e `docs/superpowers/specs/waha-config.md`.

- [ ] **Step 3: Prova real no beta**

1. Coolify sobe o projeto `beta` com o número de teste.
2. Configurações → WhatsApp: salvar URL/chave/HMAC; a tela aceita.
3. Canais → Conectar → QR aparece → escanear com o chip de teste.
4. Repetir a autenticação em uma segunda sessão de teste usando **código de pareamento**: gerar o
   código na tela e digitá-lo no celular em "Conectar com número de telefone". O QR precisa
   continuar visível durante o processo.
5. Status vira "Conectado".
6. Confirmar que "Importar histórico" estava desmarcado e que `channels.backfill_days` ficou `0`.
7. Mandar mensagem do celular para o número: evento `message` aparece em "Últimos eventos".
8. Enviar texto pelo formulário: chega no celular; depois de ~25s o contato vê "visto por último".
9. Reiniciar o container WAHA no Coolify: status volta a "Conectado" **sem pedir QR**.
10. Repetir 1–9 no `main` com o número real.

- [ ] **Step 4: Commit e PR**

```powershell
git add -A
git commit -m "docs: deploy Coolify main/beta e prova real do canal"
git push -u origin beta
gh pr create --base main --head beta --title "Fase 0: canal WhatsApp" --body "Entrega a Fase 0 conforme docs/superpowers/plans/2026-10-27-fase-0-canal-whatsapp.md"
```

---

## Critério de conclusão da Fase 0

- `main` protegida, CI verde com PHPUnit em PostgreSQL 18, Larastan 6 e Pint.
- Login interno funcionando, cadastro público inexistente.
- Cinco setores semeados nos três estados; papéis e gates cobertos por teste.
- WAHA configurado pelo banco, sem `WAHA_*` no `.env` do app.
- Webhook autenticado por HMAC, idempotente, com evento bruto gravado e status refletido no canal.
- Tela de canais mostrando status, QR e últimos eventos; conexão por QR **e** por código de
  pareamento; envio de teste chegando no celular.
- Backfill desligado por padrão, com a escolha de importar gravada no canal (execução no Plano 1).
- `main` e `beta` no Coolify, com sessões independentes e QR sobrevivendo a redeploy.
