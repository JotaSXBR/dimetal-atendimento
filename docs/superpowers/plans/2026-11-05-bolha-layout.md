# Layout das bolhas da inbox: plano

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Tirar a linha própria do menu da bolha, pôr o horário na linha do texto, enriquecer o menu (Copiar, Dados da mensagem, ícones) e reagir por uma carinha fora da bolha.

**Architecture:** Só Blade/Alpine em `resources/views/livewire/inbox/transcript.blade.php`. Reagir continua `$parent.react` (policy `react`); Copiar e Dados são client-side (sem ação nova no servidor).

**Tech Stack:** Laravel 13, Livewire 4, Alpine, Tailwind.

**Spec:** `docs/superpowers/specs/2026-11-05-bolha-layout-design.md`

## Global Constraints
- Textos da interface em português. Sem dependência nova.
- Menu continua `fixed` com o posicionamento atual (`place()`); só muda o botão que o abre e o conteúdo.
- Testes de view: `Livewire::test(Transcript::class, ['ticketId' => $id])`, como em `tests/Feature/Livewire/InboxTranscriptTest.php`.

## Review Focus
- Mensagem sem texto (foto/documento): "Copiar" não aparece; horário continua embaixo.
- Mensagem do cliente (`IN`): "Dados da mensagem" mostra só o horário de recebimento, sem Entregue/Lida.
- Mensagem apagada ou sem `wa_message_id`: sem chevron, sem carinha de reação.
- Modo leitura (`$readonly`): nenhum botão novo aparece.
- Mensagem com `delivered_at`/`read_at` nulos: o painel não mostra linha vazia.

---

### Task 1: Chevron, horário na linha e menu com ícones

**Files:** Modify `resources/views/livewire/inbox/transcript.blade.php` (bolha linhas 28-29, menu 99-154, horário 156-164); Test `tests/Feature/Livewire/InboxTranscriptTest.php`.

**Interfaces:** Produces: o `x-data` do menu fica como está; o botão tem `data-testid="message-menu-button"`.

- [ ] **Step 1: Teste (RED).** `test_menu_da_bolha_e_um_chevron_sem_linha_propria`: ticket com mensagem de texto e atendente que pode responder; `assertSeeHtml('data-testid="message-menu-button"')` e `assertDontSeeHtml('mt-1 flex justify-end')`. `test_mensagem_apagada_nao_tem_menu`: com `revoked_at`, `assertDontSeeHtml('data-testid="message-menu-button"')`. `test_modo_leitura_nao_tem_menu` análogo com `readonly`.
- [ ] **Step 2:** `php artisan test --filter=InboxTranscriptTest` → falha.
- [ ] **Step 3:** Bolha ganha `relative group`. O wrapper do menu deixa de ocupar linha: `absolute end-1 top-1`, botão (chevron SVG, `aria-label="Ações da mensagem"`) com `opacity-0 group-hover:opacity-100 focus-visible:opacity-100 [@media(hover:none)]:opacity-100`. O horário passa a `float-right` com `ms-3 mt-2` dentro do último bloco de texto quando a mensagem tem só texto; com mídia, documento ou citação sem texto, mantém a linha própria (`clear-both`). Menu: pílula de emojis no topo, itens com ícone SVG, filete (`border-t`) antes de "Apagar para todos".
- [ ] **Step 4:** teste passa; `npm run build`.
- [ ] **Step 5:** `git commit -m "feat: menu da bolha vira chevron no canto e horário na linha do texto"`

### Task 2: Copiar e Dados da mensagem

**Files:** Modify `transcript.blade.php` (menu); Test `InboxTranscriptTest.php`.

- [ ] **Step 1: Teste (RED).** `test_copiar_so_aparece_com_texto`: texto → `assertSee('Copiar')`; foto sem legenda → `assertDontSee('Copiar')`. `test_dados_da_mensagem_lista_enviada_entregue_e_lida`: saída com `delivered_at` e `read_at` → vê `Enviada`, `Entregue`, `Lida` e os horários `H:i`. `test_dados_da_mensagem_do_cliente_so_tem_recebida`: `IN` → vê `Recebida`, não vê `Entregue` nem `Lida`. `test_dados_omitem_horario_nulo`: sem `read_at` → não vê `Lida`.
- [ ] **Step 2:** rodar; falha.
- [ ] **Step 3:** "Copiar": `x-on:click="navigator.clipboard.writeText(@js($message->body))"`, só se `body !== ''` e tipo não é `location`/`contact`. "Dados da mensagem": item que alterna `info` (Alpine) e mostra, abaixo, a lista com rótulos (`Recebida` para `IN`; `Enviada`/`Entregue`/`Lida` para `OUT`) e `->timezone($tz)->format('d/m H:i')`, omitindo nulos. O menu não fecha ao clicar nesse item (`x-on:click.stop`).
- [ ] **Step 4:** testes passam.
- [ ] **Step 5:** `git commit -m "feat: copiar texto e dados da mensagem no menu da bolha"`

### Task 3: Carinha de reação fora da bolha

**Files:** Modify `transcript.blade.php`; Test `InboxTranscriptTest.php`.

- [ ] **Step 1: Teste (RED).** `test_carinha_de_reacao_so_com_permissao_de_reagir`: mensagem com `wa_message_id` → `assertSeeHtml('data-testid="quick-react"')`; sem `wa_message_id`, apagada ou `readonly` → não aparece. `test_carinha_dispara_react_com_os_emojis_da_barra`: contém os 6 de `ReactToMessage::EMOJIS`.
- [ ] **Step 2:** rodar; falha.
- [ ] **Step 3:** botão de carinha ao lado da bolha (lado de dentro da conversa: `order` invertido para `OUT`), visível em `group-hover`, que abre uma pílula com `EMOJIS` chamando `$parent.react(id, emoji)`; sob `@can('react', $message)` e `! $readonly`. Reaproveitar o mesmo `x-data` de abrir/fechar do menu (sem segundo posicionador: pílula `absolute` curta, já que não corta no `overflow` por ficar ao lado da bolha; conferir na última mensagem).
- [ ] **Step 4:** testes passam; conferir no navegador (última mensagem, mensagem longa, foto, documento).
- [ ] **Step 5:** `git commit -m "feat: reagir pela carinha ao lado da bolha"`
