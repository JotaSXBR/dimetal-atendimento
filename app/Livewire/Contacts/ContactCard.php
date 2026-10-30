<?php

namespace App\Livewire\Contacts;

use App\Actions\Contacts\SaveContact;
use App\Jobs\SyncContactToGoogleJob;
use App\Livewire\Forms\ContactForm;
use App\Models\Contact;
use App\Models\Ticket;
use App\Services\Google\ContactSyncStatus;
use App\Services\Google\GoogleAccount;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Ficha editável do contato: vive no painel do inbox e na tela Contatos. */
class ContactCard extends Component
{
    private const HISTORY_LIMIT = 10;

    #[Locked]
    public int $contactId;

    public ContactForm $form;

    public function mount(int $contactId): void
    {
        $this->contactId = $contactId;
        $this->form->fillFrom($this->contact());
    }

    public function save(SaveContact $saveContact): void
    {
        $this->form->validate();

        $saveContact($this->contact(), $this->form->payload());

        $this->dispatch('contact-saved', contactId: $this->contactId);
    }

    /** Reenvia ao Google um contato que deu erro: zera o erro e a tentativa e enfileira de novo. */
    public function resync(GoogleAccount $account): void
    {
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

    public function render(ContactSyncStatus $syncStatus): View
    {
        $contact = $this->contact();

        return view('livewire.contacts.contact-card', [
            'contact' => $contact,
            'google' => $syncStatus->describe($contact),
            'history' => Ticket::query()->with('sector')->where('contact_id', $contact->id)->latest('opened_at')->limit(self::HISTORY_LIMIT)->get(),
        ]);
    }
}
