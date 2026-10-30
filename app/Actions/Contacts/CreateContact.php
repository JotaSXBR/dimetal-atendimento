<?php

namespace App\Actions\Contacts;

use App\Jobs\SyncContactToGoogleJob;
use App\Models\Contact;
use App\Support\PhoneFormat;
use Illuminate\Validation\ValidationException;

/** Cadastro manual: telefone vira o `wa_id`, para a próxima mensagem do cliente cair no mesmo registro. */
class CreateContact
{
    /**
     * @return array{contact: Contact, created: bool}
     */
    public function __invoke(string $phone, string $name): array
    {
        $waId = PhoneFormat::toWaId($phone);

        if ($waId === null) {
            throw ValidationException::withMessages(['phone' => 'Telefone inválido: informe o DDD e o número.']);
        }

        $contact = Contact::query()->firstOrCreate(['wa_id' => $waId], ['name' => trim($name), 'edited_at' => now()]);

        if ($contact->wasRecentlyCreated) {
            SyncContactToGoogleJob::dispatchWhenConnected($contact);
        }

        return ['contact' => $contact, 'created' => $contact->wasRecentlyCreated];
    }
}
