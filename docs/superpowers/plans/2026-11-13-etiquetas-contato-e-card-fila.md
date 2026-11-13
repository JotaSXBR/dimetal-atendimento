# Etiquetas do contato, card da Fila e marca neutra: plano de implementação

> **Para quem executa:** execução inline (decisão do dono, `CLAUDE.md`), com `superpowers:executing-plans`. Os passos usam caixas (`- [ ]`). Cada tarefa tem RED visto falhando antes do código (`superpowers:test-driven-development`).

**Meta:** etiqueta também no contato (catálogo com escopo), card novo da Fila (avatar com ícone do canal, chips, coluna da direita com setor, tempo e não lidas), tipo de canal, automação por escopo e marca neutra.

**Arquitetura:** `tags.scope` (`contato`|`conversa`) mais o pivô `contact_tag`; `tickets.agent_seen_at` para as não lidas calculadas; `channels.kind`; cor por paleta fixa (enum `TagColor`, estilos em CSS por tom); marca neutra pela troca dos tokens `accent*` e `warn` em `app.css`. Aplicar etiqueta no contato usa só `attach`/`detach`, sem tocar `updated_at`/`edited_at` e sem disparar o Google.

**Stack:** Laravel 13, Livewire 4.4, Flux 2, Alpine (build CSP), Tailwind 4, PostgreSQL. Dúvida de API: Context7 (IDs em `CLAUDE.md`).

**Spec:** `docs/superpowers/specs/2026-11-12-etiquetas-contato-e-card-fila-design.md` (aprovada em 13/11).

## Restrições globais

- Só o beta; branch `feat/etiquetas-contato-card-fila`; commits em português com prefixo e a linha `Co-Authored-By` do harness; **sem PR** sem pedido do dono.
- UI alinhada em 360 px, 720p, 1080p e 2K, tema claro e escuro; contraste AA (`ThemeTokensTest`); `AlpineCspSubsetTest` e `ViewConventionsTest` continuam verdes.
- Nome de etiqueta único global (índice atual de `tags.name`); escopo não muda se a etiqueta está em uso; apagar remove os dois pivôs.
- Etiqueta do contato: quem edita o contato (ficha não somente leitura) aplica e remove; criar/editar/apagar do catálogo continua `supervisor`.
- Selo do setor só com mais de um setor recebendo tickets; selo do número só com mais de um número ativo (regras de hoje).
- Não lidas: `agent_seen_at` só é preenchido pelo responsável do ticket (ou por quem abre, se não há responsável); quem só espia não zera.
- Textos da interface em português; verde/âmbar/vermelho só para estado e alerta.
- Ao tocar em `app/`, rodar os afetados: `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test`. Blade/CSS: rodar o teste do componente. Portão 7 só no fim.

## Review Focus

Entradas e falhas que a spec implica e que mais podem incomodar (cada linha tem o teste na tarefa dona):

1. Contato mesclado (lid → telefone) com etiquetas nos dois lados: a união fica no telefone, sem duplicar a linha do pivô (Tarefa 6).
2. Apagar uma etiqueta ou um contato com vínculos: nenhuma linha órfã em `contact_tag` (Tarefa 3).
3. Trocar o escopo de uma etiqueta em uso: recusado com mensagem (Tarefa 4).
4. Ticket sem nenhuma mensagem do cliente, ou com `agent_seen_at` nulo: contador 0 / total das mensagens do cliente, sem erro (Tarefa 7).
5. Etiquetar (nó antigo) com `tag_id` de etiqueta apagada ou de escopo contato num ticket sem contato válido: o fluxo segue sem exceção (Tarefa 9).

---

### Tarefa 1: Marca neutra e aviso em âmbar

**Files:**
- Modify: `resources/css/app.css` (tokens `accent`, `accent-content`, `accent-foreground`, `accent-soft`, `warn`, `warn-foreground`, claro e `.dark`; comentários)
- Modify: `resources/views/layouts/app/sidebar.blade.php`, `resources/views/welcome.blade.php` (trocar o amarelo direto)
- Modify: `tests/Unit/ThemeTokensTest.php` (pares novos)
- Test: `tests/Unit/ThemeTokensTest.php`

**Interfaces:**
- Consumes: tokens atuais de `app.css`.
- Produces: tokens `accent*` neutros (claro: `accent`/`accent-content` = stone-800 `#292524`, `accent-foreground` = `#ffffff`; escuro: `accent`/`accent-content` = `#ffffff`, `accent-foreground` = `#292524`), `accent-soft` (claro `#f5f5f4` stone-100; escuro `#292524`, ajustar se o contraste do texto falhar) e `warn` âmbar (claro `#92400e` amber-800 ou outro que passe AA como texto sobre `warn/10` e sobre a superfície; escuro `#fbbf24` amber-400, `warn-foreground` com contraste ≥ 4,5 sobre o `warn` de cada tema). Tarefas 4 a 8 usam essas classes (`bg-accent`, `text-accent-foreground`).

- [ ] **Step 1: RED.** No `ThemeTokensTest`, assertar que `accent`/`accent-content` do claro são `#292524` e do escuro `#ffffff`, que o `warn` não é mais `#9a3412` nem `#fb923c`, e que os pares existentes (texto sobre `accent`, `warn` sobre `warn/10`, `accent-content` sobre `canvas` e `surface`) passam AA (≥ 4,5) nos dois temas. Rodar: `php artisan test tests/Unit/ThemeTokensTest.php` → FALHA (valores antigos).
- [ ] **Step 2: Implementar** a troca dos tokens em `app.css` (claro e `.dark`), os comentários que dizem "marca amarela", e as classes `yellow-*`/`amber-*` diretas em `sidebar.blade.php` e `welcome.blade.php` para os tokens (`text-accent-content`, `bg-accent`, etc.). Descobrir os pontos com `grep -rn "yellow\|amber" resources/views` (Blade: grep pelo Bash).
- [ ] **Step 3: GREEN.** `php artisan test tests/Unit/ThemeTokensTest.php tests/Unit/ViewConventionsTest.php` → PASSA. Conferir no navegador, claro e escuro, que botão primário, item ativo do menu, anel de foco e o editor de automação (`.flow-node.is-selected`) ficam legíveis.
- [ ] **Step 4: Commit** `feat: marca neutra (stone/branco) e aviso em âmbar`.

---

### Tarefa 2: Paleta fixa de etiquetas (`TagColor`)

**Files:**
- Create: `app/Enums/TagColor.php`
- Modify: `resources/css/app.css` (uma classe `.tag-<tom>` por tom, com `--tag-bg`, `--tag-fg`, `--tag-line` no claro e no `.dark`)
- Create: `resources/views/components/tag-chip.blade.php`
- Test: `tests/Unit/TagColorTest.php`, `tests/Unit/ThemeTokensTest.php`

**Interfaces:**
- Produces: `enum TagColor: string` com ~12 tons (sugestão: `azul`, `ceu`, `anil`, `violeta`, `fucsia`, `rosa`, `turquesa`, `ciano`, `ardosia`, `pedra`, `marrom`, `grafite`; nenhum verde, âmbar nem vermelho de estado), `label(): string`, `swatch(): string` (hex do tom claro, para o seletor), `static nearest(string $hex): self` (menor distância RGB), `static default(): self` (`ardosia`). Componente `<x-tag-chip :tag="$tag" :outline="bool" />` (chip sólido do contato, contorno da conversa; texto `max-w-24 truncate`; o `title` fica por conta de quem agrupa).
- Tarefas 3, 4, 5, 7 e 8 usam `TagColor` e `<x-tag-chip>`.

- [ ] **Step 1: RED.** `TagColorTest`: `nearest('#2F855A')` devolve um tom definido (verde antigo vira o mais próximo); `nearest` de cada `swatch()` devolve o próprio tom; `tryFrom('azul')` existe e `tryFrom('#2F855A')` é `null`. No `ThemeTokensTest`, para cada tom, o par `--tag-fg` sobre `--tag-bg` passa AA nos dois temas e o `--tag-line` do contorno passa 3:1 sobre `surface` (leitura do `app.css` pelo mesmo helper `hexes`, adaptado a `--tag-*`). Rodar → FALHA (enum e CSS não existem).
- [ ] **Step 2: Implementar** o enum, as classes CSS (valores desenhados aqui; conferir contra o teste) e o componente. O componente aplica `class="tag-{{ $tag->color->value }}"` e usa `bg-[var(--tag-bg)] text-[var(--tag-fg)]` (sólido) ou `border` na `--tag-line` com texto `text-ink-2` (contorno). Sem estilo inline com cor livre.
- [ ] **Step 3: GREEN** nos dois arquivos de teste; `php artisan test tests/Unit/ViewConventionsTest.php`.
- [ ] **Step 4: Commit** `feat: paleta fixa de etiquetas (TagColor) e componente tag-chip`.

---

### Tarefa 3: Dados: escopo da etiqueta, pivô do contato, canal e não lidas

**Files:**
- Create: `database/migrations/2026_11_13_000001_add_scope_and_palette_to_tags.php`, `2026_11_13_000002_create_contact_tag_table.php`, `2026_11_13_000003_add_agent_seen_at_to_tickets.php`, `2026_11_13_000004_add_kind_to_channels.php`
- Create: `app/Enums/TagScope.php`, `app/Enums/ChannelKind.php`
- Modify: `app/Models/Tag.php`, `app/Models/Contact.php`, `app/Models/Ticket.php`, `app/Models/Channel.php`, `database/factories/TagFactory.php`
- Test: `tests/Feature/Models/TagScopeTest.php`, `tests/Unit/ChannelKindTest.php`

**Interfaces:**
- Produces: `enum TagScope: string { CONTATO = 'contato'; CONVERSA = 'conversa'; label(): string }`; `enum ChannelKind: string { WHATSAPP = 'whatsapp'; label(): string; icon(): string }` (ícone Flux/SVG para o avatar); `Tag::$scope` (cast `TagScope`, padrão `conversa`), `Tag::$color` (cast `TagColor`), `Tag::contacts(): BelongsToMany`, `Tag::scopeOfContact(Builder)`/`scopeOfTicket(Builder)`; `Contact::tags(): BelongsToMany` (pivô `contact_tag`, sem timestamps, **sem `touches`**); `Ticket::agent_seen_at` (cast `datetime`); `Channel::$kind` (cast `ChannelKind`, padrão `whatsapp`, no `Fillable`).
- Migrações: `tags.scope` string padrão `conversa` + converter `tags.color` de `#RRGGBB` para o nome do tom com `TagColor::nearest` (reversível: `down()` grava o `swatch()` de volta); `contact_tag(contact_id, tag_id)` com `foreignId` `cascadeOnDelete` nos dois e chave única composta; `tickets.agent_seen_at` timestamp nulo; `channels.kind` string padrão `whatsapp`. Índice para a subconsulta de não lidas em `messages(ticket_id, direction, sent_at)` se o plano de consulta do Postgres não usar o `(ticket_id, sent_at)` atual (conferir com `EXPLAIN` no teste de carga leve da Tarefa 7; criar o índice só se preciso).

- [ ] **Step 1: RED.** `TagScopeTest`: etiqueta nova tem escopo `conversa`; `$contact->tags()->attach($tag)` grava em `contact_tag` e **não muda** `contacts.updated_at` nem `edited_at`; apagar a etiqueta e apagar o contato removem as linhas do pivô (zero órfãs); duplicar o mesmo par falha. `ChannelKindTest`: padrão `whatsapp`, `icon()` não vazio. Rodar → FALHA.
- [ ] **Step 2: Implementar** migrações, enums, modelos e factory (`TagFactory` usa `TagColor::default()` e `TagScope::CONVERSA`; estado `->contato()`).
- [ ] **Step 3: Adaptar o que a troca de `color` quebra** (os usos de `$tag->color` em Blade e testes: `inbox.blade.php`, `tags.blade.php`; testes que passam `#2F855A`): rodar `git status --porcelain | cut -c4- | codegraph affected --stdin -d 2 --quiet | xargs -r php artisan test` e corrigir o que falhar (as telas em si são refeitas nas Tarefas 4 e 8).
- [ ] **Step 4: GREEN** (`TagScopeTest`, `ChannelKindTest`, `ModelsTest`, `TagOrderTest`); `php artisan migrate:fresh` no banco local de teste sem erro.
- [ ] **Step 5: Commit** `feat: escopo de etiqueta, contact_tag, agent_seen_at e tipo de canal (migrações e modelos)`.

---

### Tarefa 4: Tela de Etiquetas com duas listas, escopo e paleta

**Files:**
- Modify: `app/Livewire/Settings/Tags.php`, `resources/views/livewire/settings/tags.blade.php`
- Test: `tests/Feature/Livewire/TagsTest.php`

**Interfaces:**
- Consumes: `TagScope`, `TagColor`, `<x-tag-chip>`.
- Produces: propriedades `Tags::$scope` (`'conversa'`/`'contato'`) e `Tags::$color` (valor de `TagColor`, padrão `ardosia`); métodos `newTag(?string $scope = null)`, `edit`, `save`, `delete` seguem `supervisor`.

- [ ] **Step 1: RED** (em `TagsTest`): lista em duas seções ("Do contato" e "Da conversa"); criar etiqueta do contato grava `scope=contato`; `color` fora da paleta (`#2F855A`) é recusada; nome único global entre os dois escopos (`Já existe uma etiqueta com esse nome.`); editar o escopo de etiqueta **com tickets ou contatos ligados** é recusado (`O escopo não muda enquanto a etiqueta está em uso.`) e livre quando não há uso; apagar remove os dois pivôs; atendente sem `supervisor` recebe 403 (como hoje).
- [ ] **Step 2: Implementar** o formulário (escolha de escopo, seletor de tom com os `swatch()` e `aria-label` com o `label()`), contagem de uso por tipo (`tickets_count`/`contacts_count`) e as duas listas. Validação `Rule::enum(TagColor::class)` e `Rule::enum(TagScope::class)`.
- [ ] **Step 3: GREEN** `php artisan test tests/Feature/Livewire/TagsTest.php tests/Unit/AccessibleNamesTest.php` (o que existir); conferir 360 px e dois temas.
- [ ] **Step 4: Commit** `feat: tela de etiquetas com escopo (contato/conversa) e paleta fixa`.

---

### Tarefa 5: Etiquetas na ficha do contato

**Files:**
- Modify: `app/Livewire/Contacts/ContactCard.php`, `resources/views/livewire/contacts/contact-card.blade.php`
- Test: `tests/Feature/Livewire/ContactCardTagsTest.php`

**Interfaces:**
- Consumes: `Contact::tags()`, `Tag` com `scope = contato`.
- Produces: `ContactCard::addTag(int $tagId): void`, `ContactCard::removeTag(int $tagId): void`; constante `ContactCard::MAX_TAGS = 20` (decisão minha: limite por contato; o plano de execução registra no ledger).

- [ ] **Step 1: RED** (`ContactCardTagsTest`): `addTag` anexa só etiqueta de escopo `contato` (de conversa é recusada); `removeTag` desanexa; ficha `readonly` recebe 403 em ambos; o contato **não** muda `updated_at`/`edited_at` e **não** enfileira `SyncContactToGoogleJob` (`Queue::fake()` / `Bus::fake()`); o 21º anexo é recusado com aviso; a ficha compacta e a completa mostram os chips (`<x-tag-chip>`) do contato; sem etiquetas do contato no catálogo o seletor não aparece.
- [ ] **Step 2: Implementar** o menu de etiquetas na ficha (lista com busca do que ainda não está no contato, `flux:dropdown` ou o padrão de menu usado na ficha) e os chips com botão de remover (`aria-label="Remover etiqueta {nome}"`). Reusa `abort_if($this->readonly, 403)`. Dispara `contact-saved`? **Não** (não é salvamento do perfil); a ficha se atualiza sozinha.
- [ ] **Step 3: GREEN** + `php artisan test tests/Feature/Livewire/ContactCardTest.php tests/Feature/Livewire/ContactCardCompactTest.php`.
- [ ] **Step 4: Commit** `feat: etiquetas do contato na ficha (aplicar e remover sem tocar o contato nem o Google)`.

---

### Tarefa 6: Mesclagem de contatos une as etiquetas

**Files:**
- Modify: `app/Services/Inbox/MergeLidContact.php`
- Test: `tests/Feature/Inbox/MergeLidContactTest.php` (o teste existente da mesclagem; criar o caso novo nele)

**Interfaces:**
- Consumes: `Contact::tags()`.
- Produces: em `MergeLidContact::__invoke`, antes de `$lid->delete()`, `$phone->tags()->syncWithoutDetaching($lid->tags()->pluck('tags.id'))`.

- [ ] **Step 1: RED:** telefone com `[A]`, lid com `[A, B]` → após a mesclagem o telefone tem `[A, B]` (sem duplicar `A`), e `contact_tag` não tem linha do lid. Rodar → FALHA.
- [ ] **Step 2: Implementar** a união (uma linha) sem tocar `updated_at` do telefone.
- [ ] **Step 3: GREEN** (`MergeLidContactTest` completo).
- [ ] **Step 4: Commit** `fix: mesclagem do lid preserva as etiquetas do contato`.

---

### Tarefa 7: Não lidas calculadas (`agent_seen_at`)

**Files:**
- Create: `app/Actions/Inbox/MarkTicketSeenByAgent.php` (ou método em `Ticket`, o que combinar com o estilo das Actions de `app/Actions/Inbox`)
- Modify: `app/Livewire/Inbox/Inbox.php` (`select`), `app/Livewire/Inbox/Transcript.php` (`refreshForEvent`), o ponto onde o atendente responde (`SendTicketMessage::create` com origem do painel, descobrir via `codegraph_explore "SendTicketMessage create PAINEL"`)
- Test: `tests/Feature/Inbox/AgentSeenTest.php`

**Interfaces:**
- Produces: `MarkTicketSeenByAgent::__invoke(Ticket $ticket, User $by): void`: preenche `agent_seen_at = now()` **só se** `$ticket->assigned_user_id === $by->id` ou `assigned_user_id === null`; ao mudar, `TicketUpdated::dispatch($ticket)`. `Ticket::unreadCountSql()` (ou scope `withUnread`) que adiciona `unread` à lista: `count(*)` de `messages` com `direction = 'in'` e `sent_at > coalesce(tickets.agent_seen_at, 'epoch')`, mesma forma da subconsulta de `PREVIEW_SQL`.

- [ ] **Step 1: RED** (`AgentSeenTest`): responsável abre → `agent_seen_at` preenchido e `TicketUpdated` disparado; supervisor que só abre ticket de outra pessoa **não** zera; ticket sem responsável, qualquer atendente abre → zera; mensagem do cliente com o ticket aberto na tela do responsável zera de novo; responder pelo painel zera; contador: 3 mensagens do cliente e `agent_seen_at` nulo → 3; só mensagens nossas → 0; ticket sem mensagens → 0 sem erro. Rodar → FALHA.
- [ ] **Step 2: Implementar** a action, a chamada nos três pontos (junto do `MarkTicketSeenJob::dispatch`, sem substituí-lo) e a subconsulta de não lidas. Conferir o `EXPLAIN` da subconsulta na lista de 200 tickets; criar o índice `messages(ticket_id, direction, sent_at)` só se o plano pedir.
- [ ] **Step 3: GREEN** + `tests/Feature/Inbox/MarkTicketSeenTest.php`, `tests/Feature/Inbox/BroadcastTest.php`.
- [ ] **Step 4: Commit** `feat: não lidas calculadas por agent_seen_at (só o responsável zera)`.

---

### Tarefa 8: Card novo da Fila, filtro por escopo e chips nos outros lugares

**Files:**
- Modify: `app/Livewire/Inbox/Inbox.php` (`render`: `unread`, `with(['contact.tags'])`, filtro `$tagId` por escopo), `resources/views/livewire/inbox/inbox.blade.php` (card), `resources/js/relative-time.js` (se precisar de modo "há N min" para o card; padrão do sino, sem expressões que o build CSP não aceite)
- Modify: `app/Livewire/Contacts/Index.php` + `resources/views/livewire/contacts/index.blade.php` (filtro `tagId` só de contato e chips na linha), `app/Livewire/History/Index.php` + a view dele (chips do contato na lista e no painel), `resources/views/livewire/inbox/new-conversation.blade.php` (chips do contato na lista de resultados), `app/Livewire/Inbox/NewConversation.php` (`with('tags')` nos `matches`)
- Test: `tests/Feature/Livewire/InboxQueueCardTest.php` (adaptar), `InboxQueueFilterTest.php` (novo), `ContactsIndexTest.php`, `tests/Feature/History/`, `NewConversationModalTest.php`

**Interfaces:**
- Consumes: `Tag::scope`, `Contact::tags()`, `unread` (Tarefa 7), `ChannelKind::icon()`, `<x-tag-chip>`, `$showSector`, `$showChannel`.
- Produces: layout do card (`data-testid` novos): `card-channel-icon` (sobre o avatar), `card-chip-contact` / `card-chip-ticket` (até 3 + `card-tags-more` com a lista completa no `title` e em `sr-only`), `card-sector` (coluna da direita, só com `$showSector`), `card-time` (tempo relativo atualizado no navegador a partir do instante ISO; o SLA continua em `card-sla`), `card-unread` (contador; some em 0, `99+` no teto). Chips do contato primeiro e sólidos; da conversa com contorno.

- [ ] **Step 1: RED:** `InboxQueueCardTest` adaptado: ícone do canal sempre; chips do contato antes e sólidos, da conversa com contorno, máximo 3 + "+N" com `title`; setor na coluna da direita só com 2+ setores recebendo; selo do número só com 2+ números ativos; `card-unread` mostra 2 com duas mensagens novas e some depois que o responsável abre; `card-time` traz o instante ISO. Filtro: etiqueta de contato casa tickets pelo contato (`whereHas('contact.tags')`), de conversa pelo ticket (`whereHas('tags')`), conforme `Tag::scope`; Contatos filtra só por etiqueta de contato e mostra os chips na linha; Histórico e Nova conversa mostram os chips do contato. Rodar → FALHA.
- [ ] **Step 2: Implementar** o card (coluna da direita: setor, tempo, não lidas), os filtros (o seletor da Fila agrupa "Do contato" e "Da conversa"), e os chips nos outros lugares (evitar N+1 com `with('tags')` ou `with('contact.tags')`; um teste conta as consultas da lista com 20 tickets).
- [ ] **Step 3: Verificar no navegador** 360 px, 720p, 1080p e 2K, claro e escuro, com nomes longos e 4+ etiquetas; sem estouro de largura nem de altura; `php artisan test tests/Unit/AlpineCspSubsetTest.php tests/Unit/ViewConventionsTest.php tests/Feature/Livewire/InboxQueuePreviewTest.php tests/Feature/Livewire/InboxChannelBadgeTest.php`.
- [ ] **Step 4: Commit** `feat: card novo da Fila (canal, chips, setor, tempo e não lidas) e etiqueta do contato nas listas`.

---

### Tarefa 9: Automação por escopo (Etiquetar e Condição "tem a etiqueta")

**Files:**
- Modify: `app/Services/Bot/Effects/BotEffects.php`, `LiveEffects.php`, `SimulatedEffects.php`, `app/Services/Bot/Nodes/EtiquetarNode.php`, `CondicaoNode.php`, `app/Services/Bot/Flow/FlowValidator.php`, `app/Livewire/Automation/Editor.php` (lookup de etiquetas com o escopo), `resources/js/flow-editor/nodes.js`, `appearance.js`
- Test: `tests/Feature/Bot/LiveEffectsTest.php`, `tests/Unit/Bot/FlowValidatorTest.php`, `tests/Feature/Livewire/AutomationEditorTest.php`, `tests/Feature/Bot/` (teste do nó Condição)

**Interfaces:**
- Consumes: `Tag::scope`.
- Produces: `BotEffects::tag(Tag $tag): void` passa a decidir pelo escopo (contato → `ticket->contact->tags()->syncWithoutDetaching`; conversa → `ticket->tags()` como hoje); **novo** `BotEffects::hasTag(Tag $tag): bool` (contato ou ticket conforme o escopo; no simulador, `false` salvo marcação do `SimulationState`); novo valor de `kind` no nó Condição: `etiqueta`, com `tag_id` (`NodeData::int('tag_id')`); portas `true`/`false`; rótulo da condição no editor: "O contato/ticket tem a etiqueta".
- **Atenção (divergência da spec):** a spec trata a Condição "tem a etiqueta X" como existente, mas hoje o `CondicaoNode` só tem horário, nome, retorno e variável. A Tarefa 9 **cria** a condição.

- [ ] **Step 1: RED:** `LiveEffects::tag` com etiqueta de contato grava em `contact_tag` e não em `ticket_tag`, e com de conversa o contrário (nós antigos continuam valendo); `hasTag` reflete o escopo; `CondicaoNode` com `kind=etiqueta` vai por `true` com a etiqueta e por `false` sem ela, e por `false` (sem exceção) se a etiqueta foi apagada; `FlowValidator` aceita `etiqueta` com `tag_id` existente e recusa inexistente (`a etiqueta X não existe.`); o simulador registra "etiquetaria".
- [ ] **Step 2: Implementar** (interface, as duas implementações, os dois nós, o validador, a lista do editor mostrando o escopo ao lado do nome, e o bloco `condicao` em `nodes.js` com a opção nova e o campo `tag_id` quando `kind = etiqueta`).
- [ ] **Step 3: GREEN** nos arquivos acima + `tests/Feature/Bot/` inteiro + `node --test tests/js`.
- [ ] **Step 4: Commit** `feat: automação etiqueta contato ou conversa pelo escopo e condição "tem a etiqueta"`.

---

## Autoverificação (feita ao escrever)

- **Cobertura da spec:** metas 1 a 5 → Tarefas 3 a 9; marca neutra → 1; paleta → 2; anti-metas (Google, órfãs, 360 px/720p/AA) → testes das Tarefas 3, 5, 8; decisões 1 a 13 e "minhas" 1 a 10 mapeadas (tempo relativo no navegador: Tarefa 8; mesclagem: 6).
- **Divergência encontrada:** a Condição por etiqueta não existia (Tarefa 9 a cria); a spec precisa de uma linha dizendo isso.
- **Consistência de tipos:** `TagScope`, `TagColor`, `ChannelKind`, `unread`, `hasTag` e `MarkTicketSeenByAgent` têm o mesmo nome em todas as tarefas.
- **Proporção:** o plano descreve interfaces e testes; os corpos ficam para a execução.

---

## Adendo de 13/11: paleta do sistema inteiro (Tarefas 11 a 13)

Pedido do dono antes de fechar o PR: pesquisar coloração de sistemas e acertar as demais cores (bolhas, nota interna, estados) para o padrão novo. Decisões 15 a 17 da spec.

### Tarefa 11: Neutros em zinc
**Files:** `resources/css/app.css` (tokens claro e escuro, remove o remapeamento `zinc` → `stone`, `bot` índigo, categorias do editor), `resources/js/flow-editor/editor.js` (fallbacks), `tests/Unit/ThemeTokensTest.php`.
- [x] RED: `test_os_neutros_sao_todos_zinc_nos_dois_temas`, `test_o_flux_enxerga_o_zinc_de_verdade_sem_remapear_para_stone` e a marca em #27272a falham. GREEN com os tokens novos (contraste AA medido).

### Tarefa 12: Bolhas, nota e aviso
**Files:** `resources/views/livewire/inbox/transcript.blade.php`, `note.blade.php`, tokens `warn-soft`/`warn-line` no `app.css`, `bg-warn/10|20` e `border-warn/30` trocados em 10 views, `tests/Feature/Livewire/BubbleColorsTest.php`, `ThemeTokensTest`.
- [x] RED/GREEN: cliente (borda), nossa (`accent-soft`), bot (`bg-bot/15`), celular (`border-dashed`, ícone, sem âmbar), nota e aviso com `warn-soft` (o âmbar a 10% virava salmão); guarda `test_nenhuma_view_pinta_aviso_com_warn_translucido`.

### Tarefa 13: Cores cruas e minimapa
**Files:** views com `text-green-*`, `text-red-*`, `stone-*` (autenticação e segurança), `app.css` (minimapa em cinza), `ThemeTokensTest`.
- [x] RED/GREEN: `test_nenhuma_view_usa_paleta_crua_de_estado_nem_stone` (exceções decorativas: `location`, `media`, `welcome`) e `test_minimapa_do_editor_nao_traz_o_amarelo_padrao_da_biblioteca`.
