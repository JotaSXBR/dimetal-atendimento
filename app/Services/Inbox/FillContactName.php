<?php

namespace App\Services\Inbox;

use App\Jobs\FillContactNameJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaContacts;
use Illuminate\Support\Facades\Cache;

/**
 * Dá ao contato o nome que o WhatsApp tem para ele: primeiro o salvo na agenda do celular, senão o push name (que só preenche
 * contato sem nome). Contato editado à mão nunca muda. O nome da agenda fica marcado (`name_source`) para o push name das
 * próximas mensagens não o apagar.
 */
class FillContactName
{
    public function __invoke(Contact $contact, Channel $channel): void
    {
        $id = $contact->wa_id ?? $contact->lid;

        if ($id === null || $contact->edited_at !== null) {
            return;
        }

        $names = (new WahaContacts(new WahaClient($channel)))->contactNames($id);

        if ($names['agenda'] !== null) {
            $contact->forceFill(['name' => $names['agenda'], 'name_source' => 'agenda'])->save();
        } elseif ($names['push'] !== null && ($contact->name === null || $contact->name === '')) {
            $contact->forceFill(['name' => $names['push']])->save();
        }
    }

    /** Enfileira a consulta, no máximo uma vez por dia por contato, enquanto ele não tem nome da agenda e ninguém o editou. */
    public function queueFor(Contact $contact, Channel $channel): void
    {
        if ($contact->edited_at !== null || $contact->name_source === 'agenda' || ! Cache::add('contact-name:'.$contact->id, 1, now()->addDay())) {
            return;
        }

        FillContactNameJob::dispatch($contact->id, $channel->id);
    }
}
