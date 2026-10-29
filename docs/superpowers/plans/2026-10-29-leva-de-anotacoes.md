# Leva de anotações do inbox — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fechar as pontas soltas do inbox antes da Fase 2 (Contatos): telefone legível, ajuste da caixa de saída, horário de funcionamento com resposta automática, telas de usuários e setores, envio de anexos e áudio gravado com presença "gravando".

**Architecture:** Cada item reaproveita o que já existe: o envio de mídia entra no mesmo `SendTicketMessage` e na fila `outbound` (ordem, "aguardando conexão" e reenvio valem para arquivos); a resposta automática é uma mensagem `origin=bot` pela mesma via; as telas são componentes Livewire/Flux como a de Canais, protegidas por Policy e Gate.

**Tech Stack:** Laravel 13, Livewire 4 (`WithFileUploads`) + Flux, Alpine para o gravador, PostgreSQL 18, Redis, PHPUnit 12 (sem Pest).

**Specs:** `docs/superpowers/specs/2026-10-29-envio-de-anexos-design.md`, `…-horario-de-funcionamento-design.md`, `…-telas-de-admin-design.md`; telefone legível em `…-contatos-design.md` (seção 8). Ajuste do drain: `memory` do projeto, "TODO timing da caixa de saída".

## Global Constraints

- Textos de tela e mensagens de validação em pt-BR; nomes de código em inglês, como no resto do projeto.
- Só o beta; nunca tocar `main`/produção do Coolify. Sem PR (só o dono pede). Um commit por tarefa, terminando com `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.
- Em cada tarefa passam `php artisan test --compact`, `vendor/bin/pint --test` e `vendor/bin/phpstan analyse` (Larastan nível 6).
- Anexos: tipos JPEG, PNG, WebP, PDF, `.doc`, `.docx`, `.xls`, `.xlsx`, `.csv`, MP3, OGG, M4A, MP4; **16 MB por arquivo**, **10 arquivos por envio**; tipo verificado pelo conteúdo, nunca pela extensão; executáveis e scripts recusados; áudio gravado: máximo 5 min, descarta o que tem menos de 1 s.
- Texto digitado com arquivos vira legenda do **primeiro** arquivo; cada arquivo é uma mensagem, enviada na ordem escolhida pela fila `outbound` (um consumidor).
- Resposta automática: texto padrão "Olá! Recebemos sua mensagem. Nosso atendimento funciona {{horario}}. Assim que estivermos de volta, um de nossos atendentes responde você. Voltamos {{proximo_horario}}."; até 4 faixas por dia, sem sobreposição, abre < fecha; fuso `config('app.display_timezone')`; sem faixa cadastrada = sempre aberto.
- Usuários: admin gerencia todos; supervisor só atendentes; ninguém desativa a si mesmo nem o último admin ativo. Setores: só admin; os cinco do seed são fixos; setor com ticket aberto não desativa.
- Limite de envio existente (30/min por usuário) conta cada arquivo como um envio; presença "gravando" tem limite próprio (20/min por usuário).

## Review Focus

1. Eco `fromMe` da mídia enviada pelo painel: não pode virar uma segunda linha `fora_do_painel` (hoje o casamento só vale para `type=text`).
2. Upload maior que o limite do nginx/PHP (padrões 1 MB / 2 MB) falha sem mensagem: o limite de 16 MB precisa valer ponta a ponta.
3. Cliente com 5 mensagens simultâneas fora do horário recebe **uma** resposta automática, e nunca em histórico reprocessado.
4. Anexo "aguardando conexão" cuja cópia local sumiu: vira falha com motivo, sem laço de reenvio.
5. Supervisor forjando `role=admin` na requisição Livewire, ou desativando o último admin: recusado no servidor.

---

### Task 1: Telefone legível no inbox

**Files:**
- Create: `app/Support/PhoneFormat.php`, `tests/Unit/PhoneFormatTest.php`
- Modify: `app/Models/Contact.php`, `resources/views/livewire/inbox/inbox.blade.php` (linhas 46, 50, 72, 85), `tests/Feature/Livewire/InboxTest.php`

**Interfaces:**
- Produces: `PhoneFormat::display(?string $waId, ?string $lid = null): string`; `PhoneFormat::toWaId(string $input): ?string`; `Contact::label(): string`; `Contact::phoneLabel(): string`. (A fase Contatos consome estas assinaturas.)

- [ ] **Step 1: Testes** (`PhoneFormatTest`, com `DataProvider`): `display('5516997524950@c.us')` = `(16) 99752-4950`; `'551633334444@c.us'` = `(16) 3333-4444`; `'14155550100@c.us'` = `+14155550100`; `display(null, 'x@lid')` e `display(null, null)` = `Número oculto pelo WhatsApp`. `toWaId`: `'(16) 99752-4950'`, `'+55 16 99752-4950'`, `'16997524950'` → `'5516997524950@c.us'`; `'12'`, `'abc'`, 16+ dígitos → `null`.
- [ ] **Step 2: Rodar e falhar** — `php artisan test --compact tests/Unit/PhoneFormatTest.php`.
- [ ] **Step 3: Implementar** sem biblioteca: tira `@c.us` e não-dígitos; `55`+13 dígitos = celular, `55`+12 = fixo; demais países `+dígitos`. `toWaId`: 10–11 dígitos recebem `55`; 12–13 começando em `55` ficam; total de 8–15 dígitos, senão `null`. `Contact::label()` = `name` se não vazio, senão `phoneLabel()`.
- [ ] **Step 4: Teste de tela** em `InboxTest`: ticket de contato `wa_id=5516997524950@c.us` sem nome mostra `(16) 99752-4950` no cabeçalho e na lista e `assertDontSee('@c.us')`; trocar os 4 usos de `wa_id`/`lid` da view.
- [ ] **Step 5:** suíte verde e **commit** `feat: telefone legivel no inbox (formatador e rotulo do contato)`.

### Task 2: Ajuste da caixa de saída (gatilho único do drain)

**Files:**
- Create: `app/Observers/ChannelObserver.php`, `tests/Feature/Inbox/OutboxTriggerTest.php`
- Modify: `app/Models/Channel.php` (atributo `#[ObservedBy]`), `app/Jobs/HandleWahaEvent.php` (remove `$reconnected` e o `dispatch` de `applyStatus`; remove `logReceived` e sua chamada), `app/Jobs/DrainOutboxJob.php`, `app/Services/Inbox/DrainOutbox.php`, `tests/Feature/Inbox/OutboxDrainTest.php`

**Interfaces:**
- Consumes: `DrainOutboxJob::dispatch(int $channelId)`.
- Produces: `ChannelObserver::updated(Channel $channel)` agenda o drain sempre que `status` **muda para** `WORKING`, venha do webhook ou da tela Canais; `DrainOutboxJob` implementa `ShouldBeUnique` com `uniqueId = channelId`; `DrainOutbox` devolve ao job se a primeira mensagem estacionou com o canal em `WORKING`, e o job se reagenda em 10 s, no máximo 6 vezes (contador em cache por canal).

- [ ] **Step 1: Testes** (`Queue::fake`) — salvar `status=WORKING` por qualquer caminho (`$channel->update` na tela e `applyStatus` do webhook) agenda **um** `DrainOutboxJob`; salvar WORKING→WORKING não agenda; STOPPED não agenda; dois disparos seguidos não duplicam o job (unicidade). Em `OutboxDrainTest`: mensagem estaciona por `session_down` com o canal `WORKING` → job reagendado com atraso de 10 s; ao sétimo reagendamento não agenda mais.
- [ ] **Step 2: Rodar e falhar.** **Step 3: Implementar.** **Step 4:** `tests/Feature/Inbox` verde; o teste que olhava o log de diagnóstico é removido junto. **Commit** `fix: gatilho unico do drain da caixa de saida e remocao do log de diagnostico`.

### Task 3: Horário de funcionamento (dados e serviço)

**Files:**
- Create: `database/migrations/2026_10_30_000001_create_business_hours_and_holidays.php`, `app/Models/BusinessHour.php`, `app/Models/Holiday.php`, `app/Services/Inbox/BusinessHours.php`, `tests/Unit/BusinessHoursTest.php` (usa banco: `RefreshDatabase`)

**Interfaces:**
- Produces: tabelas `business_hours` (`weekday` 0–6, `opens_at` e `closes_at` tipo `time`) e `holidays` (`date` único, `name`); `BusinessHours::isOpen(CarbonInterface $at): bool`; `BusinessHours::nextOpening(CarbonInterface $at): ?CarbonImmutable`; `BusinessHours::summary(): string`; `BusinessHours::nextOpeningLabel(CarbonInterface $at): string` ("hoje às 13h", "amanhã às 8h", "segunda-feira às 8h", com minutos quando diferentes de zero, ex. "8h30").

- [ ] **Step 1: Testes** — aberto dentro da faixa, fechado fora, fechado na pausa, no limite exato (abre inclusivo, fecha exclusivo), fechado em feriado mesmo dentro da faixa, sem nenhuma faixa = aberto; `nextOpening` para hoje/amanhã/segunda/pulando feriado e `null` sem faixas; `summary` agrupa dias iguais ("de segunda a sexta, das 8h às 12h e das 13h às 18h; sábado, das 8h às 12h"); as comparações usam o fuso de exibição (UTC 23:30 de sexta ainda é 20:30 em São Paulo).
- [ ] **Step 2: Rodar e falhar.** **Step 3: Implementar.** **Step 4: Rodar** → verde. **Commit** `feat: horario de funcionamento e feriados (dados e servico)`.

### Task 4: Resposta automática fora do horário

**Files:**
- Create: `app/Services/Inbox/AfterHoursReply.php`, `tests/Feature/Inbox/AfterHoursReplyTest.php`
- Modify: `app/Enums/MessageOrigin.php` (caso `BOT = 'bot'`), `app/Services/Inbox/IngestMessage.php` (chama o serviço depois do commit da mensagem recebida), `app/Services/Inbox/SendTicketMessage.php` (aceita autor nulo), `resources/views/livewire/inbox/inbox.blade.php` (rótulo "BOT · resposta automática"), `database/seeders` ou `Setting` (chaves `after_hours_enabled`, `after_hours_message`)

**Interfaces:**
- Consumes: `BusinessHours` (Task 3), `SendTicketMessage::create/…`, `Setting::get/put`.
- Produces: `AfterHoursReply::__invoke(Message $incoming): ?Message` — devolve a mensagem criada ou `null`; evento de ticket `after_hours_reply`.

- [ ] **Step 1: Testes** (`Http::fake`, `Queue::fake`, `Carbon::setTestNow`) — fora do horário cria **uma** mensagem `origin=bot`, sem autor, no ticket, com `{{horario}}` e `{{proximo_horario}}` preenchidos, e enfileira o envio; cinco mensagens seguidas criam uma só (**Review Focus 3**), inclusive em jobs concorrentes (a marca `after_hours_reply` é gravada sob o lock do ticket); dentro do horário, com resposta desligada, com `first_human_reply_at` preenchido, com ticket encerrado, com mensagem `fromMe` ou com `sent_at` a mais de 5 minutos do agora: não cria; não altera `first_human_reply_at`.
- [ ] **Step 2: Rodar e falhar.** **Step 3: Implementar** (o texto é renderizado trocando as duas variáveis; ausência de configuração usa o texto padrão). **Step 4: Rodar** `tests/Feature/Inbox` → verde. **Commit** `feat: resposta automatica fora do horario de funcionamento`.

### Task 5: Tela de horário e navegação de configurações

**Files:**
- Create: `app/Livewire/Settings/BusinessHoursPage.php`, `resources/views/livewire/settings/business-hours-page.blade.php`, `tests/Feature/Livewire/BusinessHoursPageTest.php`
- Modify: `routes/web.php` (`configuracoes/horario`, grupo `can:admin`), `resources/views/layouts/app/sidebar.blade.php` (grupo "Configurações" com Canais e Horário; itens futuros entram nas tarefas seguintes)

**Interfaces:**
- Consumes: modelos e `BusinessHours::summary` (Task 3); chaves de `settings` (Task 4).

- [ ] **Step 1: Testes** — admin abre a tela e vê a grade; salva faixas válidas (várias no mesmo dia); recusa sobreposição, faixa invertida (abre ≥ fecha) e mais de 4 faixas; adiciona e remove feriado (data única, nome obrigatório); altera liga/desliga e texto, e a prévia mostra o texto já preenchido; não admin recebe 403.
- [ ] **Step 2: Rodar e falhar.** **Step 3: Implementar** (formulário Flux; mensagens pt-BR; gravação numa transação que substitui as faixas do dia). **Step 4: Rodar** → verde. **Commit** `feat: tela de horario de funcionamento e feriados`.

### Task 6: Usuários — Policy e tela

**Files:**
- Create: `app/Policies/UserPolicy.php`, `app/Livewire/Settings/Users.php`, `resources/views/livewire/settings/users.blade.php`, `tests/Feature/Security/UserPolicyTest.php`, `tests/Feature/Livewire/UsersPageTest.php`
- Modify: `routes/web.php` (`configuracoes/usuarios`, grupo `auth` + `can:supervisor`), sidebar

**Interfaces:**
- Produces: `UserPolicy::viewAny/create/update(User $actor, ?User $target)` e `deactivate`; regra: admin tudo; supervisor só quando o alvo é `atendente` (e só pode criar/atribuir o papel `atendente`); nunca a si mesmo, nunca o último admin ativo. `Users` (Livewire): lista, cria, edita nome/e-mail/papel/setores/`can_transfer`, define senha, ativa/desativa (desativar apaga as linhas de `sessions` da pessoa e mostra quantos tickets abertos ela tem).

- [ ] **Step 1: Testes da Policy** — matriz (ator admin/supervisor/atendente × alvo admin/supervisor/atendente × criar/editar/desativar); último admin ativo não é desativado nem rebaixado; auto-desativação recusada.
- [ ] **Step 2: Testes da tela** — atendente leva 403; supervisor cria atendente com setores, e-mail duplicado e senha curta mostram erro; supervisor forjando `role=admin` ou editando um supervisor leva 403 (**Review Focus 5**); `can_transfer` e setores gravados (`sector_user`); desativar apaga as sessões da pessoa.
- [ ] **Step 3: Rodar e falhar.** **Step 4: Implementar** (a senha usa `Password::defaults()`). **Step 5: Rodar** → verde. **Commit** `feat: tela de usuarios com politica de papeis`.

### Task 7: Setores — tela

**Files:**
- Create: `app/Livewire/Settings/Sectors.php`, `resources/views/livewire/settings/sectors.blade.php`, `tests/Feature/Livewire/SectorsPageTest.php`
- Modify: `routes/web.php` (`configuracoes/setores`, `can:admin`), sidebar

**Interfaces:**
- Consumes: `SectorStatus` (`receivesTickets`), tabela `sectors` (coluna de ordem do menu existente).

- [ ] **Step 1: Testes** — admin troca o estado e reordena; desativar setor com ticket aberto é recusado com aviso; "fora do menu" é aceito; tickets existentes não mudam; não admin leva 403.
- [ ] **Step 2: Rodar e falhar.** **Step 3: Implementar.** **Step 4: Rodar** → verde. **Commit** `feat: tela de setores (estado e ordem)`.

### Task 8: Envio de mídia — servidor

**Files:**
- Create: `database/migrations/2026_10_30_000002_add_local_copy_to_messages.php`, `app/Services/Inbox/AttachmentRules.php`, `app/Services/Inbox/SendTicketMedia.php`, `tests/Unit/AttachmentRulesTest.php`, `tests/Feature/Inbox/SendMediaTest.php`
- Modify: `app/Models/Message.php` (colunas, `hasMedia()` continua `type === 'media'`), `app/Services/Waha/WahaClient.php`, `app/Services/Inbox/SendTicketMessage.php` (`deliver` ramifica por `type`), `app/Services/Inbox/IngestMessage.php` (`matchPending` passa a casar mídia), `database/factories/MessageFactory.php`

**Interfaces:**
- Produces: colunas `messages.local_path` e `messages.media_purged_at` (anuláveis); `AttachmentRules::validate(UploadedFile $file): ?string` (mensagem de erro em pt-BR ou `null`) e `AttachmentRules::kind(string $mime): string` (`image|document|video|audio`); `SendTicketMedia::__invoke(Ticket $ticket, User $author, UploadedFile $file, string $caption = '', bool $voice = false): Message` (grava a cópia em `outbound/{ano}/{mês}/{uuid}.{ext}` no disco `local`, cria a linha `type=media` com `media_mime/name/size`, `body` = legenda, dispara `MessageCreated` e `SendTicketMessageJob`); `WahaClient::sendImage/sendFile/sendVideo/sendVoice(string $chatId, string $mimetype, string $filename, string $data, string $caption = ''): array` (corpo `{session, chatId, file:{mimetype, filename, data}, caption}`; `sendVoice` com `convert: true`).

- [ ] **Step 1: Testes de `AttachmentRules`** — aceita cada tipo da lista com conteúdo real; recusa `.exe`, `.js`, `.zip`, e um `.pdf` cujo conteúdo é executável (tipo pelo conteúdo, **não** pela extensão); recusa 16 MB + 1 byte e arquivo vazio; nome com `../` ou caractere de controle é saneado.
- [ ] **Step 2: Testes de envio** (`Http::fake`, `Storage::fake`) — imagem vai por `sendImage` com a legenda em `caption`; PDF e Excel por `sendFile`; MP4 por `sendVideo`; áudio gravado por `sendVoice` com `convert: true`; três arquivos mantêm a ordem; canal desconectado deixa `QUEUED` com a cópia guardada e envia na reconexão (drain); cópia ausente na hora do envio → falha com motivo, sem laço (**Review Focus 4**); arquivo recusado não cria mensagem nem deixa arquivo no disco; **eco `fromMe`** de uma mídia enviada pelo painel (mesmo canal/contato, mesmo `media_mime` e legenda, dentro de 60 s, sem `wa_message_id`) é adotado pela linha existente e não gera `fora_do_painel` (**Review Focus 1**).
- [ ] **Step 3: Rodar e falhar.** **Step 4: Implementar.** **Step 5: Rodar** `tests/Feature/Inbox` → verde. **Commit** `feat: envio de midia pelo painel (servidor, fila e eco)`.

### Task 9: Envio de mídia — bandeja de anexos no inbox

**Files:**
- Create: `tests/Feature/Livewire/InboxAttachmentsTest.php`
- Modify: `app/Livewire/Inbox/Inbox.php` (`WithFileUploads`, `$attachments`, `send()` estendido), `resources/views/livewire/inbox/inbox.blade.php` (clipe, bandeja com miniatura/nome/tamanho/remover, arrastar e Ctrl+V), `config/livewire.php` (publicar e fixar `temporary_file_upload.rules` em `['required','file','max:16384']`), `docker/nginx/*` (`client_max_body_size 20m`), `docker/Dockerfile` ou ini do PHP (`upload_max_filesize=16M`, `post_max_size=20M`), `docs/deploy.md`

**Interfaces:**
- Consumes: `SendTicketMedia`, `AttachmentRules` (Task 8).
- Produces: ações `removeAttachment(int $index)` e `send()` que, havendo arquivos, envia o primeiro com o texto como legenda e os demais sem; mesmo `RateLimiter` de envio contando um por arquivo.

- [ ] **Step 1: Testes** (`Livewire::test(Inbox::class)->set('attachments', [...])`) — enviar 3 arquivos com texto cria 3 mensagens `media` em ordem, só a primeira com `body`; sem arquivo o texto segue como mensagem normal; arquivo inválido mostra o erro e **não** impede os válidos; mais de 10 arquivos recusado; remover da bandeja; quem não pode responder não envia; 31º envio no minuto leva ao aviso de limite.
- [ ] **Step 2: Rodar e falhar.** **Step 3: Implementar** (botão Enviar desabilitado durante o upload, `wire:loading`). **Step 4:** conferir que os limites de nginx e PHP somam ≥ 16 MB por arquivo (**Review Focus 2**) e registrar em `docs/deploy.md`. **Step 5: Rodar** → verde. **Commit** `feat: bandeja de anexos no inbox (varios arquivos, legenda no primeiro)`.

### Task 10: Cópia local servida e retenção de 90 dias

**Files:**
- Create: `app/Console/Commands/PurgeMediaCommand.php` (`app:purge-media`), `tests/Feature/Inbox/PurgeMediaTest.php`
- Modify: `app/Http/Controllers/MessageMediaController.php` (serve do disco quando há `local_path`), `routes/console.php` (diário), `resources/views/livewire/inbox/media.blade.php` (aviso "arquivo expirado" quando `media_purged_at` existe)

**Interfaces:**
- Consumes: `messages.local_path`, `media_purged_at` (Task 8).
- Produces: `app:purge-media` apaga do disco as cópias com mais de 90 dias, zera `local_path`, grava `media_purged_at` e cria `ticket_events` do tipo `media_purged`.

- [ ] **Step 1: Testes** — a rota serve a cópia local (tipo, `Range`, `Content-Disposition` seguro) sem chamar o WAHA; sem `local_path` segue pelo WAHA como hoje; autorização igual à de hoje; `app:purge-media` apaga só o que passou de 90 dias, é idempotente, registra o evento e a mensagem mostra "arquivo expirado".
- [ ] **Step 2: Rodar e falhar.** **Step 3: Implementar.** **Step 4: Rodar** → verde. **Commit** `feat: cache local de midia enviada e retencao de 90 dias`.

### Task 11: Gravador de áudio e presença "gravando"

**Files:**
- Create: `resources/js/voice-recorder.js`, `tests/Feature/Livewire/InboxVoiceTest.php`
- Modify: `resources/js/app.js`, `app/Livewire/Inbox/Inbox.php` (`sendVoice()` recebe o áudio gravado; `recording(bool)` ), `app/Services/Waha/WahaClient.php` (`setPresence(string $presence, ?string $chatId = null)`; `presence` aceita `recording` e `paused` com `chatId`), `resources/views/livewire/inbox/inbox.blade.php` (botão de microfone, faixa de gravação, prévia com `audio-player` existente)

**Interfaces:**
- Consumes: `SendTicketMedia(..., voice: true)` (Task 8), `Presence`.
- Produces: componente Alpine `voiceRecorder` (estados `idle|recording|preview`; `MediaRecorder` com `audio/webm;codecs=opus`; cronômetro; para sozinho em 5 min; descarta < 1 s; libera o microfone ao trocar de ticket ou sair da tela; chama `$wire.recording(true)` a cada 4 s e `recording(false)` ao parar/descartar); `Inbox::recording(bool $on): void` (presença `recording`/`paused` no chat do ticket, limite próprio de 20/min por usuário, melhor esforço: falha vai para o log); `Inbox::sendVoice()` exige `reply` no ticket e passa pela mesma regra de envio.

- [ ] **Step 1: Testes** (`Http::fake`) — `recording(true)` chama `POST /api/{session}/presence` com `chatId` e `presence=recording`; `recording(false)` envia `paused`; 21ª chamada no minuto é ignorada sem erro; falha do WAHA na presença não lança; `sendVoice` com um blob `audio/webm` cria mensagem de voz e envia por `sendVoice`; sem permissão de resposta é recusado; áudio acima de 16 MB recusado.
- [ ] **Step 2: Rodar e falhar.** **Step 3: Implementar** o servidor e o componente JS (estrutura e estados seguem `voice-recorder.tsx` do CRMv2; permissão negada mostra "Permita o microfone no navegador para gravar"). **Step 4: Rodar** a suíte, Pint, PHPStan e `npm run build` → verdes.
- [ ] **Step 5: Verificação no beta (do dono)** — gravar, ouvir, regravar e enviar um áudio; conferir que o cliente vê "gravando áudio…" e que o áudio chega como mensagem de voz. Conferir no Swagger do beta se `convert: true` do `sendVoice` está disponível; se não estiver, abrir tarefa para converter no servidor com `ffmpeg`.
- [ ] **Step 6: Commit** `feat: gravador de audio no painel e presenca gravando`.

---

Fora deste plano: presença do **cliente** no painel ("digitando…/gravando áudio…", assinatura `presence.update`) — anotada pelo dono ("nos dois sentidos") mas **sem spec**; entra depois de uma conversa curta. Contatos segue em `docs/superpowers/plans/2026-10-29-contatos.md` (a Tarefa 1 dele, o formatador de telefone, passa a ser a Tarefa 1 desta leva).
