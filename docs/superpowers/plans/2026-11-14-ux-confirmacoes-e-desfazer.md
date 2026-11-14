# UX: confirmações e "Desfazer" (fatia D) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (execução inline, decisão do dono) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trocar as confirmações nativas do navegador (`wire:confirm`) por um diálogo do próprio app e tirar a confirmação das remoções leves (etiqueta, nota), que passam a acontecer na hora com um aviso "Desfazer".

**Architecture:** Um diálogo único `<dialog>` na barra de layout (`x-confirm-dialog` + `resources/js/confirm-dialog.js`) intercepta, na fase de captura, o clique em qualquer elemento com `data-confirm`; confirmado, reenvia o mesmo clique, então `wire:click` e `x-on:click` seguem como estão. O "Desfazer" usa o `Flux::toast(action: …)` (grátis, já instalado) com um evento que o componente dono escuta; a nota apagada fica guardada no cache por poucos segundos sob um token do usuário (sem migração).

**Tech Stack:** Laravel 13, Livewire 4.4, Flux 2.21 (`toast` com `action`), `<dialog>` nativo, Alpine/JS puro (CSP), PHPUnit, `node --test`.

**Spec:** `docs/superpowers/specs/2026-11-14-ux-simplificacao-design.md` (item 10, fatia D).

## Global Constraints

- Branch `feat/ux-confirmacoes-e-desfazer`, da fatia C (#121, ainda não mesclada); rebasear sobre a `main` quando a #121 for mesclada. PR com base `main`.
- Sem dependência nova; nada de código do Flux Pro. Texto ≥ `text-xs`; alvo ≥ 24 px; `<button>` cru novo entra na lista de `ViewConventionsTest`; cor só por token (`danger` só para destrutivo).
- JS em CSP: nada de método/arrow function em atributo; o comportamento fica em módulo (`resources/js/confirm-dialog.js`), a lógica pura em função testada com `node --test`.
- Nenhum `wire:confirm` sobra nas views (guarda no `ViewConventionsTest`). O texto de cada confirmação de hoje se mantém (a pergunta vira o título; a consequência, o texto).
- Desfazer: o toast dura 6 s (`duration: 6000`); restaura o estado anterior e deixa a trilha de auditoria com **evento próprio** (etiqueta: `tag_added`; nota: `note_restored`).
- **Fotos e logotipo** (`removeAvatar`, `removeLogo`) ficam como "graves" com o diálogo (o arquivo é apagado, não dá para desfazer sem guardá-lo); decisão do plano, ver ledger.
- Verificação visual em 360 px, 1280×720, 1920×1080 e 2560×1440, claro e escuro; roteiro de teclado no Chrome para o diálogo e para o toast.
- Commits em português com prefixo e `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Rodar a **suíte inteira** antes de dar uma tarefa por completa (lição da fatia C).

## Review Focus

- **Diálogo:** Cancelar e Esc não executam a ação; só "Confirmar" executa, e uma vez; o foco inicial é o Cancelar (ação destrutiva); o clique em elemento com `data-confirm` dentro de menu (transcript) funciona; confirmar não dispara a ação duas vezes (o reenvio do clique não pede confirmação de novo). (Task 1)
- **Nada sem confirmar por engano:** todo elemento que tinha `wire:confirm` agora tem `data-confirm` (e vice-versa não sobra confirmação nativa); o teste de cada tela que afirmava `wire:confirm` agora afirma `data-confirm`. (Task 1)
- **Desfazer de etiqueta:** remover tira na hora e mostra "Desfazer"; desfazer recoloca (e vale só no ticket e no contato daquele componente); desfazer depois que o ticket foi encerrado não recoloca; etiqueta apagada no meio não quebra. (Task 2)
- **Desfazer de nota:** só o autor apaga e só o autor desfaz (o token é do usuário e expira); desfazer recria a nota com texto, autor, origem e horário originais; token vencido ou alheio não faz nada; o evento de auditoria aparece com texto legível. (Task 3)
- **Permissões:** nenhuma confirmação nova abre a quem não tinha a ação; Histórico (somente leitura) segue sem remover etiqueta nem nota. (Tasks 2 e 3)

---

### Task 1: diálogo de confirmação do app

**Files:**
- Create: `resources/views/components/confirm-dialog.blade.php`, `resources/js/confirm-dialog.js`, `tests/js/confirm-dialog.test.mjs`, `tests/Feature/Components/ConfirmDialogTest.php`
- Modify: `resources/js/app.js` (importar), `resources/views/layouts/app/sidebar.blade.php` (incluir o componente uma vez), as 16 views com `wire:confirm` (lista abaixo), `tests/Unit/ViewConventionsTest.php` (guarda), e os testes que afirmam `wire:confirm`
- As views: `livewire/automation/index` (apagar workflow; copiar versão), `livewire/inbox/inbox` (tomar), `livewire/inbox/transcript` (reenviar incerta; apagar para todos), `livewire/settings/business-hours-page` (feriado), `channel-card` (importar histórico), `company` (logotipo), `google-contacts` (desconectar), `quick-replies` (resposta rápida), `retention` (expurgo), `sectors` (setor), `tags` (etiqueta do cadastro), `users` (foto; ativar/desativar), `pages/settings/⚡profile` (foto)

**Interfaces:**
- Produces (JS puro): `splitConfirm(message: string): { title: string, text: string }` em `resources/js/confirm-dialog.js`: título = até o primeiro `?` (inclusive); texto = o resto, sem espaços nas pontas; sem `?` o título é a mensagem e o texto é `''`.
- Produces (atributos): `data-confirm="<mensagem>"` (obrigatório), `data-confirm-label="<rótulo do botão>"` (padrão `Confirmar`), `data-confirm-tone="danger|primary"` (padrão `primary`).
- Produces (Blade): `<x-confirm-dialog />` renderiza `<dialog id="confirm-dialog" aria-labelledby aria-describedby>` com `<form method="dialog">`, título, texto, "Cancelar" (`value="cancel"`) e dois botões de confirmar alternados por tom (`data-confirm-ok="primary|danger"`).
- Comportamento: listener `click` na captura em `document`; se o alvo (`closest('[data-confirm]')`) não tem a marca de "já confirmado", `preventDefault()` + `stopImmediatePropagation()`, abre o diálogo com `showModal()` e guarda o elemento; ao fechar com `returnValue === 'ok'` marca o elemento (`dataset.confirmed = '1'`) e chama `element.click()`, e a marca sai no clique seguinte.

- [ ] **Step 1: Testes (RED).** JS (`confirm-dialog.test.mjs`): `splitConfirm('Apagar o setor Vendas? Os tickets antigos continuam mostrando o nome.')` → título `Apagar o setor Vendas?` e texto `Os tickets antigos continuam mostrando o nome.`; sem `?` → título é a mensagem; `?` no fim → texto vazio; espaços sobrando são aparados. PHP: `ConfirmDialogTest` renderiza o componente e afirma `<dialog id="confirm-dialog"`, `method="dialog"`, os dois botões de confirmar com `data-confirm-ok`, o botão Cancelar e os `aria-labelledby`/`aria-describedby`; `ViewConventionsTest::test_nenhuma_view_usa_wire_confirm` (RED: lista as 16 views) e `test_todo_data_confirm_tem_rotulo_e_tom_conhecidos` (todo elemento com `data-confirm` tem `data-confirm-label` e `data-confirm-tone` ∈ `danger|primary`).
- [ ] **Step 2:** rodar; FAIL.
- [ ] **Step 3: Implementar** `splitConfirm` e o listener em `confirm-dialog.js`; o componente e a inclusão no layout; trocar cada `wire:confirm="…"` por `data-confirm="…" data-confirm-label="…" data-confirm-tone="…"` (mapa: apagar/remover/desconectar/desativar/expurgo/apagar para todos = `danger`; tomar, copiar versão, importar histórico, reenviar = `primary`), mantendo `wire:click` e o resto. A confirmação condicional de `users.toggleActive` só existe ao desativar: manter a condição.
- [ ] **Step 4:** testes verdes e os de cada tela atualizados (`ImportNowCardTest::test_botao_pede_confirmacao` e os que citam `wire:confirm`); suíte inteira. Commit `feat: dialogo de confirmacao do app no lugar do confirm do navegador`.

### Task 2: Desfazer ao remover etiqueta

**Files:**
- Modify: `app/Livewire/Inbox/TicketTags.php`, `app/Livewire/Contacts/ContactCard.php`
- Test: `tests/Feature/Livewire/TicketTagsTest.php`, `tests/Feature/Livewire/ContactCardTagsTest.php`

**Interfaces:**
- Produces: `TicketTags::removeTag` e `ContactCard::removeTag`, quando removem de fato, chamam `Flux::toast(text: 'Etiqueta «X» removida.', duration: 6000, action: ['label' => 'Desfazer', 'event' => 'undo-ticket-tag'|'undo-contact-tag', 'params' => ['ownerId' => <ticket|contact id>, 'tagId' => <id>]])`; `#[On('undo-ticket-tag')] public function undoRemove(int $ownerId, int $tagId): void` em cada componente: só age se `$ownerId` é o do próprio componente, e reaproveita `addTag($tagId)` (mesmas regras: ticket não encerrado, escopo da etiqueta, limite do contato). A auditoria do ticket ganha `tag_added` pelo próprio `addTag`.
- Consumes: `Flux\Flux::toast`; `addTag` e `removeTag` como estão.

- [ ] **Step 1: Testes (RED).** `removeTag` dispara o toast (`->assertDispatched('toast-show')` com `Desfazer`) só quando removeu (remover o que não está aplicado não dispara); `undoRemove` com o id certo recoloca a etiqueta e grava `tag_added`; com `ownerId` de outro ticket/contato não faz nada; com ticket encerrado dá 403 e não recoloca; etiqueta apagada no meio não quebra; contato no limite de etiquetas recusa com o erro de sempre.
- [ ] **Step 2:** FAIL. **Step 3:** implementar. **Step 4:** verdes + suíte. Commit `feat: desfazer ao remover etiqueta da conversa e do contato`.

### Task 3: Desfazer ao apagar nota

**Files:**
- Modify: `app/Livewire/Inbox/Transcript.php`, `app/Support/TicketEventText.php` (texto do evento novo; ver o enum/mapa dos tipos), `resources/views/livewire/inbox/note.blade.php` (sem `wire:confirm`; o botão "Apagar" passa a agir na hora)
- Test: `tests/Feature/Inbox/TicketNoteTest.php`, `tests/Feature/Timeline/TicketTimelineTest.php`, `tests/Feature/Audit/*` que listam os tipos

**Interfaces:**
- Produces: `Transcript::deleteNote(int $noteId)` guarda `['ticket_id','user_id','origin','body','created_at']` em `Cache::put("undo-note:{userId}:{token}", …, 60)` com `token = Str::random(32)` e mostra o toast `Nota apagada.` com `action: ['label' => 'Desfazer', 'event' => 'undo-note-delete', 'params' => ['token' => $token]]`; `#[On('undo-note-delete')] public function restoreNote(string $token): void` lê e apaga a chave do cache do usuário logado, recria a nota (mesmo texto, autor, origem e `created_at`) **no ticket deste componente**, grava `TicketEvent` `note_restored` e emite `TicketUpdated`; token ausente, vencido ou de outro usuário não faz nada.
- Consumes: `TicketNotePolicy::delete`; `TicketEvent`; `Flux::toast`.

- [ ] **Step 1: Testes (RED).** apagar a nota tira da conversa, grava `note_deleted` e dispara o toast com `Desfazer`; `restoreNote` com o token recria a nota idêntica (texto, autor, origem, horário) e grava `note_restored`; segundo uso do mesmo token não recria de novo; token de outro usuário ou inventado não faz nada; token vence (`Cache::forget`, nunca `travel()` por causa do Redis); o texto de `note_restored` aparece em português na linha do tempo e na auditoria; o botão "Apagar" da nota não tem confirmação.
- [ ] **Step 2:** FAIL. **Step 3:** implementar (guardar em `Cache`; texto do evento novo onde os outros tipos têm o deles). **Step 4:** verdes + suíte. Commit `feat: desfazer ao apagar nota`.

### Task 4: verificação, revisão e PR

- [ ] **Step 1:** servidor local e dados (receita da fatia B), `npm run build`; roteiro no Chrome: o diálogo abre com o texto certo e Cancelar em foco; Esc e Cancelar não executam; Confirmar executa uma vez (conferir as requisições); o toast "Desfazer" aparece, dura ~6 s e restaura (etiqueta e nota); `ux-audit.js` em `configuracoes/etiquetas`, `configuracoes/setores`, `automacao`, `inbox?ticket=4`, `contatos` sem estouro nem controle sem nome.
- [ ] **Step 2:** portões 6 e 7, ledger, PR, CI verde e merge.

## Fora desta fatia

Editor do bot (E), gráfico (F), Ctrl+K (G), faixas dispensáveis (H), sino (no fim).
