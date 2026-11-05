<?php

namespace App\Livewire\Inbox\Concerns;

use App\Events\TicketUpdated;
use App\Models\TicketNote;

/**
 * Nota interna: usa o mesmo campo (`draft`) da resposta, mas grava em `ticket_notes` e não toca no fluxo de envio.
 * O componente que usa precisa de `ComposesMessages` (campo e freio de ritmo) e de `selected()`/`user()`.
 */
trait TakesNotes
{
    private const NOTE_LIMIT = 4096;

    public function saveNote(): void
    {
        $ticket = $this->selected();
        $this->authorize('note', $ticket);

        $this->draft = trim($this->draft);
        $this->resetErrorBag();
        $this->validate(
            ['draft' => ['required', 'string', 'max:'.self::NOTE_LIMIT]],
            ['draft.required' => 'Escreva a nota.', 'draft.max' => 'A nota passa de '.self::NOTE_LIMIT.' caracteres.'],
        );

        if (! $this->reserveSends(1)) {
            $this->addError('draft', 'Você está enviando mensagens rápido demais. Aguarde alguns segundos.');

            return;
        }

        TicketNote::create(['ticket_id' => $ticket->id, 'user_id' => $this->user()->id, 'body' => $this->draft]);
        TicketUpdated::dispatch($ticket);

        $this->reset('draft');
        $this->dispatch('transcript-refresh');
    }
}
