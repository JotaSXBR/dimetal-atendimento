<?php

namespace App\Livewire\Contacts;

use App\Actions\Contacts\SaveContact;
use App\Livewire\Forms\ContactForm;
use App\Models\Contact;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Ficha editável do contato: vive no painel do inbox e na tela Contatos. */
class ContactCard extends Component
{
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

    public function render(): View
    {
        return view('livewire.contacts.contact-card', ['contact' => $this->contact()]);
    }
}
