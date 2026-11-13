# Etiquetas da conversa pela tela: plano

> **Para quem executa:** `superpowers:executing-plans`, inline, RED antes do código.

**Meta:** pôr e tirar etiqueta da conversa na tela. **Spec:** `docs/superpowers/specs/2026-11-13-etiquetas-da-conversa-design.md`
**Arquitetura:** `App\Livewire\Inbox\TicketTags` (`ticketId` locked, `readonly` locked, `addTagId`), view `livewire/inbox/ticket-tags.blade.php`, usado em `x-ticket-details`; textos de auditoria em `TicketEventText`/`TicketTimeline`.

## Global Constraints
- Só beta; PR sem merge; Blade com Alpine só CSP; UI em 360/720p/1080p/2K claro e escuro.

## Review Focus
- Usuário sem acesso ao ticket: não lê nem altera. Ticket encerrado e `readonly`: 403/sem efeito.
- Etiqueta de contato nunca entra na conversa; etiqueta já aplicada não duplica evento.
- Remover etiqueta que não está no ticket não grava evento.

### Tarefa 1: Componente e auditoria
**Files:** `app/Livewire/Inbox/TicketTags.php`, `resources/views/livewire/inbox/ticket-tags.blade.php`, `app/Support/TicketEventText.php`, `app/Support/TicketTimeline.php`, `tests/Feature/Livewire/TicketTagsTest.php`.
- [ ] RED: aplica/remove (inclusive a do bot) com evento e `TicketUpdated`; recusa escopo contato; sem duplicar; sem acesso; encerrado/readonly; linha do tempo legível.
- [ ] GREEN.

### Tarefa 2: Painel
**Files:** `resources/views/components/ticket-details.blade.php`, `tests/Feature/Livewire/TicketDetailsBlocksTest.php`.
- [ ] RED: Inbox mostra o seletor; histórico só chips; linha "Etiquetas" do bloco Ticket removida.
- [ ] GREEN; verificação visual nos dois temas e 360 px.
