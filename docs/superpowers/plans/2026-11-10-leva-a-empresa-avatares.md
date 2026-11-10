# Leva A (empresa, avatares, setores) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nome e logo da empresa pela UI, foto de usuário e de contato (vinda do WAHA) com um só editor/pipeline, e setores criáveis, renomeáveis e apagáveis.

**Architecture:** Um serviço de imagem (`AvatarImage`) recorta no centro, reduz e converte para WebP; um serviço de armazenamento (`AvatarStorage`) grava no disco de mídia (RustFS) com chave aleatória e entrega por rota do app com cache longo e URL versionada. O editor é um componente Blade + Alpine que carrega o Cropper.js v2 sob demanda e envia só o recorte pelo Livewire. A foto do contato é buscada por job na fila (nunca no webhook). Setores usam `SoftDeletes`, e o padrão é instalado uma vez por um passo agendado, com marca em `Setting`.

**Tech Stack:** Laravel 13, Livewire 4, Flux 2.21 (grátis), Tailwind 4, Alpine, Cropper.js 2.1.x (`cropperjs`, npm), `intervention/image-laravel` 1.x (GD), PostgreSQL 18, RustFS (S3).

**Spec:** `docs/superpowers/specs/2026-11-10-leva-a-empresa-avatares-design.md`

## Global Constraints

- Branch `feat/leva-a-empresa-avatares`; nunca commitar na `main`; PR só quando o dono pedir; nada de referências de pesquisa em commit.
- Commits em português com prefixo (`feat:`, `fix:`, `docs:`); um commit por tarefa; rodapé `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Código do app: buscar/ler por `codegraph_explore`; Blade e JS por `grep` no Bash (o índice não cobre Blade); `Edit` antes de script Python.
- Imagem: o navegador envia só o recorte (~512 px, nunca o original), limite **1 MB**; servidor revalida e converte para WebP: **256 px** para usuário e logo, **128 px** para contato; PNG/JPG/WebP apenas, sem SVG, tipo pelo conteúdo.
- Foto de contato: renova a cada **24 h**, expira em **90 dias** sem atualização (purga existente, que também zera as colunas do contato); tudo em job na fila `media`; sem `refresh=true`.
- Só `branding/*` (logo) é público; `avatars/*` exige sessão. Cabeçalho `Cache-Control: private, max-age=31536000, immutable` nas fotos e `public, max-age=31536000, immutable` no logo, mais `ETag`; a URL leva `?v=<hash de 10 caracteres>`.
- Setores: `SoftDeletes`; só apaga sem ticket aberto, sem workflow (rascunho ou última versão publicada) com nó `setor_id` apontando para ele e sem resposta rápida do setor; autorização `admin`.
- UI: tokens do `app.css`, contraste AA nos dois temas, sem rolagem horizontal em 1280, 1920, 2560 e 360 px; avatar e monograma com nome acessível; `motion-safe:`.
- Testes: `php artisan test --parallel` nos ciclos RED/GREEN; portão 7 pede suíte inteira com Redis em série, Pint, Larastan e PHPMD (ver CLAUDE.md). Disco de mídia nos testes: `Storage::fake(config('media.disk'))`.

## Review Focus

- Arquivo `.png` que na verdade é SVG, GIF animado ou texto; imagem enorme em pixels (bomba de descompressão): recusa clara, sem estourar memória (Task 2).
- URL de foto antiga (versão velha) ainda no HTML de outra aba depois de trocar a imagem: a rota ignora `v` e serve a imagem atual, nunca 404 (Task 3).
- Contato só com `@lid` (sem `wa_id`), ou WAHA devolvendo `null`, 404, 429, URL de host estranho ou arquivo grande demais: monograma, sem exceção na ingestão e sem laço de tentativas (Task 8).
- Dez mensagens do mesmo contato ao mesmo tempo: no máximo um job de foto (Task 8).
- Apagar setor e recriar outro com o mesmo nome; ticket antigo do setor apagado continua mostrando o nome; filtro do Histórico ainda lista o setor apagado (Task 9).

---

### Task 1: Nome da empresa pela UI

**Files:**
- Modify: `resources/views/components/app-logo.blade.php`, `resources/views/layouts/app/sidebar.blade.php`, `resources/views/layouts/auth/card.blade.php`, `resources/views/layouts/auth/split.blade.php`, `resources/views/partials/head.blade.php`, `resources/views/welcome.blade.php`
- Test: `tests/Feature/Settings/CompanyNameUiTest.php`

**Interfaces:**
- Consumes: `App\Settings\CompanySettings::name(): string` (já existe; cai no `APP_NAME` sem valor).
- Produces: nenhuma assinatura nova; as views passam a chamar `CompanySettings::name()`.

- [ ] **Step 1: Write the failing test** `CompanyNameUiTest` com três testes (`RefreshDatabase`): `test_titulo_e_meta_usam_o_nome_da_empresa` (grava `Setting::put('company.name', 'Metalúrgica Teste')`, GET `/login`, `assertSee('Metalúrgica Teste')` e `assertSee('Atendimento via WhatsApp da Metalúrgica Teste', false)`); `test_menu_lateral_mostra_o_nome_da_empresa` (usuário logado em `/inbox`, `assertSee('Metalúrgica Teste')`); `test_sem_nome_cadastrado_cai_no_app_name` (`config(['app.name' => 'AppFallback'])`, `/login` mostra `AppFallback`).
- [ ] **Step 2: Run** `php artisan test tests/Feature/Settings/CompanyNameUiTest.php` — Expected: FAIL (as views leem `config('app.name')`).
- [ ] **Step 3: Replace** toda ocorrência de `config('app.name', 'Laravel')`/`config('app.name')` listadas em Files por `\App\Settings\CompanySettings::name()` (achar com `grep -rn "app.name" resources/views`); o `<title>` e o `<meta description>` em `partials/head.blade.php` incluídos. O QR (`channel-card.blade.php`) não tem nome; nada a mudar ali.
- [ ] **Step 4: Run** o mesmo comando — Expected: PASS.
- [ ] **Step 5: Commit** `feat: nome da empresa pela UI no menu, login, título e meta`.

### Task 2: Serviço de imagem (`AvatarImage`) e pacote

**Files:**
- Modify: `composer.json`, `composer.lock` (via `composer require intervention/image-laravel`; autorizado pelo dono)
- Create: `app/Services/Media/AvatarImage.php`, `app/Services/Media/InvalidImage.php`
- Test: `tests/Unit/AvatarImageTest.php`

**Interfaces:**
- Produces: `AvatarImage::fromBinary(string $bytes, int $size, int $maxBytes = 1_048_576): string` (WebP quadrado `$size`×`$size`, recorte central); `AvatarImage::fromUpload(\Illuminate\Http\UploadedFile $file, int $size): string` (lê o conteúdo e chama `fromBinary`); constantes `SIZE_PERSON = 256`, `SIZE_LOGO = 256`, `SIZE_CONTACT = 128`; `InvalidImage extends \RuntimeException` com mensagem em português para o usuário.
- Regras: tipo pelo conteúdo (`getimagesizefromstring` → só `image/jpeg`, `image/png`, `image/webp`); recusa acima de `$maxBytes`; recusa lado maior que 4096 px (checar as dimensões **antes** de decodificar); GIF, SVG, texto e imagem corrompida → `InvalidImage`.

- [ ] **Step 1: Run** `composer require intervention/image-laravel` (no Git Bash: `php composer.phar`/`composer` do ambiente do dono; se não estiver no PATH, pedir ao dono que rode). Conferir que instala sem conflito com Laravel 13 e que `php -r 'var_dump(gd_info()["WebP Support"]);'` é `true` (a imagem base instala `gd` por `install-php-extensions`, que inclui WebP).
- [ ] **Step 2: Write the failing tests** (`tests/Unit/AvatarImageTest.php`, `Tests\TestCase`): `test_png_retangular_vira_webp_quadrado_do_tamanho_pedido` (gera PNG 600×300 com `imagecreatetruecolor`, `fromBinary(…, 256)`; `getimagesizefromstring` do resultado = 256×256 e `mime` `image/webp`); `test_gif_svg_e_texto_sao_recusados` (data providers: `GIF89a…`, `<svg…>`, `"oi"`; `expectException(InvalidImage::class)`); `test_arquivo_acima_do_limite_e_recusado` (bytes > 1 MB válidos); `test_imagem_gigante_em_pixels_e_recusada_antes_de_decodificar` (PNG 5000×5000 de 1 cor gerado com GD, que fica pequeno em bytes); `test_extensao_mente_mas_o_conteudo_vale` (JPEG com nome `.png` via `UploadedFile::fake()->createWithContent('a.png', $jpegBytes)` passa por `fromUpload`).
- [ ] **Step 3: Run** `php artisan test tests/Unit/AvatarImageTest.php` — Expected: FAIL (classe não existe).
- [ ] **Step 4: Implement** `AvatarImage` e `InvalidImage` conforme as regras. Usar `Intervention\Image\Laravel\Facades\Image::decode($bytes)->cover($size, $size)->encodeUsingMediaType('image/webp', quality: 82)` (conferir o nome exato do método de `encode` na versão instalada com `codegraph`/vendor ou Context7 `/intervention/image-laravel`) e `->toString()`. Capturar a exceção do decoder e relançar como `InvalidImage`.
- [ ] **Step 5: Run** o mesmo comando — Expected: PASS.
- [ ] **Step 6: Commit** `feat: serviço de imagem para avatares (recorte central, WebP)`.

### Task 3: Armazenamento, colunas e entrega

**Files:**
- Create: `database/migrations/2026_11_11_000001_add_avatar_columns.php`, `app/Services/Media/AvatarStorage.php`, `app/Models/Concerns/HasAvatar.php`, `app/Http/Controllers/AvatarController.php`, `app/Http/Controllers/LogoController.php`
- Modify: `app/Models/User.php`, `app/Models/Contact.php` (use `HasAvatar`; docblocks das colunas), `app/Settings/CompanySettings.php`, `routes/web.php`
- Test: `tests/Feature/Media/AvatarDeliveryTest.php`

**Interfaces:**
- Migration: `users` ganha `avatar_path` (string, null), `avatar_version` (string(10), null); `contacts` ganha os mesmos mais `avatar_checked_at` (timestamp, null).
- Produces: `AvatarStorage::store(string $prefix, string $webp): array{path: string, version: string}` (`$prefix` ∈ `avatars/users`, `avatars/contacts`, `branding`; chave `{prefix}/{ulid}.webp`; versão = `substr(sha1($webp), 0, 10)`); `AvatarStorage::delete(?string $path): void`; `AvatarStorage::response(string $path, bool $public): \Symfony\Component\HttpFoundation\StreamedResponse` (lê o stream do disco `Message::mediaDisk()`, `Content-Type: image/webp`, `X-Content-Type-Options: nosniff`, `ETag` = basename do objeto, 304 em `If-None-Match`, cabeçalhos de cache da Global Constraints; 404 se o objeto não existir).
- `HasAvatar` (trait; o modelo define `avatarPrefix(): string`): `avatarUrl(): ?string` (`route('avatar', [type, id, 'v' => avatar_version])`, `null` sem foto), `setAvatar(string $webp): void` (grava novo objeto, atualiza colunas, apaga o antigo), `clearAvatar(): void`.
- `CompanySettings`: `logoUrl(): ?string` (`route('logo', ['v' => …])`), `setLogo(string $webp): void`, `clearLogo(): void`, chaves `company.logo_path` e `company.logo_version`.
- Rotas: `GET /avatares/{type}/{id}` nome `avatar` (`type` ∈ `users|contacts`, dentro do grupo `auth` + `verified`, `AvatarController`); `GET /marca/logo` nome `logo` (pública, `LogoController`). Ambas ignoram `v` ao servir.

- [ ] **Step 1: Write the failing tests** (`AvatarDeliveryTest`, `RefreshDatabase`, `Storage::fake(config('media.disk'))`): `test_foto_exige_login` (visitante em `/avatares/users/1` → redireciona ao login); `test_logo_e_publico` (visitante em `/marca/logo` com logo gravado → 200 `image/webp`); `test_foto_com_cabecalhos_de_cache_e_etag` (autenticado: `Cache-Control` contém `private`, `max-age=31536000`, `immutable`; 304 com `If-None-Match` igual ao ETag); `test_url_com_versao_velha_serve_a_imagem_atual` (`?v=antiga` → 200); `test_sem_foto_devolve_404`; `test_trocar_a_foto_apaga_o_objeto_antigo_e_muda_a_versao` (duas chamadas a `setAvatar` com imagens diferentes: o primeiro caminho não existe mais no disco, `avatar_version` mudou); `test_tipo_invalido_na_rota_devolve_404`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Media/AvatarDeliveryTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** a migration, o storage, a trait, os controladores, as rotas e os métodos de `CompanySettings`. `AvatarController` busca `User`/`Contact` por `type`, usa `avatar_path` e chama `AvatarStorage::response($path, public: false)`.
- [ ] **Step 4: Run** o mesmo comando — Expected: PASS.
- [ ] **Step 5: Commit** `feat: armazenamento e entrega de avatares e logo (RustFS, cache versionado)`.

### Task 4: Componente `<x-avatar>` e a marca no menu e no login

**Files:**
- Create: `resources/views/components/avatar.blade.php`
- Modify: `resources/views/components/app-logo.blade.php`, `resources/views/layouts/auth/card.blade.php`, `resources/views/layouts/auth/split.blade.php`, `resources/views/components/desktop-user-menu.blade.php`
- Test: `tests/Feature/Components/AvatarComponentTest.php`

**Interfaces:**
- Consumes: `CompanySettings::logoUrl()`, `HasAvatar::avatarUrl()`.
- Produces: `<x-avatar :src="?string" :name="string" shape="round|rounded" size="xs|sm|md|lg|xl" />`, envolvendo `flux:avatar` (com `src` mostra a imagem; sem `src` mostra o monograma com as iniciais de `name`; `circle` para `round`; `rounded` mantém os cantos arredondados do Flux); sempre com `alt`/`aria-label` = `name`; imagem com `loading="lazy"`.

- [ ] **Step 1: Write the failing tests:** `test_com_src_renderiza_img_com_alt_do_nome`; `test_sem_src_renderiza_monograma_com_iniciais_e_nome_acessivel` (`name="Maria Souza"` → `MS` e `aria-label`/`alt` `Maria Souza`); `test_round_usa_circulo_e_rounded_nao`; `test_menu_usa_o_logo_da_empresa_quando_existe_e_monograma_quando_nao` (`Setting::put('company.name','Zeta')` sem logo → monograma `Z` na sidebar; com `setLogo` → `<img` com `/marca/logo`).
- [ ] **Step 2: Run** `php artisan test tests/Feature/Components/AvatarComponentTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** o componente e trocar o ícone do Laravel (`x-app-logo-icon`) por `<x-avatar :src="CompanySettings::logoUrl()" :name="CompanySettings::name()" shape="rounded" />` em `app-logo.blade.php` e nos layouts de login (`card`, `split`); em `desktop-user-menu.blade.php` usar `<x-avatar :src="auth()->user()->avatarUrl()" :name="auth()->user()->name" shape="round" />` no lugar do `flux:avatar` atual.
- [ ] **Step 4: Run** o mesmo comando mais `php artisan test tests/Feature/Layout --parallel` — Expected: PASS (ajustar testes de layout que apontem o ícone antigo).
- [ ] **Step 5: Commit** `feat: componente de avatar com monograma e marca da empresa no menu e no login`.

### Task 5: Editor de enquadramento (`x-avatar-uploader`)

**Files:**
- Modify: `package.json`, `package-lock.json` (`npm install cropperjs@^2.1`), `resources/js/app.js`
- Create: `resources/js/avatar-cropper.js`, `resources/views/components/avatar-uploader.blade.php`, `app/Livewire/Concerns/ReceivesAvatar.php`
- Test: `tests/Feature/Components/AvatarUploaderTest.php`

**Interfaces:**
- Produces (Blade): `<x-avatar-uploader model="photo" :current="?string" :name="string" shape="round|rounded" />`; renderiza o `x-avatar` atual, o botão "Escolher imagem", e o painel do editor (escondido até escolher o arquivo) com botões "Aproximar", "Afastar", setas de mover (teclado e leitor de tela) e "Usar esta foto"/"Cancelar".
- Produces (JS): `Alpine.data('avatarCropper', (wireModel) => …)` em `avatar-cropper.js`; carrega o Cropper com `import('cropperjs')` só ao escolher o arquivo; seleção 1:1 com `initial-coverage="1"` (recorte central cobrindo o maior quadrado); ao confirmar, `selection.$toCanvas({width: 512, height: 512})` → `canvas.toBlob(cb, 'image/webp', 0.9)` → `$wire.upload(wireModel, new File([blob], 'avatar.webp', {type: 'image/webp'}))`; erro de upload mostra a mensagem do Livewire; `motion-safe:` nas transições.
- Produces (PHP): trait `ReceivesAvatar` com `public $photo` (`TemporaryUploadedFile|null`) e `protected function avatarWebp(int $size): string` que valida `['photo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024']]` (mensagens em português) e devolve `AvatarImage::fromUpload($this->photo, $size)`, convertendo `InvalidImage` em erro de validação em `photo`.

- [ ] **Step 1: Run** `npm install cropperjs@^2.1` e conferir que `npm run build` (`vp build`) segue verde.
- [ ] **Step 2: Write the failing tests** (Blade/Livewire; o gesto do editor é verificado no navegador, Task 11): `test_uploader_renderiza_com_atributos_do_alpine_e_botoes_de_zoom` (`assertSee` de "Aproximar", "Afastar", `x-data="avatarCropper(`); `test_trait_recusa_arquivo_acima_de_1mb` e `test_trait_recusa_texto_renomeado_para_png` (componente de teste inline que usa a trait, `Livewire::test` com `UploadedFile::fake()`); `test_trait_devolve_webp_do_tamanho_pedido`.
- [ ] **Step 3: Run** `php artisan test tests/Feature/Components/AvatarUploaderTest.php` — Expected: FAIL.
- [ ] **Step 4: Implement** o componente, o JS (registrado em `app.js` sem importar o Cropper de forma estática) e a trait. Estilo do painel só com tokens do `app.css`.
- [ ] **Step 5: Run** o mesmo comando e `npm run build` — Expected: PASS e build sem erro.
- [ ] **Step 6: Commit** `feat: editor de enquadramento de foto (Cropper.js sob demanda)`.

### Task 6: Logo da empresa na tela Empresa e no QR

**Files:**
- Modify: `app/Livewire/Settings/Company.php`, `resources/views/livewire/settings/company.blade.php`, `resources/views/livewire/settings/channel-card.blade.php`
- Delete: `public/images/qr-logo.svg`
- Test: `tests/Feature/Livewire/CompanyLogoTest.php`

**Interfaces:**
- Consumes: `ReceivesAvatar::avatarWebp(int)`, `CompanySettings::setLogo/clearLogo/logoUrl`, `<x-avatar-uploader>`.
- Produces: `Company::saveLogo(): void` (autoriza `admin`; `avatarWebp(AvatarImage::SIZE_LOGO)` → `CompanySettings::setLogo`; limpa `photo`) e `Company::removeLogo(): void`. O QR usa `CompanySettings::logoUrl() ?? asset('images/logo-padrao.svg')` — como o `qr-code-styling` precisa de imagem, sem logo o QR vai **sem** imagem central (passar `null` ao `channelQr` e tratar em `channel-qr.js`).

- [ ] **Step 1: Write the failing tests:** `test_admin_envia_logo_e_ele_aparece_no_menu` (`UploadedFile::fake()->image('l.png', 800, 500)`, `saveLogo`, `CompanySettings::logoUrl()` não nulo, objeto no disco com 256×256 WebP); `test_remover_logo_volta_ao_monograma_e_apaga_o_objeto`; `test_nao_admin_recebe_403_em_save_e_remove`; `test_imagem_invalida_mostra_erro_e_nao_grava`; `test_qr_do_canal_recebe_o_logo_da_empresa_ou_vazio` (o atributo `x-data="channelQr(…)"` tem a URL de `/marca/logo` com logo e `null`/vazio sem).
- [ ] **Step 2: Run** `php artisan test tests/Feature/Livewire/CompanyLogoTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** na página Empresa (seção "Logotipo", `shape="rounded"`, botão "Remover"); ajustar `channel-card.blade.php` e `resources/js/channel-qr.js` para aceitar `logoUrl` nulo; apagar o SVG provisório (`grep -rn "qr-logo" app resources config public` deve voltar vazio).
- [ ] **Step 4: Run** o mesmo comando e `php artisan test tests/Feature --filter=Channel` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: logotipo da empresa pela UI (menu, login e QR)`.

### Task 7: Foto do usuário

**Files:**
- Modify: `app/Livewire/Settings/Users.php`, `resources/views/livewire/settings/users.blade.php`, a página de perfil (`grep -rn "updateProfileInformation" resources/views app` para achar o componente e a view), `resources/views/livewire/inbox/inbox.blade.php` (cabeçalho do ticket: assinatura de quem atende), `resources/views/livewire/audit/*` (só onde já mostra o usuário e há espaço)
- Test: `tests/Feature/Livewire/UserAvatarTest.php`

**Interfaces:**
- Consumes: `ReceivesAvatar`, `HasAvatar::setAvatar/clearAvatar`, `<x-avatar-uploader>`.
- Produces: no perfil, `saveAvatar(): void`/`removeAvatar(): void` (o próprio usuário); em `Users` (formulário de edição), os mesmos métodos agindo sobre `$editingId` com `authorize('update', $target)`. Lista de usuários e cabeçalho do ticket usam `<x-avatar … shape="round" size="sm">`.

- [ ] **Step 1: Write the failing tests:** `test_usuario_troca_a_propria_foto_e_ela_aparece_na_lista`; `test_admin_troca_a_foto_de_outro_usuario`; `test_atendente_nao_troca_a_foto_de_outro` (403); `test_remover_foto_volta_ao_monograma`; `test_lista_de_usuarios_mostra_monograma_com_nome_acessivel`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Livewire/UserAvatarTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** conforme Interfaces (`AvatarImage::SIZE_PERSON`); `User::avatarPrefix()` retorna `avatars/users`.
- [ ] **Step 4: Run** o mesmo comando e `php artisan test tests/Feature/Livewire/UsersPageTest.php` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: foto do usuário no perfil, na lista e no cabeçalho do ticket`.

### Task 8: Foto do contato vinda do WAHA

**Files:**
- Create: `app/Services/Inbox/SyncContactPhoto.php`, `app/Jobs/SyncContactPhotoJob.php`, `database/factories` (estado `comFoto()` em `ContactFactory`, se útil)
- Modify: `app/Services/Waha/WahaContacts.php`, `app/Services/Inbox/IngestMessage.php` (logo após `FillContactName::queueFor`), `app/Actions/Contacts/CreateContact.php`, `app/Console/Commands/PurgeMediaCommand.php`, `config/services.php` (`waha.photo_hosts`), views da fila (`livewire/inbox/inbox.blade.php`), cabeçalho do ticket e `livewire/inbox/contact.blade.php`, `livewire/history/index.blade.php`, `livewire/contacts/index.blade.php`, `livewire/contacts/contact-card.blade.php`
- Test: `tests/Feature/Inbox/SyncContactPhotoTest.php`, `tests/Feature/Services/WahaContactsPhotoTest.php`, `tests/Feature/Console/PurgeContactPhotosTest.php`

**Interfaces:**
- Produces: `WahaContacts::profilePictureUrl(string $id): ?string` — `GET /api/contacts/profile-picture` com `contactId`, `session`; **404, `profilePictureURL` ausente/`null`/vazio → `null`**; outro erro HTTP → `WahaRequestException` (via `client->check`).
- `SyncContactPhoto::__invoke(Contact $contact, Channel $channel): void`: id = `wa_id ?? lid` (sem id, retorna sem fazer nada); consulta a URL; `null` → grava `avatar_checked_at = now()` e limpa a foto existente (privacidade fechada: monograma) — **resultado definitivo**; com URL: exige `https` e host terminando em um item de `config('services.waha.photo_hosts')` (padrão `['whatsapp.net', 'fbcdn.net']`), baixa com `Http::timeout(10)` e teto de 2 MB, `AvatarImage::fromBinary($bytes, SIZE_CONTACT, 2_097_152)`, `setAvatar`, `avatar_checked_at = now()`; host fora da lista, arquivo grande ou `InvalidImage` → definitivo "sem foto" (mesmo tratamento do `null`); timeout, 5xx, 429 e falha ao baixar → **transitório**: relança a exceção, sem tocar `avatar_checked_at`.
- `SyncContactPhoto::queueFor(Contact $contact, Channel $channel): void`: não enfileira se `avatar_checked_at` tem menos de 24 h; usa `Cache::add('contact-photo:'.$contact->id, 1, now()->addHour())` como trava de disparo (uma tentativa por hora no máximo, também após falha transitória); `SyncContactPhotoJob::dispatch($contact->id, $channel->id)`.
- `SyncContactPhotoJob` (fila `media`, `$tries = 3`, `backoff = [30, 120]`): `Contact::find`/`Channel::find`; some silenciosamente se algum não existe.
- `PurgeMediaCommand`: depois dos anexos, para cada contato com `avatar_path` não nulo e `avatar_checked_at` anterior a `now()->subDays(self::RETENTION_DAYS)`: `AvatarStorage::delete`, zera `avatar_path`, `avatar_version`, `avatar_checked_at`; a saída soma "Fotos de contato expiradas: N".
- Telas: `<x-avatar :src="$contact->avatarUrl()" :name="$contact->name ?? PhoneFormat…" shape="round">` na fila, no cabeçalho do ticket, no Histórico, em Contatos e na ficha; nome acessível mesmo sem nome (usar o telefone formatado).

- [ ] **Step 1: Write the failing tests** (`Http::fake`, `Storage::fake`, `Bus::fake`/`Queue::fake` onde couber): em `WahaContactsPhotoTest`: `test_devolve_a_url`, `test_null_ausente_vazio_e_404_viram_null` (provider com os quatro corpos), `test_429_e_500_lancam_excecao`. Em `SyncContactPhotoTest`: `test_url_valida_grava_miniatura_128_webp_e_marca_a_checagem`; `test_sem_foto_marca_a_checagem_e_deixa_monograma`; `test_privacidade_fechada_remove_a_foto_antiga`; `test_host_estranho_nao_baixa_e_conta_como_sem_foto` (`Http::assertNotSent` para o host); `test_arquivo_grande_demais_conta_como_sem_foto`; `test_erro_transitorio_relanca_e_nao_marca_a_checagem`; `test_contato_so_com_lid_usa_o_lid`; `test_contato_sem_id_nao_chama_o_waha`; `test_queue_for_nao_enfileira_com_checagem_de_menos_de_24h`; `test_dez_chamadas_seguidas_enfileiram_um_job_so`; `test_ingestao_de_mensagem_enfileira_o_job_e_nao_chama_o_waha` (a ingestão não faz HTTP: `Http::assertNothingSent()`). Em `PurgeContactPhotosTest`: `test_purga_apaga_objeto_e_zera_colunas_so_com_mais_de_90_dias` e `test_contato_renovado_nao_expira`.
- [ ] **Step 2: Run** `php artisan test tests/Feature/Inbox/SyncContactPhotoTest.php tests/Feature/Services/WahaContactsPhotoTest.php tests/Feature/Console/PurgeContactPhotosTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** na ordem: `profilePictureUrl`, `SyncContactPhoto`, job, ganchos (`IngestMessage` e `CreateContact` chamam `queueFor`; este último só com canal disponível: ver como `StartConversation` já obtém o canal), purga, `config/services.php`.
- [ ] **Step 4: Run** os três arquivos — Expected: PASS. Depois `php artisan test --parallel --filter=Ingest` para garantir que nada na ingestão quebrou.
- [ ] **Step 5: Implement** as telas (grep: `grep -rn "contact" resources/views/livewire/inbox/inbox.blade.php` etc.), com `<x-avatar>`, e rodar `php artisan test tests/Feature/Livewire --parallel`.
- [ ] **Step 6: Verify no beta (manual, antes do portão 7):** com o canal do beta, um contato com foto e outro sem; conferir o corpo real do `GET /api/contacts/profile-picture` no GOWS (para ajustar o teste e a lista `photo_hosts` se o CDN tiver outro host) e registrar o achado no ledger.
- [ ] **Step 7: Commit** `feat: foto do contato vinda do WAHA (job, cache de 24 h, expira em 90 dias)`.

### Task 9: Setores: criar, renomear e apagar

**Files:**
- Create: `database/migrations/2026_11_11_000002_soft_delete_sectors.php`, `app/Actions/Sectors/DeleteSector.php`, `app/Actions/Sectors/SectorInUse.php`
- Modify: `app/Models/Sector.php`, `app/Models/Ticket.php` (`sector()` com `->withTrashed()`), `app/Models/QuickReply.php` (idem, se exibir o setor), `app/Livewire/Settings/Sectors.php`, `resources/views/livewire/settings/sectors.blade.php`, `app/Livewire/History/Index.php` (filtro: `Sector::withTrashed()`), `database/factories/SectorFactory.php` (se preciso)
- Test: `tests/Feature/Livewire/SectorsCrudTest.php`, `tests/Feature/Domain/SectorSoftDeleteTest.php`

**Interfaces:**
- Migration: `sectors.deleted_at` (timestamp, null); remover o índice único `sectors_name_unique`; `CREATE UNIQUE INDEX sectors_name_active_unique ON sectors (name) WHERE deleted_at IS NULL` (SQL cru, PostgreSQL); `down()` desfaz.
- Produces: `Sector` usa `SoftDeletes`; `Sector::hasOpenTickets(): bool` (move a lógica hoje privada em `Sectors::hasOpenTickets`); `DeleteSector::__invoke(Sector $sector): void` lança `SectorInUse` (mensagem em português listando o motivo: tickets abertos, fluxo da Automação, respostas rápidas) ou faz, numa transação, `$sector->users()->detach()` + `$sector->delete()`. A checagem de workflow lista os nós `Transferir` com `setor_id` igual ao id no rascunho (`Workflow::draft`) e na última versão publicada (`latestVersion()->graph`), lendo pelo `FlowGraph`/`NodeData`.
- `Sectors` (Livewire): `create(string $name)` (validação `required|max:60` e único entre não apagados; entra no fim da ordem, `ATIVO`), `rename(int $id, string $name)` (mesma validação com `ignore($id)`), `remove(int $id)` (chama `DeleteSector`; `SectorInUse` vira `addError('remove.'.$id, …)`); tudo com `authorize('admin')`.

- [ ] **Step 1: Write the failing tests:** em `SectorSoftDeleteTest`: `test_ticket_antigo_continua_mostrando_o_nome_do_setor_apagado`; `test_pode_recriar_um_setor_com_o_nome_de_um_apagado`; `test_nome_duplicado_entre_ativos_viola_o_indice`; `test_setor_apagado_some_do_menu_transferencia_e_bot` (`visibleInMenu`, `receivingTickets`, `Sector::query()`); `test_historico_ainda_filtra_pelo_setor_apagado`. Em `SectorsCrudTest`: `test_admin_cria_setor_no_fim_da_ordem_e_ativo`; `test_renomear_mantem_vinculos_e_tickets`; `test_nome_vazio_ou_repetido_e_recusado`; `test_apagar_sem_uso_funciona_e_solta_usuarios`; `test_apagar_com_ticket_aberto_e_recusado`; `test_apagar_com_no_transferir_no_rascunho_e_recusado` e `…na_versao_publicada_…`; `test_apagar_com_resposta_rapida_e_recusado`; `test_nao_admin_nao_cria_nem_renomeia_nem_apaga` (403, forjando a chamada).
- [ ] **Step 2: Run** `php artisan test tests/Feature/Domain/SectorSoftDeleteTest.php tests/Feature/Livewire/SectorsCrudTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** migration, model, relação `withTrashed`, `DeleteSector`, métodos do Livewire e a view (campo "Novo setor", edição do nome na linha, botão "Apagar" com confirmação e a mensagem de erro por linha; ajustar o texto do docblock que diz "setores fixos na v1").
- [ ] **Step 4: Run** os dois arquivos e `php artisan test tests/Feature/Livewire/SectorsPageTest.php tests/Feature/Bot --parallel` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: setores criáveis, renomeáveis e apagáveis (soft delete, com trava de uso)`.

### Task 10: Setores padrão sem comando manual

**Files:**
- Create: `app/Services/Setup/DefaultSectors.php`
- Modify: `database/seeders/SectorSeeder.php`, `routes/console.php`
- Test: `tests/Feature/Domain/DefaultSectorsTest.php`

**Interfaces:**
- Produces: `DefaultSectors::NAMES = ['Vendas', 'Financeiro', 'Comercial', 'SAC', 'Diretoria']` (ordem do menu, ativos); `DefaultSectors::install(): bool` — idempotente, transacional: se `Setting::get('sectors.defaults_loaded')` é verdadeiro devolve `false`; se `Sector::withTrashed()->exists()` só grava a marca; senão cria os cinco (`menu_order` 1 a 5, `ATIVO`) e grava a marca; devolve `true` quando gravou algo. `SectorSeeder::run()` passa a chamar `DefaultSectors::install()`; `SECTORS` do seeder é removida (a lista vive em `DefaultSectors::NAMES`; ajustar quem a use com `grep`).
- Agendamento: `Schedule::call(fn () => DefaultSectors::install())->everyMinute()->when(fn () => Setting::get('sectors.defaults_loaded') !== true)->name('install-default-sectors')` em `routes/console.php`. Decisão registrada: o `migrate` roda no start do `app` pelo AUTORUN do serversideup (sem gancho próprio) e o container `schedule:work` já existe, então o passo agendado dispensa mexer no deploy; o custo é até 1 minuto de espera na primeira instalação.

- [ ] **Step 1: Write the failing tests:** `test_instalacao_nova_cria_os_cinco_na_ordem_e_ativos`; `test_segunda_chamada_nao_recria_o_que_foi_apagado` (instala, apaga "SAC" com `delete()`, instala de novo: continua sem "SAC"); `test_banco_com_setores_so_grava_a_marca_e_nao_duplica`; `test_setor_so_apagado_conta_como_ja_instalado` (`withTrashed`); `test_agendamento_so_roda_sem_a_marca` (avaliar o `when` do evento agendado pelo `Schedule`).
- [ ] **Step 2: Run** `php artisan test tests/Feature/Domain/DefaultSectorsTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** conforme Interfaces.
- [ ] **Step 4: Run** o mesmo arquivo e `php artisan test tests/Feature/Console --parallel` — Expected: PASS.
- [ ] **Step 5: Commit** `feat: setores padrão instalados uma vez, sem recriar o que o admin apagou`.

### Task 11: Verificação visual e fechamento

**Files:**
- Modify: memória `leva-a-empresa-avatares-estado.md` e `MEMORY.md` (importação já na `main`; leva A implementada, estado do branch)
- Test: nenhum arquivo novo (verificação manual com `chrome-devtools`)

- [ ] **Step 1:** Subir o app local (conforme CLAUDE.md: Postgres de teste não é o do app; usar o ambiente dev do dono) e, no navegador, em 1280×720, 1920×1080, 2560×1440 e 360 px, claro e escuro: login com logo e com monograma; menu; tela Empresa (editor: escolher imagem não quadrada, zoom por botões, setas, confirmar; remover); perfil e lista de usuários; fila, cabeçalho do ticket, Histórico e Contatos com e sem foto; tela Setores (criar, renomear, apagar com e sem uso). Conferir ausência de rolagem horizontal e contraste; console do navegador sem erros.
- [ ] **Step 2:** Atualizar a memória (importação mergeada; leva A pronta no branch, pendências do dono).
- [ ] **Step 3: Commit** `docs: estado da leva A na memória` (a memória fica fora do repositório; commitar só o que mudar no repo, se algo mudar).

## Self-review (feito ao escrever)

- **Cobertura da spec:** meta 1 → Task 1; meta 2 → Tasks 3, 4, 6; meta 3 → Task 7; meta 4 → Tasks 3, 8; meta 5 → Tasks 9, 10; meta 6 → fora do código (importação já na `main`), memória na Task 11; renovação 24 h/expiração 90 dias → Task 8; cache versionado, quem vê, Cropper por npm/Vite, CSP `img-src blob:` (nota para a leva C, não é código desta leva) → Tasks 3 e 5.
- **Desvio da spec, registrar no ledger:** a coluna `avatar_found` foi dispensada (derivável: `avatar_path` nulo com `avatar_checked_at` preenchido = "sem foto").
- **Consistência de nomes:** `AvatarImage::fromBinary/fromUpload`, `AvatarStorage::store/delete/response`, `HasAvatar::avatarUrl/setAvatar/clearAvatar`, `CompanySettings::logoUrl/setLogo/clearLogo`, `ReceivesAvatar::avatarWebp`, `SyncContactPhoto::__invoke/queueFor`, `DefaultSectors::install` usados igual em todas as tarefas.
- **Risco aberto:** qualquer usuário logado pode baixar a foto de qualquer contato pelo id (a spec pede só login); fotos não passam pela regra de setor do ticket. Levar ao dono na mensagem final.
