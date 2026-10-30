<?php

namespace App\Livewire\Settings;

use App\Models\Contact;
use App\Services\Google\GoogleAccount;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Google Contatos')]
class GoogleContacts extends Component
{
    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function disconnect(GoogleAccount $account): void
    {
        $this->authorize('admin');

        $account->disconnect();
    }

    public function render(GoogleAccount $account): View
    {
        return view('livewire.settings.google-contacts', [
            'connection' => $account->current(),
            'synced' => Contact::query()->whereNotNull('google_synced_at')->count(),
            // Editado e ainda não enviado (ou alterado depois do último envio).
            'pending' => Contact::query()->whereNotNull('edited_at')->whereNull('google_synced_at')->count(),
            'failed' => Contact::query()->whereNotNull('google_error')->count(),
        ]);
    }
}
