# Fatia 3: menu da mensagem (responder, reagir, editar, apagar) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O atendente cita (Responder), reage, edita e apaga para todos a partir do menu da bolha, com o original sempre guardado no painel.

**Architecture:** Cada ação é um método do Livewire (`Inbox`, trait `ActsOnMessages`) que chama um serviço pequeno, e o serviço chama o WAHA na hora (`WahaMessageActions`). Recusa vira `MessageActionFailed` com o motivo e nada muda no painel. O painel muda quando chega o eco do WhatsApp (`ApplyMessageChange`, já existente). Citação: duas colunas em `messages` (id citado e trecho), preenchidas ao receber (`replyTo`) e ao enviar (`reply_to`).

**Tech Stack:** Laravel 13, Livewire 4, Flux, Reverb, PostgreSQL 18, Redis, WAHA 2026.9.2 (GOWS), PHPUnit (`php artisan test`).

**Spec:** `docs/superpowers/specs/2026-11-03-inbox-completa-design.md` (seção 5, "Fatia 3", bloco "Decisões do dono em 04/11").

## Global Constraints

- Textos de tela em português; commits em português com prefixo, um por tarefa; branch `feat/inbox-menu-da-mensagem` (nunca a `main`); sem PR até o dono pedir.
- Reações: **👍 ❤️ 😂 😮 😢 🙏**, clicar no mesmo remove (emoji vazio no WAHA). Editar: só texto do painel, só o autor, até **15 min**. Apagar: só do painel, só o autor, **sem janela nossa**; `revoked_at` só no eco `message.revoked`.
- Mensagem do cliente e do bot: só Responder e Reagir. Toda ação exige a permissão `reply` do ticket.
- Falha do WAHA mostra o motivo e não altera o painel. Toda ação grava `TicketEvent` (`message_reacted`, `message_edited`, `message_revoked_by_agent`) com o autor.
- Id completo para o WAHA: `{true|false}_{chat}_{id}` (chat = `wa_id` ou `lid` do contato); mensagem sem `wa_message_id` não aceita ação.
- Sem novo worker; chamadas ao WAHA são síncronas no clique (timeout do `WahaClient`, 15 s).
- Busca de código do app só por `codegraph_explore`; Blade/JS por `grep`/leitura, avisando o dono. Testes: Postgres em 127.0.0.1:5432; rápido `php artisan test --parallel` com `QUEUE_CONNECTION=database`; portão 7 com Redis em série, mais Pint, Larastan e PHPMD (PHPMD limita 15 métodos públicos por classe e complexidade 10: por isso as ações ficam em classes próprias).

## Review Focus

1. Mensagem ainda sem `wa_message_id` (envio na fila) e contato `@lid`: a ação é recusada sem chamar o WAHA e o id completo sai com o chat certo (Tasks 3 e 5).
2. Editar fora dos 15 min, texto vazio, igual ao atual ou acima do limite: o item some do menu e o servidor também recusa (Task 6).
3. Apagar que o WhatsApp só aplicou no celular da empresa (WAHA "ok" sem eco): o painel **não** marca apagada (Task 6).
4. Responder a mensagem de **outro ticket**, de outro canal ou inexistente (id vindo do navegador): recusado; citar mensagem fora da página ou apagada funciona com o trecho guardado (Tasks 2 e 4).
5. Texto citado com `<script>` ou `*` sem fechar: sai escapado e sem HTML cru (Task 2).

---

### Task 1: Medir o `replyTo` real (sem código; usa o log de debug do WAHA)

**Files:**
- Modify: `docs/superpowers/specs/2026-11-03-inbox-completa-design.md` (registrar o formato na seção "Fatia 3")

**Interfaces:**
- Produces: o formato de `payload.replyTo` (campos do id citado, do corpo e de quem escreveu, inclusive com `@lid`) que a Task 2 lê.

- [ ] **Step 1: Pedir ao dono** que, do celular de teste ((16) 99752-4950), responda citando uma mensagem do atendente ao canal do beta (nunca a cliente real) e depois cite uma mensagem do próprio contato. O debug do WAHA já está ligado.
- [ ] **Step 2: Ler o log do `waha-beta` pela API do Coolify** (`.env.coolify.local`; nunca imprimir o token) e extrair o JSON do evento `message` com `replyTo`, sem telefones completos no registro.
- [ ] **Step 3: Registrar no spec** os nomes exatos dos campos e qualquer variação (`@lid`, citação de mídia). Se não houver `replyTo` utilizável, parar e voltar ao dono.
- [ ] **Step 4: Commit** — `git commit -m "docs: formato real do replyTo do GOWS"`.

### Task 2: Receber citação e mostrá-la no transcript

**Files:**
- Create: `database/migrations/2026_11_05_000001_add_quote_to_messages.php`, `app/Services/Inbox/Quote.php`
- Modify: `app/Models/Message.php` (fillable, casts, docblock), `app/Services/Inbox/IncomingMessage.php`, `app/Services/Inbox/IngestMessage.php` (`attributes`), `resources/views/livewire/inbox/transcript.blade.php`, `database/factories/MessageFactory.php` (se listar colunas)
- Test: `tests/Feature/Inbox/QuotedMessageTest.php`, `tests/Feature/Livewire/InboxTranscriptTest.php`

**Interfaces:**
- Produces: colunas `messages.quoted_wa_message_id` (string 255, nullable) e `messages.quoted_snapshot` (json, nullable; cast `array`).
- Produces: `Quote::snapshot(Message $target): array{author: string, type: string, excerpt: string}` — autor (nome do contato ou do atendente, "Empresa" quando fora do painel), tipo e trecho de até 200 caracteres (mídia sem texto vira "Foto", "Áudio", "Vídeo", "Arquivo").
- Produces: `Quote::fromPayload(array $data, Channel $channel): ?array{wa_id: string, snapshot: array}` — lê `replyTo` no formato medido na Task 1; usa a mensagem local (`channel_id` + `wa_message_id` = `Message::waKey(id)`) se existir, senão o corpo que veio no payload; tolera formato desconhecido devolvendo `null`.
- Produces: `IncomingMessage::$quote` (`?array`, último parâmetro com padrão `null`), preenchido em `fromEvent` por `Quote::fromPayload`.

- [ ] **Step 1: Write the failing tests** — `test_mensagem_com_replyto_guarda_o_id_citado_e_o_trecho` (ingere evento do cliente com `replyTo` do formato da Task 1 sobre mensagem existente: `quoted_wa_message_id` igual a `Message::waKey(...)` e `quoted_snapshot['excerpt']` do texto original); `test_citacao_de_mensagem_desconhecida_usa_o_corpo_do_payload`; `test_trecho_passa_de_200_caracteres_e_e_cortado`; `test_replyto_em_formato_estranho_vira_mensagem_sem_citacao` (nenhum erro); `test_midia_citada_sem_texto_vira_o_nome_do_tipo`; `InboxTranscriptTest::test_bloco_citado_aparece_escapado_e_com_ancora_da_original` (corpo `<script>x</script> *a`: HTML escapado, sem tag crua, e o link `data-quote-target` com o id local da original quando ela está carregada).
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Inbox/QuotedMessageTest.php tests/Feature/Livewire/InboxTranscriptTest.php` → FAIL (classe e colunas ausentes).
- [ ] **Step 3: Implement** a migration aditiva (nullable, `down` remove as colunas), os contratos acima e, no Blade, o bloco citado dentro da bolha (autor, trecho, `data-quote-target="{{ id local ou vazio }}"`). A bolha ganha `id="message-{{ $message->id }}"`; o clique rola até a original e a destaca com uma classe temporária, e não faz nada se ela não está na página (JS no `resources/js/app.js` ou módulo novo `quote-jump.js`, importado em `app.js`).
- [ ] **Step 4: Run to verify they pass** — mesmo comando, mais `tests/Feature/Inbox` → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: receber e mostrar citacao de mensagem"`.

### Task 3: Ações no WAHA, id completo, política e erro de ação

**Files:**
- Create: `app/Services/Waha/WahaMessageActions.php`, `app/Services/Inbox/MessageActionFailed.php`, `app/Policies/MessagePolicy.php`
- Modify: `app/Models/Message.php` (`fullWaId`, constante da janela)
- Test: `tests/Feature/Services/WahaMessageActionsTest.php`, `tests/Feature/Inbox/MessagePolicyTest.php`, `tests/Unit/MessageFullIdTest.php`

**Interfaces:**
- Produces: `Message::EDIT_WINDOW_MINUTES = 15`; `Message::fullWaId(): ?string` — `null` sem `wa_message_id`; senão `{true se OUT, false se IN}_{wa_id ?? lid do contato}_{wa_message_id}`.
- Produces: `WahaMessageActions::__construct(WahaClient $client)`; `react(string $fullId, string $emoji): void` (`PUT /api/reaction` com `session`, `messageId`, `reaction`; emoji vazio remove); `edit(string $chatId, string $fullId, string $text): void` (`PUT /api/{sessão}/chats/{chat}/messages/{id}` com `text`); `revoke(string $chatId, string $fullId): void` (`DELETE` na mesma rota). Usam `http()`/`check()`/`sessionName()` e traduzem `WahaRequestException` em `MessageActionFailed`. O `@` do chat vai codificado na URL (`rawurlencode`).
- Produces: `MessageActionFailed extends RuntimeException` (a mensagem já é o motivo em português; `fromWaha(WahaRequestException): self` troca códigos conhecidos por frases e deixa o resto "O WhatsApp recusou a ação.").
- Produces: `MessagePolicy`: `reply(User, Message)` (permissão `reply` do ticket), `react` (reply + `wa_message_id` + não apagada), `edit` (direction OUT, origin PAINEL, `author_user_id` do usuário, `type` texto, não apagada, tem `wa_message_id`, `sent_at` há no máximo `EDIT_WINDOW_MINUTES`), `revoke` (como `edit` sem a janela e para qualquer tipo).

- [ ] **Step 1: Write the failing tests** — `Http::fake`: `test_reagir_manda_put_reaction_com_o_id_completo`, `test_emoji_vazio_remove_a_reacao`, `test_editar_e_apagar_usam_a_rota_da_conversa_com_chat_codificado`, `test_recusa_do_waha_vira_message_action_failed_com_motivo`; `MessageFullIdTest::test_id_completo_de_entrada_e_de_saida_com_wa_id_e_com_lid` e `::test_sem_wa_message_id_devolve_null`; `MessagePolicyTest` com uma linha por regra: cliente e bot só reply/react, outro atendente não edita nem apaga, edição some aos 15 min e 1 s, apagar não tem janela, mensagem apagada não aceita nada, sem permissão `reply` nada passa.
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Services/WahaMessageActionsTest.php tests/Feature/Inbox/MessagePolicyTest.php tests/Unit/MessageFullIdTest.php` → FAIL.
- [ ] **Step 3: Implement** os contratos acima. A política se registra como as demais (`TicketPolicy`): conferir por `codegraph_explore` como o app registra políticas antes de escrever.
- [ ] **Step 4: Run to verify they pass** — mesmo comando → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: acoes de mensagem no WAHA, id completo e politica"`.

### Task 4: Responder (enviar com citação)

**Files:**
- Create: `app/Livewire/Inbox/Concerns/ActsOnMessages.php`
- Modify: `app/Services/Waha/WahaClient.php` (`sendText` com `?string $replyTo = null`), `app/Services/Waha/WahaMedia.php` (`send` com `?string $replyTo = null`), `app/Services/Inbox/SendTicketMessage.php` (`create`/`record`/`transmit`), `app/Services/Inbox/SendTicketMedia.php`, `app/Livewire/Inbox/Concerns/ComposesMessages.php` (`send`, `sendAttachments`), `app/Livewire/Inbox/Inbox.php` (usar a trait), `resources/views/livewire/inbox/inbox.blade.php` (barra de citação) e `transcript.blade.php` (item Responder)
- Test: `tests/Feature/Livewire/InboxReplyTest.php`, `tests/Feature/Inbox/SendQuotedMessageTest.php`

**Interfaces:**
- Consumes: `Message::fullWaId()`, `Quote::snapshot()`, `MessagePolicy::reply`.
- Produces: `ActsOnMessages` com `public ?int $replyToId`, `public function reply(int $messageId): void` (só aceita mensagem **do ticket aberto**, do mesmo canal e autorizada; senão ignora) e `public function cancelReply(): void`; o `Inbox` limpa `replyToId` ao trocar de ticket e depois de enviar.
- Changes: `SendTicketMessage::create(Ticket $ticket, ?User $author, string $text, MessageOrigin $origin = MessageOrigin::PAINEL, ?Message $quoted = null): Message` e `record(..., array $extra = [])` recebem `quoted_wa_message_id` e `quoted_snapshot` por `$extra`; `transmit` passa `reply_to` = `fullWaId()` da citada ao `sendText` e a `WahaMedia::send`. Só a **primeira** mensagem do envio (texto ou primeiro arquivo) leva a citação.

- [ ] **Step 1: Write the failing tests** — `SendQuotedMessageTest::test_texto_citado_envia_reply_to_com_o_id_completo_e_grava_o_trecho`, `::test_anexo_citado_leva_reply_to`, `::test_so_a_primeira_mensagem_do_lote_leva_a_citacao`, `::test_citacao_sem_reply_continua_igual` (nenhum `reply_to` no corpo); `InboxReplyTest::test_responder_mostra_a_barra_e_envia_citado_e_limpa`, `::test_responder_a_mensagem_de_outro_ticket_e_ignorado` (Review Focus 4), `::test_trocar_de_ticket_limpa_a_citacao`, `::test_usuario_sem_reply_nao_cita`.
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Inbox/SendQuotedMessageTest.php tests/Feature/Livewire/InboxReplyTest.php` → FAIL.
- [ ] **Step 3: Implement** os contratos acima; a barra de citação (autor, trecho, ✕) fica acima do campo de resposta; o item Responder do menu da bolha chama `$parent.reply(id)` (o transcript é um componente filho).
- [ ] **Step 4: Run to verify they pass** — mesmo comando, mais `tests/Feature/Inbox tests/Feature/Livewire` → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: responder citando uma mensagem"`.

### Task 5: Reagir

**Files:**
- Create: `app/Services/Inbox/ReactToMessage.php`
- Modify: `app/Livewire/Inbox/Concerns/ActsOnMessages.php`, `resources/views/livewire/inbox/transcript.blade.php` (barra de 6 emojis no menu), `app/Support/TicketTimeline.php` e `app/Support/TicketEventText.php` (verbo de `message_reacted`), `config/security.php` (`message_action_per_minute` = 30)
- Test: `tests/Feature/Inbox/ReactToMessageTest.php`, `tests/Feature/Livewire/InboxReactTest.php`

**Interfaces:**
- Consumes: `WahaMessageActions::react`, `Message::fullWaId()`, `MessagePolicy::react`, `MessageActionFailed`.
- Produces: `ReactToMessage::EMOJIS = ['👍','❤️','😂','😮','😢','🙏']`; `ReactToMessage::__invoke(Message $message, User $actor, string $emoji): void` — emoji fora da lista lança `InvalidArgumentException`; se a reação atual do atendente (`by = 'me'`) é a mesma, manda emoji vazio (remover); grava `TicketEvent` `message_reacted` com `payload {message_id, emoji ou null}` e o autor. **Não** grava `MessageReaction`: quem grava é o eco (`ApplyMessageChange::reaction`).
- Produces: `ActsOnMessages::react(int $messageId, string $emoji): void` — autoriza, aplica o teto `message_action_per_minute` por usuário (`RateLimiter`), chama o serviço e põe o motivo em `$actionError` quando falha.

- [ ] **Step 1: Write the failing tests** — `ReactToMessageTest::test_emoji_da_lista_chama_o_waha_e_grava_o_evento_sem_gravar_a_reacao`, `::test_mesmo_emoji_de_novo_manda_vazio_para_remover`, `::test_emoji_fora_da_lista_e_recusado_sem_chamar_o_waha`, `::test_mensagem_sem_wa_message_id_e_recusada_sem_chamar_o_waha` (Review Focus 1), `::test_recusa_do_waha_nao_grava_evento_nem_altera_o_painel`, `::test_o_eco_da_reacao_do_painel_grava_a_reacao_me` (ingere `message.reaction` `fromMe` e vê `MessageReaction` com `by = 'me'`); `InboxReactTest::test_barra_mostra_os_seis_emojis_e_clicar_reage`, `::test_acima_do_teto_a_acao_e_recusada_com_aviso`, `::test_reagir_a_mensagem_de_outro_ticket_e_ignorado`.
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Inbox/ReactToMessageTest.php tests/Feature/Livewire/InboxReactTest.php` → FAIL.
- [ ] **Step 3: Implement** os contratos acima, e o verbo "reagiu a uma mensagem" na linha do tempo e na auditoria.
- [ ] **Step 4: Run to verify they pass** — mesmo comando, mais `tests/Feature/Timeline` → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: reagir a uma mensagem com seis emojis"`.

### Task 6: Editar e apagar para todos

**Files:**
- Create: `app/Services/Inbox/EditTicketMessage.php`, `app/Services/Inbox/RevokeTicketMessage.php`
- Modify: `app/Livewire/Inbox/Concerns/ActsOnMessages.php`, `resources/views/livewire/inbox/transcript.blade.php` (itens Editar e Apagar, edição na bolha, rótulo "apagada pelo atendente"), `app/Support/TicketTimeline.php` e `app/Support/TicketEventText.php` (verbos de `message_edited` e `message_revoked_by_agent`)
- Test: `tests/Feature/Inbox/EditRevokeMessageTest.php`, `tests/Feature/Livewire/InboxEditRevokeTest.php`

**Interfaces:**
- Consumes: `WahaMessageActions::edit/revoke`, `MessagePolicy::edit/revoke`, `Message::fullWaId()`, `MessageActionFailed`.
- Produces: `EditTicketMessage::__invoke(Message $message, User $actor, string $text): void` — texto aparado, não vazio, diferente do atual e até o limite de mensagem (mesmo `MESSAGE_LIMIT` de `ComposesMessages`); chama o WAHA e grava `TicketEvent` `message_edited` (`{message_id}`); **não** altera `body` (o eco `message.edited` grava a versão anterior em `MessageEdit` e o corpo novo).
- Produces: `RevokeTicketMessage::__invoke(Message $message, User $actor): void` — chama o WAHA e grava `TicketEvent` `message_revoked_by_agent` (`{message_id}`) no clique; **não** marca `revoked_at` (só o eco `message.revoked`).
- Produces: `ActsOnMessages::startEdit(int $messageId)`, `saveEdit()`, `cancelEdit()`, `revoke(int $messageId)` com `public ?int $editingId`, `public string $editText`; mesmas regras de autorização, teto e `$actionError` da Task 5. O transcript mostra "Apagada pelo atendente" quando `revoked_at` existe e a origem é `PAINEL`, e mantém "no celular" para `FORA_DO_PAINEL`.

- [ ] **Step 1: Write the failing tests** — `EditRevokeMessageTest::test_editar_chama_o_waha_grava_evento_e_nao_muda_o_corpo`, `::test_o_eco_da_edicao_grava_a_versao_anterior_e_o_texto_novo`, `::test_editar_aos_15_min_e_1_s_e_recusado_sem_chamar_o_waha` (Review Focus 2), `::test_texto_vazio_igual_ou_acima_do_limite_e_recusado`, `::test_editar_mensagem_de_outro_atendente_ou_do_cliente_e_recusado`, `::test_apagar_chama_o_waha_e_grava_o_evento_sem_marcar_apagada` (Review Focus 3), `::test_o_eco_message_revoked_marca_apagada_e_mantem_o_texto`, `::test_apagar_nao_tem_janela_de_tempo`, `::test_recusa_do_waha_mostra_o_motivo_e_nao_grava_evento`; `InboxEditRevokeTest::test_menu_so_mostra_editar_e_apagar_nas_proprias_do_painel`, `::test_item_editar_some_depois_de_15_min`, `::test_salvar_edicao_e_cancelar`, `::test_rotulo_apagada_pelo_atendente_e_no_celular_para_as_de_fora_do_painel`.
- [ ] **Step 2: Run to verify they fail** — `php artisan test tests/Feature/Inbox/EditRevokeMessageTest.php tests/Feature/Livewire/InboxEditRevokeTest.php` → FAIL.
- [ ] **Step 3: Implement** os contratos acima; a edição acontece na própria bolha (campo, Salvar, Cancelar).
- [ ] **Step 4: Run to verify they pass** — mesmo comando, mais `tests/Feature/Inbox tests/Feature/Timeline` → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat: editar e apagar para todos pelo menu da mensagem"`.

### Task 7: Verificação no beta (portão de decisão, exige deploy)

**Files:**
- Modify: `docs/superpowers/specs/2026-11-03-inbox-completa-design.md` (resultado); `app/Services/Inbox/ReactToMessage.php` e `app/Services/Inbox/EditTicketMessage.php` só no plano B abaixo

- [ ] **Step 1: Deploy no beta** pelo caminho que o dono autorizar (merge nomeado). Nunca tocar a produção.
- [ ] **Step 2: Medir com o contato de teste ((16) 99752-4950), sem mensagem a cliente real:** reagir, remover a reação, editar (dentro de 15 min), editar após 15 min (item some), apagar e responder citando, olhando a tela, o log do app (debug) e o do `waha-beta` pela API do Coolify. Registrar: (a) o id completo é aceito com `@lid`? (b) cada ação volta como evento `fromMe` e a tela muda sozinha? (c) quanto tempo o eco leva? (d) o apagar fora do limite do WhatsApp devolve "ok" sem eco (o painel não marca)?
- [ ] **Step 3: Decidir.** Eco chegou para todas: registrar e seguir. Eco **não** chegou para reação ou edição: plano B, o serviço grava `MessageReaction` (`by = 'me'`, `upsert` como o `ApplyMessageChange`) ou o corpo novo e `MessageEdit` localmente depois do "ok" do WAHA (idempotente com um eco que chegue depois), com RED/GREEN próprio. Eco **não** chegou para o apagar: **parar e voltar ao dono**.
- [ ] **Step 4: Commit** — `git commit -m "docs: resultado da verificacao do menu da mensagem no beta"` (e o plano B, se houve).
