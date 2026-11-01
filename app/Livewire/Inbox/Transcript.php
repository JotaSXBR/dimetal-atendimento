<?php

namespace App\Livewire\Inbox;

use App\Jobs\MarkTicketSeenJob;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * As mensagens do ticket aberto. Vive fora do `Inbox` para que só um evento de mensagem (ou o `transcript-refresh`
 * que o `Inbox` dispara depois de enviar) o renderize: digitar, abrir painéis e eventos da lista não o tocam.
 */
class Transcript extends Component
{
    private const LIMIT = 200;

    #[Locked]
    public int $ticketId;

    /** @param  array<string, mixed>  $payload */
    #[On('echo-private:inbox,.message.created')]
    #[On('echo-private:inbox,.message.status')]
    #[On('echo-private:inbox,.ticket.updated')]
    public function refreshForEvent(array $payload = []): void
    {
        $id = $payload['ticket_id'] ?? null;

        if (! is_numeric($id) || (int) $id !== $this->ticketId) {
            $this->skipRender();

            return;
        }

        // Só `message.created` traz `direction`. Mensagem do cliente no ticket que está na tela: o atendente a vê, o cliente recebe o "visto".
        if (($payload['direction'] ?? null) === 'in') {
            MarkTicketSeenJob::dispatch($this->ticketId);
        }
    }

    #[On('transcript-refresh')]
    public function refresh(): void {}

    public function render(): View
    {
        $user = auth()->user();
        assert($user instanceof User);

        // O filho não confia no pai: confere de novo se o usuário pode ver este ticket.
        $ticket = Ticket::query()->readableBy($user)->find($this->ticketId);

        return view('livewire.inbox.messages', [
            'messages' => $ticket?->messages()->latest('id')->limit(self::LIMIT)->get()->reverse() ?? collect(),
        ]);
    }
}
