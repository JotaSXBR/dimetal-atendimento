# Card da Fila: etiquetas e atendente Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, regra do dono). Steps use checkbox (`- [ ]`) syntax.

**Goal:** O card da Fila mostra até 6 etiquetas (acima disso "+N") e o atendente passa para a coluna da direita.

**Architecture:** Só Blade: `resources/views/livewire/inbox/inbox.blade.php` (o card). Nada de PHP novo; os testes são os de `InboxQueueCardTest`.

**Tech Stack:** Laravel 13, Livewire 4, Blade, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-14-card-da-fila-etiquetas-e-atendente-design.md`

## Global Constraints

- Teto de etiquetas no card: **6** (`$maxChips = 6`); acima, `+N` com a lista completa no tooltip e no texto para leitor de tela, como hoje.
- Ordem das etiquetas: as do contato primeiro (por nome), depois as da conversa (por nome), como hoje.
- Atendente: avatar `size="xs"` decorativo e o **primeiro nome** em `data-testid="card-assignee-name"`, `max-w-16 truncate`, com o nome completo em `title`.
- `$hasMeta` depende só de `$showChannel`; sem canal a mostrar, a linha `card-meta` não existe.
- Os chips de `x-contact-chips` (Contatos, Histórico) seguem com 3.
- Commits em português com prefixo, trailer `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`; editar com `Edit`; Blade se busca com `grep` pelo Bash.
- Testes: `docker start pg-test` antes; `docker stop pg-test` no fim. RED visto antes de cada código.

## Review Focus

- Exatamente 6 etiquetas: todas aparecem e não há `+N`; 7: aparece `+1`.
- O chip do contato continua antes dos da conversa.
- Ticket sem atendente: nada na coluna da direita e nenhuma linha `card-meta`.
- Com dois canais ativos a linha `card-meta` traz só o canal, sem o atendente.
- Nome longo ou composto: só o primeiro nome aparece (truncado) e o nome completo fica no `title`.

---

### Task 1: Até 6 etiquetas no card

**Files:**
- Modify: `resources/views/livewire/inbox/inbox.blade.php` (bloco `@php` do card e o `@foreach ($chipsShown ...)`)
- Test: `tests/Feature/Livewire/InboxQueueCardTest.php`

**Interfaces:**
- Produces: `$maxChips = 6` no `@php` do card; `$chipsShown = $chips->take($maxChips)`, `$chipsRest = $chips->slice($maxChips)`.

- [ ] **Step 1: Testes que falham.** Trocar `test_mostra_ate_tres_chips_com_o_do_contato_primeiro_e_mais_n` por `test_mostra_ate_seis_chips_com_o_do_contato_primeiro_e_mais_n` (1 etiqueta do contato "Revenda" + 7 da conversa `Alfa, Beta, Cama, Delta, Eta, Fox, Gama`: aparecem 6 chips, 1 do contato e 5 da conversa; `card-tags-more` contém `+2`; o do contato vem antes). Novos: `test_com_exatamente_seis_etiquetas_nao_aparece_o_mais_n` (6 etiquetas: 6 chips e `data-testid="card-tags-more"` ausente) e `test_com_sete_etiquetas_aparece_mais_1`. Ajustar `test_mais_n_diz_os_nomes_das_etiquetas_escondidas_a_quem_nao_ve_o_title` e `test_title_do_mais_n_fica_no_trecho_visual_e_nao_duplica_o_texto_do_leitor_de_tela` para 8 etiquetas da conversa (`Alfa, Beta, Cama, Delta, Eta, Fox, Gama, Hotel`), escondidas `Gama, Hotel` (no lugar de `Delta, Eta`).
- [ ] **Step 2: Rodar e ver falhar.** Run: `docker start pg-test; php artisan test tests/Feature/Livewire/InboxQueueCardTest.php`. Expected: FAIL nos testes novos e ajustados (o teto ainda é 3).
- [ ] **Step 3: Implementar** no `@php` do card: `$maxChips = 6;` e usar `take($maxChips)` / `slice($maxChips)`.
- [ ] **Step 4: Rodar e ver passar**, mais `tests/Feature/Livewire/InboxTest.php`. Expected: PASS (inclusive o teste de "sem consulta por ticket").
- [ ] **Step 5: Commit** `feat: card da Fila mostra até 6 etiquetas antes do +N`.

### Task 2: Atendente na coluna da direita

**Files:**
- Modify: `resources/views/livewire/inbox/inbox.blade.php` (o `card-meta` e a coluna `flex shrink-0 flex-col items-end`)
- Test: `tests/Feature/Livewire/InboxQueueCardTest.php`

**Interfaces:**
- Consumes: `$item->assignee` (já carregado em `with`), `$showChannel`, `$showSector`, `$unread`.
- Produces: na coluna da direita, na ordem hora, setor, atendente, não lidas; `data-testid="card-assignee-name"` (o mesmo de hoje) com `title` e `max-w-16 truncate`.

- [ ] **Step 1: Testes que falham.** `test_atendente_fica_na_coluna_da_direita_entre_o_setor_e_as_nao_lidas` (2 setores para o setor aparecer, ticket com atendente e mensagem do cliente não lida; as posições no HTML crescem: `card-time` < `card-sector` < `card-assignee-name` < `card-unread`, e `card-meta` ausente com um só canal); `test_com_dois_canais_a_linha_de_baixo_tem_so_o_canal` (dois canais ativos, ticket com atendente: via `DOMXPath`, `//*[@data-testid="card-meta"]//*[@data-testid="card-assignee-name"]` não existe e `card-meta` contém o nome do canal); `test_atendente_mostra_so_o_primeiro_nome_truncado_e_o_nome_completo_no_title` (nome "Marina Souza Albuquerque": texto `Marina`, `title` do elemento `Marina Souza Albuquerque`, classe com `truncate`); `test_ticket_sem_atendente_nao_tem_o_atendente_na_coluna` (sem `card-assignee-name`).
- [ ] **Step 2: Rodar e ver falhar.** Run: `php artisan test tests/Feature/Livewire/InboxQueueCardTest.php`. Expected: FAIL nos três primeiros (hoje o atendente está dentro de `card-meta`).
- [ ] **Step 3: Implementar.** Tirar o bloco do atendente do `card-meta` e pô-lo na coluna da direita, depois do setor e antes das não lidas: `<span class="inline-flex max-w-24 items-center gap-1 text-[10px] text-ink-2">` com `<x-avatar ... size="xs" decorative />` e `<span data-testid="card-assignee-name" title="{{ $item->assignee->name }}" class="max-w-16 truncate">{{ Str::before(...) }}</span>`. `$hasMeta = $showChannel;`.
- [ ] **Step 4: Rodar e ver passar**, mais `tests/Feature/Livewire/InboxTest.php tests/Feature/Livewire/InboxChannelBadgeTest.php tests/Feature/Layout`. Expected: PASS.
- [ ] **Step 5: Commit** `feat: atendente do card da Fila na coluna da direita`.

### Task 3: A hora não herda o `pre-wrap` do texto (adendo)

**Files:** `resources/views/livewire/inbox/message-time.blade.php`; teste em `tests/Feature/Livewire/InboxMediaTest.php`.

- [ ] Teste `test_a_hora_nao_herda_o_pre_wrap_do_texto_em_nenhum_modo` (classe `whitespace-nowrap` no trecho da hora em texto, foto e áudio): RED, depois `whitespace-nowrap` na lista base de classes. Commit `fix: hora e tiques da bolha sem herdar o pre-wrap do texto`.

### Task 4: Áudio em duas linhas, com a velocidade depois da onda (adendo)

**Files:** `resources/views/livewire/inbox/audio-player.blade.php`, `message-time.blade.php`, `media.blade.php`, `transcript.blade.php`; testes em `tests/Feature/Livewire/AudioPlayerLayoutTest.php` e `InboxMediaTest.php`.

**Interfaces:** `$timeMode = 'audio'` (transcript) quando a mensagem é mídia de áudio com arquivo e sem legenda; `message-time` ganha o modo `audio` (`absolute end-0 bottom-0 inline-flex text-ink-2`); o player vira `grid-cols-[auto_minmax(0,1fr)_auto]` com o botão de velocidade em `row-start-1 col-start-3`.

- [ ] Testes que falham: `AudioPlayerLayoutTest` (3 colunas; a velocidade fica na linha 1, coluna 3, depois da onda e fora de `audio-meta`; `audio-meta` só com a duração); `InboxMediaTest` (áudio tem `data-time-mode="audio"` e documento segue `below`; a hora do áudio existe uma vez, dentro de `audio-bubble`).
- [ ] Implementar e rodar `InboxMediaTest`, `AudioPlayerLayoutTest`, `InboxTest`, `tests/Feature/History`. Commit `feat: áudio em duas linhas com a velocidade depois da onda e a hora na linha da duração`.
