# Mídia e presença Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O atendente vê imagens e vídeos e ouve áudios do cliente; as respostas de texto saem com visto, digitando, pausa e offline numa fila de saída própria.

**Architecture:** Mídia fica no WAHA; o Laravel guarda só a referência (`messages.media_ref`) e serve o arquivo por uma rota autenticada que repassa em stream. O envio vira dois passos (criar a linha no clique, entregar num job da fila `outbound`, worker de um processo).

**Tech Stack:** Laravel 13, Livewire 4 + Flux, Redis queue, WAHA GOWS `2026.10.1`, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-29-midia-e-presenca-design.md` (notas em `docs/superpowers/notes/2026-10-28-waha-presenca-e-midia.md`).

## Global Constraints

- Só o **beta**; nunca `main`/produção do Coolify. Commits locais no branch `feat/midia-presenca`; sem push nem PR até o dono pedir.
- Migrações só de expansão (colunas nulas): worker, scheduler e reverb rodam a versão velha durante o deploy.
- Sem `ffmpeg`; áudio recebido não é convertido. WAHA sem endereço público; a chave de API nunca vai ao navegador.
- Retenção de 90 dias: `WHATSAPP_FILES_LIFETIME=7776000`, `WHATSAPP_FILES_FOLDER=/app/.media`.
- `SendTicketMessageJob`: `tries=1`, `timeout=120`, fila `outbound`; `retry_after` da fila acima de 120.
- Qualidade do CI: Larastan `max`, PHPMD (`phpmd.xml`), Pint, cobertura mínima 80%. Rodar `vendor/bin/pint`, `vendor/bin/phpstan` e `php artisan test` antes de fechar cada task.
- Texto da interface em português.

## Review Focus

- Mídia sem `filename` e sem `mimetype` no evento: grava `media_ref=null`, mostra "Mídia indisponível", texto preservado.
- `media_ref` com `../` ou `/` (gravado por evento adulterado): a rota responde 404 sem tocar o WAHA.
- Dois envios seguidos do mesmo ticket (A longa, B curta): A é entregue antes de B.
- Falha em `startTyping` ou `sendSeen`: a mensagem ainda é enviada.
- Erro 463/475 no envio: marca falha com motivo próprio, não tenta de novo, não reinicia a sessão.
- Cliente manda mídia e o WAHA fica fora do ar: job de busca tenta de novo e, esgotado, a mensagem fica "indisponível".

## File Structure

- `database/migrations/2026_10_29_000001_add_media_to_messages.php` (novo): colunas de mídia.
- `app/Models/Message.php`: campos novos, `hasMedia()`.
- `app/Services/Inbox/IncomingMessage.php`: lê `media.*` do evento.
- `app/Services/Waha/WahaClient.php`: `downloadMessageMedia`, `streamFile`, `sendSeen` com ids.
- `app/Jobs/ResolveMessageMediaJob.php` (novo): completa a referência da mídia.
- `app/Http/Controllers/MessageMediaController.php` (novo) + rota `midia/{message}`.
- `resources/views/livewire/inbox/inbox.blade.php`: bolhas de mídia.
- `app/Services/Inbox/SendTicketMessage.php`, `app/Services/Inbox/TypingDelay.php` (novo), `app/Jobs/SendTicketMessageJob.php`, `app/Jobs/GoOfflineJob.php` (novo), `app/Livewire/Inbox/Inbox.php`: envio humanizado.
- `docker/Dockerfile`, `.github/workflows/deploy-beta.yml`, `config/queue.php`, `docs/deploy.md`, `docs/superpowers/specs/waha-config.md`: infra e docs.

---

### Task 1: Colunas de mídia na mensagem

**Files:**
- Create: `database/migrations/2026_10_29_000001_add_media_to_messages.php`
- Modify: `app/Models/Message.php` (fillable, `@property`)
- Test: `tests/Feature/Inbox/MessageMediaColumnsTest.php`

**Interfaces:**
- Produces: `messages.media_ref` (string 255 nullable), `media_mime` (string 100 nullable), `media_name` (string 255 nullable), `media_size` (unsigned bigint nullable); `Message::hasMedia(): bool` (`type === 'media'`).

- [ ] **Step 1: Test** `test_mensagem_guarda_a_referencia_da_midia`: cria `Message::factory()->create(['type'=>'media','media_ref'=>'a.ogg','media_mime'=>'audio/ogg','media_name'=>'a.ogg','media_size'=>1234])`, recarrega e afirma os quatro valores e `hasMedia() === true`; `test_mensagem_de_texto_nao_tem_midia` afirma `hasMedia() === false` e colunas nulas.
- [ ] **Step 2:** `php artisan test --filter=MessageMediaColumnsTest` → FALHA (coluna inexistente).
- [ ] **Step 3:** Migração com as quatro colunas nulas (sem índices) e `down()` que as remove; incluir no `#[Fillable]` do model e nos `@property`; `hasMedia()` no model.
- [ ] **Step 4:** mesmo comando → PASSA.
- [ ] **Step 5:** `git add database app tests && git commit -m "feat: colunas de midia na mensagem"`.

### Task 2: Ler a mídia do evento

**Files:**
- Modify: `app/Services/Inbox/IncomingMessage.php`, `app/Services/Inbox/IngestMessage.php` (`store`, linha ~110)
- Test: `tests/Feature/Inbox/IngestMessageTest.php` (casos novos)

**Interfaces:**
- Consumes: Task 1.
- Produces: `IncomingMessage` ganha `?string $mediaRef`, `?string $mediaMime`, `?string $mediaName`, `?int $mediaSize` e `bool $viewOnce`; `mediaRef` é o nome do arquivo em `payload.media.filename` quando existir (nunca a `url`); `IngestMessage` grava os quatro campos na mensagem.

- [ ] **Step 1: Tests** (no estilo de `IngestMessageTest`): `test_midia_com_filename_guarda_a_referencia` (payload `hasMedia=true`, `media: {filename:'abc.ogg', mimetype:'audio/ogg; codecs=opus'}` → `media_ref='abc.ogg'`, `media_mime='audio/ogg'` sem o parâmetro `;codecs`, `type='media'`); `test_midia_so_com_url_enc_fica_sem_referencia` (`media.url='https://mmg.whatsapp.net/x.enc'` → `media_ref` nulo, mensagem gravada); `test_visualizacao_unica_vira_mensagem_marcada` (payload sem corpo e sem mídia com o tipo de visualização única → mensagem gravada com `type='view_once'`). Montar os payloads a partir de um `waha_events` real do beta (leitura) ou do formato em `docs/superpowers/specs/waha-config.md` §9; **anotar no teste o campo exato em que o GOWS marca visualização única**.
- [ ] **Step 2:** `php artisan test --filter=IngestMessageTest` → FALHA nos três novos.
- [ ] **Step 3:** Em `IncomingMessage::content`, extrair `media.filename`, `media.mimetype` (cortar em `;`), `media.size` se existir; `typeFor` devolve `'view_once'` para o marcador de visualização única. Em `IngestMessage::store`, repassar os campos. Não aceitar `filename` com `/` ou `..` (descartar como nulo).
- [ ] **Step 4:** mesmo comando → PASSA (e os testes antigos continuam).
- [ ] **Step 5:** commit `feat: le a midia do evento do WAHA`.

### Task 3: Buscar a mídia que o evento não trouxe

**Files:**
- Modify: `app/Services/Waha/WahaClient.php`, `app/Services/Inbox/IngestMessage.php`
- Create: `app/Jobs/ResolveMessageMediaJob.php`
- Test: `tests/Feature/Jobs/ResolveMessageMediaJobTest.php`

**Interfaces:**
- Consumes: Task 2.
- Produces: `WahaClient::downloadMessageMedia(string $chatId, string $waId): ?array{filename: string, mimetype: ?string}` (`GET /api/{session}/chats/{chatId}/messages/{waId}?downloadMedia=true`, lê `media.filename`; `null` se não houver); `ResolveMessageMediaJob(int $messageId)` (`tries=4`, backoff `[5,15,60]`, fila padrão) que preenche `media_ref`/`media_mime`/`media_name` e dispara `MessageStatusChanged` para a tela atualizar. `IngestMessage` despacha o job (`afterCommit`) para toda mensagem `type='media'` sem `media_ref`.

- [ ] **Step 0 (verificação no beta):** confirmar com uma chamada real ao `waha-beta` o formato de `media.filename` e o caminho de leitura do arquivo (`/api/files/{filename}` ou `/api/files/{session}/{filename}`) e escrever o resultado como comentário no `WahaClient::streamFile` (Task 4).
- [ ] **Step 1: Tests** com `Http::fake`: `test_busca_a_midia_pelo_id_e_grava_a_referencia`; `test_midia_ja_resolvida_nao_chama_o_waha`; `test_waha_fora_do_ar_tenta_de_novo` (job lança e `release` por backoff, mensagem intacta); `test_esgotadas_as_tentativas_a_mensagem_fica_sem_referencia` (chamar `failed()`, sem apagar o texto).
- [ ] **Step 2:** `php artisan test --filter=ResolveMessageMediaJobTest` → FALHA.
- [ ] **Step 3:** Implementar `downloadMessageMedia` e o job; `IngestMessage` despacha com `->afterCommit()`.
- [ ] **Step 4:** testes do job e `IngestMessageTest` → PASSAM.
- [ ] **Step 5:** commit `feat: busca a midia pelo id quando o evento nao a traz`.

### Task 4: Rota que serve a mídia

**Files:**
- Create: `app/Http/Controllers/MessageMediaController.php`
- Modify: `routes/web.php` (grupo `auth`+`verified`: `Route::get('midia/{message}', MessageMediaController::class)->name('midia')`), `app/Services/Waha/WahaClient.php`
- Test: `tests/Feature/Inbox/MessageMediaRouteTest.php`

**Interfaces:**
- Consumes: Tasks 1 e 3.
- Produces: `WahaClient::streamFile(string $ref, ?string $range): Response` (stream, encaminha `Range`); `GET /midia/{message}` com `Content-Type` da lista (`image/jpeg|png|webp|gif`, `audio/ogg|mpeg|mp4|aac`, `video/mp4|webm`, `application/pdf`), `X-Content-Type-Options: nosniff`, `Cache-Control: private`, `Accept-Ranges: bytes`; outro tipo vira `application/octet-stream` com `Content-Disposition: attachment`.

- [ ] **Step 1: Tests** com `Http::fake`: `test_quem_ve_o_ticket_recebe_o_arquivo` (200, corpo igual, `nosniff`, `Cache-Control` privado); `test_quem_nao_ve_o_ticket_leva_403`; `test_visitante_vai_para_o_login`; `test_tipo_fora_da_lista_baixa_como_anexo`; `test_referencia_com_barra_ou_ponto_ponto_da_404_sem_chamar_o_waha` (`Http::assertNothingSent`); `test_range_e_repassado_e_devolve_206`; `test_mensagem_sem_midia_da_404`.
- [ ] **Step 2:** `php artisan test --filter=MessageMediaRouteTest` → FALHA.
- [ ] **Step 3:** Controller invocável: `Message::with('ticket')->findOrFail`, `Gate::authorize('view', $message->ticket)`, validar `media_ref` com `^[A-Za-z0-9._-]+$` e sem `..`, chamar `streamFile`, responder com `response()->stream` copiando status (200/206), `Content-Range` e `Content-Length` quando vierem. A chave de API nunca aparece na resposta.
- [ ] **Step 4:** mesmo comando → PASSA.
- [ ] **Step 5:** commit `feat: rota autenticada que serve a midia do WAHA`.

### Task 5: Mostrar a mídia na conversa

**Files:**
- Modify: `resources/views/livewire/inbox/inbox.blade.php` (bolha, linha ~173)
- Test: `tests/Feature/Livewire/InboxMediaTest.php`

**Interfaces:**
- Consumes: Tasks 1, 2 e 4 (`route('midia', $message)`).

- [ ] **Step 1: Tests** (Livewire, estilo de `InboxTest`): `test_imagem_aparece_como_img_com_a_rota_da_midia` (`assertSeeHtml('<img')` e a URL), `test_audio_aparece_com_controles` (`<audio controls`), `test_video_aparece_com_controles`, `test_documento_vira_link_de_download` (`download`), `test_midia_sem_referencia_mostra_indisponivel` (texto "Mídia indisponível"), `test_visualizacao_unica_mostra_o_aviso` ("o cliente enviou uma mensagem de visualização única; abra no celular"), `test_nenhuma_bolha_fica_vazia`.
- [ ] **Step 2:** `php artisan test --filter=InboxMediaTest` → FALHA.
- [ ] **Step 3:** Escolher a bolha por `media_mime` (prefixos `image/`, `audio/`, `video/`, senão link com `download`); `preload="metadata"` no áudio e no vídeo; `loading="lazy"` na imagem; `max-w` e `rounded` no padrão Flux/Tailwind das bolhas atuais; texto de legenda continua em `body`.
- [ ] **Step 4:** mesmo comando e `InboxTest` → PASSAM.
- [ ] **Step 5:** commit `feat: bolhas de midia no inbox`.

### Task 6: Fila de saída e linha criada no clique

**Files:**
- Modify: `app/Services/Inbox/SendTicketMessage.php`, `app/Jobs/SendTicketMessageJob.php`, `app/Livewire/Inbox/Inbox.php` (`send`, linha 163), `config/queue.php`
- Test: `tests/Feature/Inbox/SendTicketMessageTest.php`, `tests/Feature/Livewire/InboxTest.php`, `tests/Feature/Security/RateLimitTest.php` (ajustar)

**Interfaces:**
- Produces: `SendTicketMessage::create(Ticket $ticket, User $author, string $text): Message` (linha `text`, origem painel, `MessageCreated::dispatch`); `SendTicketMessage::deliver(Message $message): void` (envio ao WAHA; mantém o tratamento atual de falha e eco); `SendTicketMessageJob(int $messageId)` com `$queue='outbound'`, `$tries=1`, `$timeout=120`; `config('queue.connections.redis.retry_after')` com padrão `180`.

- [ ] **Step 1: Tests:** `test_clicar_em_enviar_cria_a_linha_e_despacha_na_fila_outbound` (Livewire: `Message` existe com `delivery_status` nulo e `Queue::assertPushedOn('outbound', SendTicketMessageJob::class)`); `test_deliver_marca_falha_com_o_motivo` (migrar o teste atual de falha); `test_retry_after_maior_que_o_timeout_do_job`.
- [ ] **Step 2:** `php artisan test --filter="SendTicketMessageTest|InboxTest|RateLimitTest"` → FALHA.
- [ ] **Step 3:** Dividir o serviço, mudar o job para receber `messageId`, `Inbox::send` chama `create` e despacha. Limite de envio por usuário continua antes de criar a linha.
- [ ] **Step 4:** os três arquivos de teste → PASSAM.
- [ ] **Step 5:** commit `refactor: linha criada no clique e envio na fila outbound`.

### Task 7: Envio com presença

**Files:**
- Create: `app/Services/Inbox/TypingDelay.php`, `app/Jobs/GoOfflineJob.php`
- Modify: `app/Services/Inbox/SendTicketMessage.php` (`deliver`), `app/Services/Waha/WahaClient.php` (`sendSeen`)
- Test: `tests/Feature/Inbox/PresenceSendTest.php`, `tests/Unit/TypingDelayTest.php`

**Interfaces:**
- Consumes: Task 6.
- Produces: `TypingDelay::milliseconds(string $text): int` entre 1000 e 6000, crescente com o tamanho, com variação aleatória; `WahaClient::sendSeen(string $chatId, array $messageIds): void`; `GoOfflineJob(int $channelId, string $token)` (fila `outbound`, `delay` 45 s) que só chama `setPresence('offline')` se `Cache::get("presence-offline:{$channelId}") === $token`.

- [ ] **Step 1: Tests:** unit `test_o_atraso_fica_entre_1_e_6_segundos` e `test_texto_maior_nao_atrasa_menos_na_media`; feature com `Http::fake` e `Sleep::fake()`: `test_sequencia_visto_digitando_envio` (`Http::assertSentInOrder`: `sendSeen` com os ids das mensagens recebidas, `startTyping`, `stopTyping`, `sendText`), `test_falha_no_digitando_nao_impede_o_envio`, `test_falha_no_visto_nao_impede_o_envio`, `test_offline_so_dispara_do_ultimo_envio` (dois `deliver` seguidos, rodar os dois `GoOfflineJob`, só um `presence offline`), `test_erro_463_ou_475_marca_falha_com_motivo_proprio` (`failure_reason` começa com "Limite do WhatsApp", `Log::warning`, nenhuma nova chamada ao WAHA), `test_mensagem_a_antes_de_b` (mesma fila, `Queue::fake`, ordem de despacho preservada).
- [ ] **Step 2:** `php artisan test --filter="PresenceSendTest|TypingDelayTest"` → FALHA.
- [ ] **Step 3:** `deliver` encadeia a sequência com `Sleep::for(TypingDelay::milliseconds($text))->milliseconds()`; presença é melhor esforço (`try/catch` com `Log::info`); `sendText` mantém o tratamento atual; erro cujo texto traga `463` ou `475` marca falha sem retentar; depois do envio grava um token novo em `presence-offline:{channelId}` (TTL 5 min) e despacha `GoOfflineJob` com atraso de 45 s. Não reiniciar nem reparear a sessão em nenhum caminho.
- [ ] **Step 4:** mesmo comando e `SendTicketMessageTest` → PASSAM.
- [ ] **Step 5:** commit `feat: envio humanizado com visto, digitando e offline`.

### Task 8: Worker de saída, WAHA com retenção e documentação

**Files:**
- Modify: `docker/Dockerfile` (`worker` passa a rodar `docker/worker.sh` (dois processos: `outbound` x1 e `default`; decisão do dono, sem container novo)), `docs/deploy.md`, `docs/superpowers/specs/waha-config.md` §7 (hoje diz que o app baixa e guarda o arquivo: trocar pela decisão atual), `docs/security.md` (rota de mídia)

**Interfaces:**
- Consumes: Tasks 4 e 6.

- [x] **Step 1:** (substituído) sem recurso novo: `docker/worker.sh` no `worker-beta`.
- [ ] **Step 2:** No `waha-beta`, definir `WHATSAPP_FILES_LIFETIME=7776000` e reiniciar o WAHA; confirmar que a sessão continua `WORKING`.
- [ ] **Step 3:** Editar Dockerfile, workflow e docs; `docker build --target worker-outbound -f docker/Dockerfile .` conclui sem erro.
- [ ] **Step 4:** Rodar `vendor/bin/pint`, `vendor/bin/phpstan`, `vendor/bin/phpmd` conforme o CI e `php artisan test` completo.
- [ ] **Step 5:** commit `feat: worker de saida, retencao de 90 dias no WAHA e docs`.

### Task 9: Verificação no beta (depois do merge autorizado pelo dono)

- [ ] **Step 1:** O dono manda uma foto, um áudio e um vídeo; conferir que aparecem e tocam no Chrome e no Safari do iPhone.
- [ ] **Step 2:** Responder duas mensagens seguidas; conferir a ordem, o "digitando" no celular do cliente e o celular do atendente recebendo push depois do offline.
- [ ] **Step 3:** Reiniciar o WAHA e conferir que a limpeza por tempo continua ativa (arquivo antigo some); se não, registrar o plano B (`find -mtime +90 -delete`) no `docs/deploy.md`.
