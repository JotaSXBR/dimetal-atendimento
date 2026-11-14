# Menores da série UX (D, E, F, G) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Resolver os menores de `docs/superpowers/menores-pendentes.md` conforme os rulings do advisor (o dono delegou), tirando cada linha do arquivo no commit que a resolve.

**Architecture:** Correções pontuais em arquivos já existentes; lógica nova que dá para isolar vira função pura com `node --test`. Nada de dependência nova.

**Tech Stack:** Laravel 13, Livewire 4.4, Alpine CSP, `node --test`.

**Spec:** `docs/superpowers/menores-pendentes.md` (a tabela de cada fatia é o requisito) e os `Ruling:` do ledger (decididos com o advisor em 14/11).

## Global Constraints

- Cada correção com teste que falhou antes; suíte inteira verde por tarefa.
- A linha do menor sai de `menores-pendentes.md` no commit que a resolve; D5, F4, F5 e G5 fecham como "aceito" (linha removida, ruling no ledger).

## Review Focus

- **D2 (confirmação):** confirmar só reenvia o clique se o elemento ainda é o mesmo (`wire:click` e `data-confirm` iguais aos de quando o diálogo abriu); um re-render no meio aborta em silêncio, nunca executa a ação de outro item.
- **E5 (validação):** a regra nova do `FlowValidator` não recusa os modelos de fábrica (`Distribuidora`, `DefaultWorkflow`) nem versões já publicadas (só vale ao publicar rascunho).
- **G2 (URL):** `?contato=ID` abre a ficha e some da URL ao fechar; id inexistente ou de outro contexto não quebra; trocar de página/busca não reabre.
- **D3/D4:** nenhum Desfazer promete o que não faz; ticket apagado/encerrado vira aviso, não erro do Livewire.

### Task 1: diálogo de confirmação (D1, D2)
Files: `resources/js/confirm-dialog.js`, `tests/js/confirm-dialog.test.mjs`. Produces (puro): `actionKey(element): string` = `wire:click` + `\u0000` + `data-confirm` do elemento; `sameAction(key, element): boolean`. Sem `#confirm-dialog`, o clique usa `window.confirm(mensagem)` e só segue se confirmado. Testes: chave igual/diferente com objetos falsos de `getAttribute`.

### Task 2: busca Ctrl+K (G1, G2, G3, G4)
Files: `resources/js/command-palette.js`, `tests/js/command-palette.test.mjs`, `app/Livewire/Contacts/Index.php`, `tests/Feature/Search/PaletteTest.php`, `resources/views/layouts/app/sidebar.blade.php`. G1: primeiro resultado `data-active` + `aria-activedescendant` ao abrir/atualizar. G2: `#[Url(as: 'contato')]` em `selectedId` (checar semântica no Context7 `/livewire/livewire/v4.4.8`), existência checada, fechar zera. G3: não abrir se houver outro `dialog[open]`. G4: puro `shortcutLabel(platform)` (`⌘K` em Mac/iOS, senão `Ctrl K`), aplicado ao carregar e em `livewire:navigated`.

### Task 3: notas e etiquetas (D3, D4)
Files: `app/Livewire/Inbox/Transcript.php`, `app/Livewire/Inbox/TicketTags.php`, `tests/Feature/Inbox/SaveNoteTest.php`, `tests/Feature/Livewire/TicketTagsTest.php`. D3: `restoreNote` confere `Ticket::readableBy($user)->whereKey($this->ticketId)->exists()` antes de gastar o token (RED: ticket apagado na janela). D4: toast de remoção só com "Desfazer" quando a etiqueta é do escopo conversa; `undoRemove` em ticket encerrado mostra aviso "A conversa foi encerrada; a etiqueta não voltou." em vez do 403.

### Task 4: editor do bot (E1, E2, E3, E4, E5)
Files: `resources/js/flow-editor.js`, `resources/js/flow-editor/appearance.js`, `resources/js/flow-editor/editor.js`, `resources/views/livewire/automation/editor.blade.php`, `app/Services/Bot/Flow/FlowValidator.php`, `tests/js/flow-appearance.test.mjs`, `tests/Unit/Bot/FlowValidatorTest.php`. E1: `variables` reativo atualizado no `focus`/`mousedown` do select. E2+E3: puro `anchorTranslate(positions, k, margin = 24)` (Início; sem Início, o menor x/y). E4: select fora do `<label>` (`[data-variable-field]`). E5: Pergunta `variavel` casa `^[a-z0-9][a-z0-9_]*$` ou erro "{id}: o nome da variável usa só letras minúsculas, números e _."; modelos de fábrica passam.

### Task 5: gráfico (F1, F2, F3)
Files: `resources/views/components/bar-chart.blade.php`, `tests/Feature/Components/BarChartTest.php`. F3: barra com `max(1, …)` quando valor > 0 (RED: 1 ao lado de 300). F1/F2: asserções que mordem (comprovar quebrando o código por um instante).

### Task 6: verificação, revisão e PR (portões 6 e 7, ledger, limpeza de `menores-pendentes.md`, PR, merge com CI verde)
