# Pendências do PR #109 (visto, acessibilidade, cores) e player de áudio: plano

> **Para quem executa:** execução inline (decisão do dono), `superpowers:executing-plans`, RED antes do código em cada tarefa.

**Meta:** resolver os menores adiados do PR #109 com as escolhas do dono (13/11), arrumar o player de áudio (feio e desalinhado) e rodar a revisão independente.

**Spec:** `docs/superpowers/specs/2026-11-12-etiquetas-contato-e-card-fila-design.md` (decisões 15 a 17 e o "visto pela equipe"). Branch `fix/pendencias-etiquetas-visto-acessibilidade`, a partir da `main` em `45339d7`.

## Global Constraints

- Só o beta; sem PR sem pedido do dono; commits em português com a linha `Co-Authored-By` do harness.
- UI alinhada em 360 px, 720p, 1080p e 2K, claro e escuro; contraste AA; `ViewConventionsTest`/`AlpineCspSubsetTest` verdes.
- Portão 7 inteiro antes de dizer pronto (Pint, Larastan, PHPMD, suíte com Redis, JS, build).

## Decisões do dono (13/11)

1. **Visto:** o contador só zera com a aba visível; em segundo plano a mensagem fica não lida até a aba voltar.
2. **Sidebar:** sem `aria-label` no item Fila; os selos ganham texto só para leitor de tela. *Detalhe de execução:* com o menu recolhido o texto some, então o item ganha um trecho `sr-only` visível só nessa situação, com as contagens.
3. **Tempo do card:** o servidor escreve no mesmo formato do navegador (`há 12 min.`, `há 1 h`, `há 2 dias`, `há 5 seg.`).
4. **Revisão:** subagente Sonnet 5.5, esforço médio, sobre a paleta, o contraste, a paginação e estas correções.

## Tarefas

### Tarefa 1: Visto só com a aba visível
**Files:** `app/Livewire/Inbox/Transcript.php`, `resources/js/transcript-visibility.js` (novo, importado em `app.js`), `resources/views/livewire/inbox/transcript.blade.php`, `tests/Feature/Inbox/AgentSeenTest.php`.
**Interfaces:** `Transcript::$visible` (bool público, padrão `true`, atualizado pelo navegador); `Transcript::seen(): void` (a aba voltou a ficar visível: marca como visto se o usuário pode ver o ticket).
- [ ] RED: evento de mensagem do cliente com `visible=false` não muda `agent_seen_at` (mas o visto ao cliente continua); `seen()` depois zera; `visible=true` zera como hoje; `seen()` de usuário que não vê o ticket não faz nada.

### Tarefa 2: Corrida do visto
**Files:** `app/Actions/Inbox/MarkTicketSeenByAgent.php`, `tests/Feature/Inbox/AgentSeenTest.php`.
- [ ] RED: `agent_seen_at` passa a ser o `sent_at` da mensagem mais recente do cliente (não `now()`); mensagem que chega depois disso continua não lida.

- [x] **Revisada (13/11, menor adiado):** o visto passou a ser uma marca d'água por **id** de mensagem (`tickets.agent_seen_message_id`, migração `2026_11_13_000005` com backfill a partir de `agent_seen_at`), porque `sent_at` tem precisão de segundo e duas mensagens no mesmo segundo se confundiam. `agent_seen_at` continua gravado ("quando viu").

### Tarefa 3: Efeito de etiqueta sem contato
**Files:** `app/Services/Bot/Effects/LiveEffects.php`, `tests/Feature/Bot/LiveEffectsTest.php`.
- [ ] RED: `tag()` e `hasTag()` de etiqueta do contato com o contato inexistente não lançam exceção (`hasTag` falso, `tag` sem efeito).

### Tarefa 4: `Tags::newTag` legível
**Files:** `app/Livewire/Settings/Tags.php`, `tests/Feature/Livewire/TagsTest.php`.
- [ ] Teste de caracterização (escopo inválido cai em `conversa`), depois a reescrita sem `?->value ??`.

### Tarefa 5: Etiqueta "pedra" em zinc
**Files:** `app/Enums/TagColor.php` (swatch), `resources/css/app.css` (`.tag-pedra`), `tests/Unit/ThemeTokensTest.php`.
- [ ] RED: o tom "pedra" tem valores zinc (`#f4f4f5`, `#27272a`, `#71717a`; escuro `#3f3f46`, `#e4e4e7`, `#a1a1aa`); contraste AA mantido.

### Tarefa 6: Nome acessível do item Fila
**Files:** `resources/views/layouts/app/sidebar.blade.php`, `tests/Feature/Layout/SidebarTest.php`.
- [ ] RED: o link da Fila não tem `aria-label`; os selos têm texto `sr-only` ("aguardando", "com SLA estourado"); há um trecho `sr-only` só para a sidebar recolhida com o nome e as contagens; o texto do link contém o texto visível.

### Tarefa 7: Tempo do card no formato do navegador
**Files:** `app/Support/WaitLabel.php` (novo `narrow()`), `resources/views/livewire/inbox/inbox.blade.php`, `tests/Unit/WaitLabelTest.php`, `tests/js/relative-time.test.mjs`, `tests/Feature/Livewire/InboxQueueCardTest.php`.
- [ ] RED: `WaitLabel::narrow()` devolve `há 5 seg.`, `há 12 min.`, `há 3 h`, `há 1 dia`, `há 2 dias`; teste de JS confere o mesmo texto no `Intl.RelativeTimeFormat` (paridade).

### Tarefa 8: Player de áudio
**Files:** `resources/views/livewire/inbox/audio-player.blade.php`, `resources/js/audio-player.js` (se preciso), testes de tela do player.
- [ ] Ver o player renderizado, definir o desenho (botão de play, onda e tempo alinhados, velocidade discreta), RED/GREEN e verificação visual nos dois temas e em 360 px.

### Tarefa 9: Revisão e fim
- [ ] Portão 7 inteiro; revisão independente (Sonnet 5.5 medium); correções com teste; só então perguntar ao dono se abre o PR.
