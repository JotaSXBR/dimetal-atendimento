{{-- Nota interna: só a equipe vê, nunca vai ao WhatsApp. Cartão âmbar no fluxo da conversa, sem tiques. --}}
<div wire:key="note-{{ $note->id }}" id="note-{{ $note->id }}" data-testid="note-card" class="group/note rounded-xl border border-warn/30 bg-warn/10 px-3 py-2 text-sm text-ink">
    <p class="mb-0.5 flex items-center gap-1 font-mono text-[10px] tracking-wide text-warn uppercase">
        <flux:icon.lock-closed class="size-3" />
        Nota interna · {{ $note->origin === \App\Enums\NoteOrigin::EQUIPE ? ($note->author?->name ?? 'Usuário removido') : $note->origin->label() }} · {{ $note->created_at->timezone(config('app.display_timezone'))->format('d/m H:i') }}
    </p>
    <p class="break-words whitespace-pre-wrap">{{ \App\Support\WhatsAppText::html($note->body) }}</p>
    @if ($note->user_id === auth()->id())
        <flux:button type="button" size="xs" variant="ghost" class="mt-1" wire:click="deleteNote({{ $note->id }})" wire:confirm="Apagar esta nota?">Apagar</flux:button>
    @endif
</div>
