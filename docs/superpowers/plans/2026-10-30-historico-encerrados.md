# Histórico de tickets encerrados: plano de implementação

> **Para quem executa:** execução inline na sessão (regra do dono em `AGENTS.md`), com TDD em todas as tarefas e a skill
> `superpowers:executing-plans`. Os passos usam caixas `- [ ]`.

**Objetivo:** o usuário busca e lê os tickets encerrados que pode ver, numa tela `/historico` e numa aba "Encerrados" do Inbox.

**Arquitetura:** um escopo de visibilidade novo (`inHistoryOf`) alimenta a policy `view`, o Inbox e a tela nova; um serviço
`HistorySearch` monta a consulta; a transcrição vira um parcial Blade usado pelo Inbox e pelo Histórico; os rótulos de evento saem
da tela de auditoria para uma classe comum.

**Stack:** Laravel 13, Livewire 4, Flux, PostgreSQL 18 (`pg_trgm`), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-30-historico-encerrados-design.md`. Medição da busca:
`docs/superpowers/notes/2026-10-30-busca-de-texto-benchmark.md`.

## Restrições globais

- Textos de tela em pt-BR. Dia/hora exibidos no fuso `config('app.display_timezone')` (Brasília); o banco guarda em `config('app.timezone')`.
- Aba "Encerrados": últimos **7 dias**, até **50** linhas. Tela `/historico`: **25** por página, período padrão **30 dias**.
- Busca no texto das mensagens só com termo de **3 caracteres ou mais**; `%`, `_` e `\` do termo valem como texto.
- Transcrição mostra as últimas **200** mensagens (como o Inbox) e avisa quando há mais.
- Sem reabrir, sem exportação, sem PDF. Nada de PR (regra do dono); commits no branch `feat/atendimento-completo`, em português com prefixo.
- Testes precisam do Postgres de teste no ar (comando em `AGENTS.md`). Qualidade final: `composer test`.

## Foco da revisão (o que a spec implica e nenhuma tarefa óbvia cobre)

1. Atendente abre pela URL a mídia de um ticket encerrado de outro setor: tem de ser 403 (Tarefa 1 e 5).
2. Termo com `%`, `_`, `\` ou aspas: busca literal, sem erro de SQL (Tarefa 2).
3. Ticket com várias mensagens que casam aparece uma vez só na lista (Tarefa 2).
4. Usuário inativo e atendente sem setor nem vínculo: lista vazia, não erro (Tarefa 1).
5. Encerrado às 23h30 de Brasília cai no dia certo no filtro de período (Tarefa 2).

## Mapa de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `app/Models/Ticket.php` (modificar) | escopos `inHistoryOf` e `readableBy` |
| `app/Policies/TicketPolicy.php` (modificar) | `view` usa `readableBy` |
| `database/migrations/2026_10_30_000001_add_trigram_index_to_messages_body.php` (criar) | extensão e índice GIN |
| `app/Services/Inbox/HistorySearch.php` (criar) | consulta filtrada de tickets encerrados |
| `app/Support/TicketEventText.php` (criar) | rótulos e detalhe dos eventos |
| `app/Livewire/Audit/Index.php` (modificar) | passa a usar `TicketEventText` |
| `resources/views/livewire/inbox/transcript.blade.php` (criar) | laço de mensagens, com modo leitura |
| `resources/views/livewire/inbox/inbox.blade.php`, `app/Livewire/Inbox/Inbox.php` (modificar) | usa o parcial; aba Encerrados; leitura |
| `app/Livewire/History/Index.php`, `resources/views/livewire/history/index.blade.php` (criar) | tela `/historico` |
| `routes/web.php`, `resources/views/layouts/app/sidebar.blade.php` (modificar) | rota `historico` e item de menu |
| `tests/Feature/History/*` (criar) | testes das tarefas |

---

### Tarefa 1: visibilidade dos encerrados

**Arquivos:** modificar `app/Models/Ticket.php`, `app/Policies/TicketPolicy.php`; testar em `tests/Feature/History/HistoryAccessTest.php`.

**Interfaces:**
- Produz: `Ticket::scopeInHistoryOf(Builder $query, User $user): void` e `Ticket::scopeReadableBy(Builder $query, User $user): void`.
- Regra de `inHistoryOf`: só `status = encerrado`; usuário inativo, nada (`whereRaw('false')`); `canSeeAllSectors()`, todos; atendente: `assigned_user_id = ele` ou `sector_id` em `$user->sectors()`.
- `readableBy`: inativo, nada; `canSeeAllSectors()`, sem restrição; atendente: `visibleTo` **ou** `inHistoryOf` em grupo `where(fn)`. Não montar o OR chamando os dois escopos para supervisor: o escopo `visibleTo` dele não adiciona condição e o grupo vazio some, o que quebra o `orWhere`.

- [ ] **Passo 1: testes que falham** (`HistoryAccessTest`, `RefreshDatabase`, tickets com `Ticket::factory()` e `status` `ENCERRADO`):
  - `test_atendente_ve_encerrado_do_proprio_setor_e_o_que_atendeu`
  - `test_atendente_nao_ve_encerrado_de_outro_setor`
  - `test_encerrado_sem_setor_so_aparece_para_supervisor_e_admin` (e para o atendente que o atendeu)
  - `test_usuario_inativo_nao_ve_nada`
  - `test_ticket_aberto_nao_entra_em_in_history_of`
  - `test_policy_view_aceita_encerrado_do_historico_e_a_midia_responde_403_fora_do_escopo` (GET `route('midia', $message)` com `Storage::fake('local')` e `local_path` gravado: 200 dentro do escopo, 403 fora)
- [ ] **Passo 2:** `php artisan test --filter=HistoryAccessTest` — esperado: FALHA (escopo inexistente).
- [ ] **Passo 3:** implementar os dois escopos em `Ticket` e trocar o corpo de `TicketPolicy::view` para `Ticket::query()->readableBy($user)->whereKey($ticket->id)->exists()`.
- [ ] **Passo 4:** `php artisan test --filter=HistoryAccessTest` e depois `php artisan test tests/Feature/Inbox tests/Feature/Livewire` — esperado: tudo passa (nenhuma regra existente muda para ticket aberto).
- [ ] **Passo 5:** commit `feat: visibilidade dos tickets encerrados (inHistoryOf e readableBy)`.

### Tarefa 2: busca dos encerrados

**Arquivos:** criar a migração e `app/Services/Inbox/HistorySearch.php`; testar em `tests/Feature/History/HistorySearchTest.php`.

**Interfaces:**
- Consome: `Ticket::scopeInHistoryOf`, `Contact::scopeSearch(string)`.
- Produz: `HistorySearch::query(User $user, string $q = '', ?string $from = null, ?string $to = null, ?int $sectorId = null, ?int $userId = null, ?string $outcome = null): Builder` (de `Ticket`), com `HistorySearch::MIN_TEXT = 3`.
- Semântica: base `inHistoryOf($user)`. `$q` casa contato (`whereHas('contact', fn => search($q))`), **ou** protocolo (`ilike`), **ou**, com `mb_strlen >= MIN_TEXT`, `exists` em `messages` por `ticket_id` com `body ilike` e `%`, `_`, `\` escapados (mesmo padrão de `Contact::scopeSearch`). `$from`/`$to` são `Y-m-d` de Brasília: `closed_at >= início do dia de $from` e `<= fim do dia de $to`, convertidos para `config('app.timezone')`. `$userId` só vale se `$user->canSeeAllSectors()`. `$outcome` é um valor de `CloseOutcome`; valor desconhecido é ignorado. Ordem `closed_at desc, id desc`.
- Migração: `create extension if not exists pg_trgm` e `create index messages_body_trgm on messages using gin (body gin_trgm_ops)`; `down` remove só o índice.

- [ ] **Passo 1: testes que falham** (`HistorySearchTest`):
  - `test_acha_por_nome_do_contato_telefone_e_protocolo`
  - `test_acha_por_texto_da_mensagem_e_nao_duplica_com_varias_mensagens_que_casam`
  - `test_termo_curto_nao_busca_no_texto`
  - `test_percent_underscore_e_barra_valem_como_texto` (mensagens "100% ok" e "a_b"; termo `100%`, `a_b` e `\`)
  - `test_periodo_usa_o_dia_de_brasilia` (ticket encerrado `2026-10-30 02:30 UTC` = 23h30 do dia 29 em Brasília: entra em `from=to=2026-10-29`, não em `2026-10-30`)
  - `test_filtros_de_setor_atendente_e_motivo`
  - `test_filtro_de_atendente_e_ignorado_para_atendente`
  - `test_a_migracao_cria_o_indice_trigrama` (consulta `pg_indexes` por `messages_body_trgm`)
- [ ] **Passo 2:** `php artisan test --filter=HistorySearchTest` — esperado: FALHA.
- [ ] **Passo 3:** criar a migração e implementar `HistorySearch` conforme a semântica acima.
- [ ] **Passo 4:** `php artisan test --filter=HistorySearchTest` — esperado: PASSA.
- [ ] **Passo 5:** commit `feat: busca dos tickets encerrados com indice de trigramas`.

### Tarefa 3: rótulos de evento e parcial da transcrição

**Arquivos:** criar `app/Support/TicketEventText.php` e `resources/views/livewire/inbox/transcript.blade.php`; modificar `app/Livewire/Audit/Index.php`, `resources/views/livewire/inbox/inbox.blade.php`; testes existentes de auditoria e Inbox são a rede de segurança.

**Interfaces:**
- Produz: `TicketEventText::TYPES` (mesmo mapa de `Audit\Index::TYPES`), `TicketEventText::label(string $type): string`, `TicketEventText::detail(TicketEvent $event): string` (corpo atual de `Audit\Index::detail`, movido sem mudar comportamento).
- `Audit\Index::typeLabel()` e `detail()` continuam existindo e delegam a `TicketEventText` (as views de auditoria os chamam).
- Parcial `livewire.inbox.transcript` recebe `$messages` e `$readonly` (bool). Com `readonly = true` não renderiza o botão Reenviar nem o bloco "Aguardando o WhatsApp reconectar". O resto do laço (bolhas, mídia, selos, hora) é o do `inbox.blade.php` hoje, movido sem alteração.

- [ ] **Passo 1:** rodar `php artisan test --filter=Audit` e `php artisan test tests/Feature/Inbox tests/Feature/Livewire` — esperado: PASSA (linha de base).
- [ ] **Passo 2: teste que falha** em `tests/Feature/History/TranscriptTest.php`: `test_modo_leitura_nao_mostra_reenviar` (renderiza `view('livewire.inbox.transcript', ['messages' => ..., 'readonly' => true])` com mensagem de saída que falhou: não contém "Reenviar"; com `readonly => false` contém) e `test_event_text_rotula_e_detalha_o_encerramento` (`closed` com `outcome` e `summary`).
- [ ] **Passo 3:** criar `TicketEventText`, fazer `Audit\Index` delegar, extrair o parcial e incluí-lo no Inbox com `readonly => false`.
- [ ] **Passo 4:** repetir os comandos do Passo 1 e o teste novo — esperado: tudo PASSA, sem mudança de comportamento no Inbox e na auditoria.
- [ ] **Passo 5:** commit `refactor: transcricao em parcial e rotulos de evento compartilhados`.

### Tarefa 4: aba "Encerrados" no Inbox

**Arquivos:** modificar `app/Livewire/Inbox/Inbox.php` e `resources/views/livewire/inbox/inbox.blade.php`; testar em `tests/Feature/History/InboxClosedTabTest.php`.

**Interfaces:**
- Consome: `Ticket::scopeInHistoryOf`, `Ticket::scopeReadableBy`.
- No `Inbox`: `selected()` e `find()` passam de `visibleTo` para `readableBy` (assim um encerrado do histórico abre; as ações continuam barradas pelas policies, que negam ticket encerrado). A lista (`render`) continua com `open()->visibleTo()`. Nova aba `'encerrados' => 'Encerrados'` em `$tabs`; com `tab = encerrados` a lista é `inHistoryOf` com `closed_at >= now()->subDays(7)`, `closed_at desc`, `limit(50)`, e a view lista essas linhas (contato, protocolo, hora de encerramento, motivo) no lugar dos grupos Aguardando/Em atendimento, com o link "Busca completa" para `route('historico')`.
- No ticket encerrado aberto: o cabeçalho mostra o motivo e o resumo do encerramento; o parcial recebe `readonly => ! $canReply`.

- [ ] **Passo 1: testes que falham** (`InboxClosedTabTest`):
  - `test_aba_encerrados_lista_so_os_ultimos_7_dias_e_so_os_visiveis` (6 dias entra, 8 não, outro setor não)
  - `test_limita_a_50_linhas`
  - `test_selecionar_encerrado_abre_a_conversa_sem_compositor_e_mostra_motivo_e_resumo`
  - `test_acoes_continuam_negadas_em_encerrado` (`claim`, `close`, `send` respondem 403 ou aviso, sem alterar nada)
  - `test_atendente_nao_seleciona_encerrado_de_outro_setor` (403)
  - `test_aba_tem_link_para_a_busca_completa`
- [ ] **Passo 2:** `php artisan test --filter=InboxClosedTabTest` — esperado: FALHA.
- [ ] **Passo 3:** implementar na ordem: `selected()`/`find()` com `readableBy`, aba e lista, cabeçalho de encerrado.
- [ ] **Passo 4:** `php artisan test --filter=InboxClosedTabTest` e `php artisan test tests/Feature/Inbox tests/Feature/Livewire` — esperado: PASSA.
- [ ] **Passo 5:** commit `feat: aba Encerrados no Inbox`.

### Tarefa 5: tela `/historico`

**Arquivos:** criar `app/Livewire/History/Index.php`, `resources/views/livewire/history/index.blade.php`, `tests/Feature/History/HistoryPageTest.php`; modificar `routes/web.php` (rota `historico`, mesmo grupo `auth` + `verified` de `contatos`) e `resources/views/layouts/app/sidebar.blade.php` (item "Histórico" com ícone `archive-box`, logo depois de Contatos, visível para todos os papéis).

**Interfaces:**
- Consome: `HistorySearch::query`, `TicketEventText::label/detail`, parcial `livewire.inbox.transcript` (`readonly => true`), `Ticket::scopeReadableBy`.
- `App\Livewire\History\Index` (`#[Title('Histórico')]`, `WithPagination`): propriedades públicas com `#[Url]`: `q` (string), `from`, `to` (`Y-m-d`, padrão: hoje-30 e hoje no fuso de Brasília), `sectorId`, `userId`, `outcome`; `?int $ticketId`. Métodos: `select(int $ticketId): void` (`authorize('view', Ticket::findOrFail(...))`), `updating*` que fazem `resetPage()`. `render()` devolve a página de 25 (com `contact`, `sector`, `assignee` carregados), a lista de setores e, só para quem `canSeeAllSectors()`, a de atendentes, e, com um ticket selecionado, as últimas 200 mensagens, os eventos em ordem cronológica e o aviso quando houver mais de 200.
- Antes de montar a view: abrir o frame "Ticket encerrado" de `Dimetal.pen` pelas ferramentas do Pencil e seguir o layout; anotar no commit o que foi diferente.

- [ ] **Passo 1: testes que falham** (`HistoryPageTest`):
  - `test_exige_login` (302 para visitante) e `test_todos_os_papeis_entram`
  - `test_lista_so_o_que_o_usuario_pode_ver_e_ordena_por_encerramento`
  - `test_busca_filtra_por_contato_protocolo_e_texto` e `test_filtros_de_periodo_setor_e_motivo`
  - `test_filtro_de_atendente_so_aparece_para_supervisor_e_admin`
  - `test_selecionar_mostra_transcricao_eventos_e_resumo_sem_reenviar_nem_compositor`
  - `test_ticket_de_outro_setor_responde_403_ao_selecionar_e_na_midia`
  - `test_avisa_quando_a_conversa_tem_mais_de_200_mensagens`
  - `test_anexo_expirado_aparece_como_expirado`
  - `test_a_lista_nao_faz_consulta_por_linha` (conta consultas com 3 e com 20 tickets: o número é o mesmo)
- [ ] **Passo 2:** `php artisan test --filter=HistoryPageTest` — esperado: FALHA.
- [ ] **Passo 3:** implementar a rota, o componente, a view e o item de menu.
- [ ] **Passo 4:** `php artisan test --filter=HistoryPageTest` — esperado: PASSA.
- [ ] **Passo 5:** commit `feat: tela de historico dos tickets encerrados`.

### Tarefa 6: verificação final

- [ ] **Passo 1:** `composer test` (Pint, Larastan, PHPMD e a suíte inteira) — esperado: tudo verde; qualquer falha, mesmo antiga, vai no relatório pelo nome.
- [ ] **Passo 2:** revisão do branch contra a spec (skill `requesting-code-review`), com foco nas cinco linhas de "Foco da revisão"; corrigir o que for crítico.
- [ ] **Passo 3:** atualizar a memória do projeto (`pendencias-seguranca-e-proximos-passos`) com o que ficou pronto e o que sobrou, e commit `docs: ...` se houver mudança em `docs/`.
