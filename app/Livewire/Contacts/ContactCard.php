<?php

namespace App\Livewire\Contacts;

use App\Actions\Contacts\SaveContact;
use App\Jobs\SyncContactToGoogleJob;
use App\Livewire\Forms\ContactForm;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Google\ContactSyncStatus;
use App\Services\Google\GoogleAccount;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/** Ficha editável do contato: vive no painel do inbox e na tela Contatos. */
class ContactCard extends Component
{
    private const HISTORY_LIMIT = 10;

    #[Locked]
    public int $contactId;

    public ContactForm $form;

    /** No Inbox a ficha abre em resumo; o formulário só aparece em "Editar". Em Contatos ela é sempre o formulário. */
    public bool $compact = false;

    public bool $editing = false;

    /** O ticket em que a ficha está aberta: não entra na lista de atendimentos anteriores. */
    #[Locked]
    public ?int $currentTicketId = null;

    /** Histórico: só leitura, sem o botão Editar. */
    #[Locked]
    public bool $readonly = false;

    public function mount(int $contactId, bool $compact = false, bool $readonly = false, ?int $currentTicketId = null): void
    {
        $this->currentTicketId = $currentTicketId;
        $this->contactId = $contactId;
        $this->compact = $compact;
        $this->readonly = $readonly;
        $this->form->fillFrom($this->contact());
    }

    public function toggleEdit(): void
    {
        abort_if($this->readonly, 403);

        $this->editing = ! $this->editing;
        $this->form->fillFrom($this->contact());
        $this->resetErrorBag();
    }

    public function save(SaveContact $saveContact): void
    {
        abort_if($this->readonly, 403);

        $this->form->validate();

        $saveContact($this->contact(), $this->form->payload());
        $this->editing = false;

        $this->dispatch('contact-saved', contactId: $this->contactId);
    }

    /** Reenvia ao Google um contato que deu erro: zera o erro e a tentativa e enfileira de novo. */
    public function resync(GoogleAccount $account): void
    {
        abort_if($this->readonly, 403);

        if (! $account->isConnected()) {
            return;
        }

        $contact = $this->contact();
        $contact->forceFill(['google_error' => null, 'google_attempted_at' => null])->save();

        SyncContactToGoogleJob::dispatchWhenConnected($contact);
    }

    public function addUrl(): void
    {
        if (count($this->form->urls) < ContactForm::MAX_URLS) {
            $this->form->urls[] = '';
        }
    }

    public function removeUrl(int $index): void
    {
        unset($this->form->urls[$index]);
        $this->form->urls = array_values($this->form->urls);
    }

    private function contact(): Contact
    {
        return Contact::query()->findOrFail($this->contactId);
    }

    /** Um ticket do contato mudou: a lista de atendimentos anteriores se atualiza, menos com o formulário aberto. */
    #[On('echo-private:inbox,.ticket.updated')]
    public function refresh(): void
    {
        if ($this->editing || ! $this->compact) {
            $this->skipRender();
        }
    }

    public function render(ContactSyncStatus $syncStatus): View
    {
        $contact = $this->contact();
        $user = auth()->user();
        assert($user instanceof User);

        $history = Ticket::query()->with(['sector', 'channel'])->where('contact_id', $contact->id)->when($this->currentTicketId !== null, fn ($query) => $query->whereKeyNot($this->currentTicketId))->latest('opened_at')->limit(self::HISTORY_LIMIT)->get();

        return view('livewire.contacts.contact-card', [
            'contact' => $contact,
            'google' => $syncStatus->describe($contact),
            'history' => $history,
            'openable' => Ticket::query()->readableBy($user)->whereIn('id', $history->modelKeys())->pluck('id')->all(),
            'currentChannelId' => $this->currentTicketId === null ? null : Ticket::query()->whereKey($this->currentTicketId)->value('channel_id'),
        ]);
    }
}
