# Bug do sino de alertas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Corrigir os três achados do dono no sino: (1) alertas repetidos, (2) alerta "0 mensagens em 0 conversas", (3) o clique no alerta de SLA não abre o ticket.

**Architecture:** Mudanças em `App\Livewire\Alerts\Bell` (abrir o ticket ao clicar; juntar os alertas de SLA repetidos do mesmo ticket) e em `RestoreDatabase` (a recuperação pós-restauração só avisa se trouxe algo). Sem migração.

**Tech Stack:** Laravel 13, Livewire 4.4, PHPUnit.

**Spec:** achados do dono (conversa de 14/11) e evidência do beta (sino do usuário Jessé): 15 itens, entre eles "SLA estourado"/"SLA perto de estourar" repetidos para o mesmo ticket (000022 em 6 alertas, 000027 duas vezes em 18 min), "Mensagens recuperadas — Motorola Edge 60: 0 mensagens em 0 conversas" (2×, no mesmo minuto de "Banco restaurado") e itens cujo único efeito é `markRead`.

## Causas (systematic-debugging, fase 1)

- **Clique:** `bell.blade.php` liga cada item a `markRead('<id>')`; a notificação de SLA já guarda `ticket_id` (`SlaWarning`/`SlaBreached::toArray`), mas nada navega. Falta a ação "abrir".
- **"0 mensagens":** `RestoreDatabase` despacha `RecoverMessagesJob(..., notifyAlways: true)`, que avisa todos mesmo com 0; o teste atual trava isso como intenção, mas a mensagem não informa nada.
- **Repetidos:** o SLA avisa uma vez por *espera* (as marcas `sla_warned_at`/`sla_breached_at` zeram quando o cliente é respondido ou o ticket é transferido), então um ticket que volta a esperar gera novo par aviso+estouro; o sino lista cada um como item separado, mais o par "perto de estourar" + "estourado" do mesmo ciclo. É por desenho do relógio, e o ruído está na lista, não no disparo.

## Global Constraints

- O disparo de SLA e a faixa não mudam; só a apresentação no sino. Quem não vê mais o ticket não ganha link (`visibleTo`).
- Texto em português; nada novo de dado de cliente no sino.

## Review Focus

- **Clique:** só navega se o ticket ainda é visível ao usuário; alerta de canal/backup só marca como lido; id de outro usuário não marca nada.
- **Juntar SLA:** o mais recente por ticket vence (estouro > aviso do mesmo instante); marcar como lido limpa todos os SLA daquele ticket; o contador do selo bate com o que a lista mostra.
- **0 mensagens:** recuperação manual (botão) continua dando retorno; só a pós-restauração deixa de avisar com zero.

### Task 1: abrir o ticket ao clicar
Files: `app/Livewire/Alerts/Bell.php`, `resources/views/livewire/alerts/bell.blade.php`, `tests/Feature/Alertas/BellTest.php`. Produces `Bell::open(string $id): void` (marca como lida; se há `ticket_id` visível, `redirect(route('inbox', ['ticket' => id]), navigate: true)`).

### Task 2: recuperação pós-restauração sem aviso vazio
Files: `app/Services/Backup/Restore/RestoreDatabase.php`, `tests/Feature/Backup/Restore/RestoreDatabaseTest.php`.

### Task 3: juntar os alertas de SLA repetidos
Files: `app/Livewire/Alerts/Bell.php`, `tests/Feature/Alertas/BellTest.php`. Regra: por `ticket_id`, só o SLA mais recente aparece; `markRead`/`open` limpam os SLA do mesmo ticket; `unread` desconta os escondidos.

### Task 4: verificação (beta e local), revisão e PR (portões 6 e 7, ledger, PR, merge com CI verde).
