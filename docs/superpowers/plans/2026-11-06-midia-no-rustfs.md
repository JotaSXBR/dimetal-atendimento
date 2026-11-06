# Mídia no RustFS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Guardar a mídia (recebida e enviada) num bucket S3 privado do RustFS no beta, servida pelo app com `Range`, com a purga de 90 dias valendo para as duas direções.

**Architecture:** Um disco configurável (`config('media.disk')`, `local` por padrão, `s3` no beta). `messages.local_path` vira `media_path` (chave no disco). O `ResolveMessageMediaJob` copia do WAHA para o disco; o `MessageMediaController` serve do disco (local: `response()->file`; S3: busca a URL temporária por HTTP interno e repassa, como já faz com o WAHA) e cai no WAHA sem cópia. O `MidiaNode` do bot copia o arquivo-fonte para o disco `media`.

**Tech Stack:** Laravel 13, Flysystem S3 (`league/flysystem-aws-s3-v3`), RustFS (Coolify), PHPUnit com `Storage::fake`/`Http::fake`.

**Spec:** `docs/superpowers/specs/2026-11-05-midia-no-rustfs-design.md`

## Global Constraints

- Só o beta; nunca `waha` (`ayu9d64rhirujt27vh5l3irz`), `dimetal-main` (`nztdbc9d6agpmza31ozhhmo7`), MARICHAT. Chaves do bucket só nas variáveis do Coolify.
- Chaves no bucket: `inbound/{channel_id}/AAAA/MM/{uuid}.{ext}`, `outbound/AAAA/MM/{uuid}.{ext}`, `internal/` reservada e vazia. Purga: 90 dias (`PurgeMediaCommand::RETENTION_DAYS`).
- `throw=false` no disco S3: toda gravação confere o retorno e trata `false` como erro.
- Pint, Larastan e PHPMD limpos; suíte inteira verde no modo padrão **e** com Redis. Sem PR sem pedido.

## Review Focus

- Mídia recebida cuja cópia falha 4 vezes: a mensagem continua abrindo pelo WAHA (`media_ref`) enquanto ele guardar.
- `Range` inválido ou além do fim (416) e `Range` aberto (`bytes=100-`) no S3 e no local.
- Mensagem antiga sem `media_path` segue pelo WAHA; purgada dá 404 e a tela mostra "arquivo expirado".
- Purga idempotente e sem apagar o arquivo-fonte do bot.
- Reenvio de anexo que falhou depois que a cópia sumiu do disco (falha com motivo, sem laço).

---

### Task 1: disco `media`, coluna `media_path` e os usos de envio/purga

**Files:**
- Create: `config/media.php`, `database/migrations/2026_11_06_000002_rename_local_path_to_media_path.php`
- Modify: `composer.json`/`composer.lock` (`league/flysystem-aws-s3-v3`), `app/Models/Message.php`, `app/Services/Inbox/SendTicketMedia.php`, `app/Services/Inbox/SendTicketMessage.php` (`readAttachment`), `app/Services/Inbox/ResendTicketMessage.php`, `app/Services/Bot/Nodes/MidiaNode.php`, `app/Console/Commands/PurgeMediaCommand.php`, `app/Livewire/Settings/Retention.php` (+ view de Retenção), `resources/views/livewire/inbox/media.blade.php`, `phpunit.xml` (nada: `MEDIA_DISK` padrão é `local`)
- Test: renomear `local_path`→`media_path` em `PurgeMediaTest`, `SendMediaTest`, `InboxVoiceTest`, `RetentionPageTest`, `EngineExtraNodesTest`, `MessageMediaRouteTest` e factories; novos casos abaixo.

**Interfaces:**
- Produces: `config('media.disk'): string`; `Message::$media_path: ?string`; helper `Message::mediaDisk(): Illuminate\Contracts\Filesystem\Filesystem` (= `Storage::disk(config('media.disk'))`).

- [ ] **Step 1: testes (RED)**: `test_bot_copia_o_arquivo_para_o_disco_media_e_grava_o_caminho_da_copia` (o `media_path` começa com `outbound/`, o arquivo-fonte `bot/catalogo.pdf` continua existindo); `test_purga_nao_apaga_o_arquivo_fonte_do_bot`; `test_purga_apaga_do_disco_media_recebida_e_enviada` (duas mensagens com mais de 90 dias, uma `inbound/…`, outra `outbound/…`: arquivos somem, `media_path` nulo, `media_purged_at` preenchido, um `TicketEvent media_purged` por mensagem; idempotente na 2ª rodada); `test_retencao_conta_anexos_recebidos_e_enviados`; os testes existentes renomeados para `media_path`.
- [ ] **Step 2:** `php artisan test --filter='PurgeMedia|SendMedia|EngineExtraNodes|RetentionPage|InboxVoice'` → FAIL (coluna e disco inexistentes).
- [ ] **Step 3: implementar**: migration `renameColumn('local_path','media_path')` (down inverso); `config/media.php` com `'disk' => env('MEDIA_DISK', 'local')`; `Message::mediaDisk()`; trocar `Storage::disk('local')` por `Message::mediaDisk()` em `SendTicketMedia`, `readAttachment`, `PurgeMediaCommand`; `MidiaNode` lê do `local` e faz `mediaDisk()->put('outbound/AAAA/MM/{uuid}.{ext}', stream)`, gravando `media_path` da cópia (falha de gravação: log e `Step::next()`); `Retention` e a view dizem "anexos" (recebidos e enviados); `media.blade.php`/`ResendTicketMessage` usam `media_path`; `composer require league/flysystem-aws-s3-v3 "^3.0"`.
- [ ] **Step 4:** os testes do passo 2 + `tests/Feature/Inbox` + `tests/Feature/Livewire` + `tests/Feature/Bot` → PASS.
- [ ] **Step 5:** `git commit -m "feat: anexos num disco de mídia configurável (media_path), purga das duas direções e cópia do bot"`

### Task 2: servir do disco com `Range`

**Files:** Modify `app/Http/Controllers/MessageMediaController.php`; Test `tests/Feature/Inbox/MessageMediaRouteTest.php` (+ novo `MessageMediaDiskTest.php`).

**Interfaces:** Consumes Task 1 (`Message::mediaDisk()`, `media_path`). Produces: rota `GET /midia/{message}` com a ordem: Gate → purgada (404) → disco → WAHA.

- [ ] **Step 1: testes (RED)** com `Storage::fake('local')`: serve do disco com `Content-Type`/`nosniff`; `Range: bytes=0-3` devolve 206 e 4 bytes com `Content-Range`; `bytes=100-` além do fim devolve 416; `media_purged_at` preenchido devolve 404; sem `media_path` e com `media_ref` cai no WAHA (`Http::fake`); sem Gate devolve 403. **S3:** configurar `media.disk=s3` com endpoint falso, `Http::fake` na URL assinada devolvendo 206 com `Content-Range`: o controller repassa status e cabeçalhos e o `Range` enviado ao endpoint é o do navegador.
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: implementar** `serveFromDisk(Message, Request)`: driver `local` → `response()->file($disk->path(...), headers)`; outro driver → `Http::withHeaders(['Range'=>…])->withOptions(['stream'=>true])->get($disk->temporaryUrl($path, now()->addMinutes(5)))` e o mesmo `response()->stream` + `headersFor` já usados para o WAHA (extrair o trecho comum para um método, sem duplicar).
- [ ] **Step 4:** PASS + `tests/Feature/Inbox` + `tests/Feature/History` (a mídia aparece lá).
- [ ] **Step 5:** `git commit -m "feat: mídia servida do disco configurável com Range e queda para o WAHA"`

### Task 3: o job copia a mídia recebida para o disco

**Files:** Modify `app/Jobs/ResolveMessageMediaJob.php`; Test `tests/Feature/Inbox/ResolveMessageMediaJobTest.php` (ou o existente).

**Interfaces:** Consumes `WahaMedia::streamFile(string $ref, ?string $range): ?Response`, `Message::mediaDisk()`. Produces: `media_path = inbound/{channel_id}/AAAA/MM/{uuid}.{ext}`, `media_size`.

- [ ] **Step 1: testes (RED)**: depois de achar o arquivo no WAHA, o job grava o corpo no disco (`Storage::fake`), `media_path` e `media_size` preenchidos e `MessageStatusChanged` só depois; segunda execução com `media_path` e objeto existentes não copia de novo; WAHA falha ao ler o arquivo (`500`) → exceção (tenta de novo) e `media_path` continua nulo, `media_ref` gravado; `put` devolvendo `false` → exceção.
- [ ] **Step 2:** rodar → FAIL.
- [ ] **Step 3: implementar** `copyToDisk(Message, WahaMedia, string $ref)`: `streamFile($ref, null)`, `StreamWrapper::getResource($response->toPsrResponse()->getBody())` → `$disk->writeStream($key, $resource)` (falso = `RuntimeException`), extensão pelo `media_name`/`EXTENSIONS`.
- [ ] **Step 4:** PASS + `tests/Feature/Inbox` + `tests/Feature/Recovery`.
- [ ] **Step 5:** `git commit -m "feat: mídia recebida é copiada do WAHA para o disco de mídia"`

### Task 4: infraestrutura do beta e verificação no Chrome

- [ ] Suíte inteira (padrão paralela e Redis em série), Pint, Larastan, PHPMD.
- [ ] Pela API do Coolify: criar `rustfs-beta` no destino `dimetal-beta`, volume, sem domínio; bucket `dimetal-beta-media` e chave só dele; `MEDIA_DISK=s3` e `AWS_*` em app, worker e scheduler do beta; push do branch, `git_branch` dos três apps para `feat/midia-no-rustfs`, deploy.
- [ ] Chrome (beta): receber foto, áudio, PDF e vídeo do celular; abrem e o áudio toca com `Range`; anexo enviado abre; objeto no bucket; `media_purged` num registro forçado a 91 dias. Depois do teste: `git_branch` dos apps volta para `beta`.
