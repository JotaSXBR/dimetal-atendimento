<?php

namespace App\Services\Inbox;

use App\Jobs\SyncContactPhotoJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaContacts;
use Illuminate\Support\Facades\Cache;

/**
 * Guarda a URL da foto de perfil do contato (a do CDN do WhatsApp). A foto em si nunca é baixada nem gravada: a tela a recebe pela
 * rota que a repassa em memória (ver `ContactPhotoProxy`). O WAHA guarda a URL por 24 h, então o ritmo é de uma checagem por dia.
 */
class SyncContactPhoto
{
    /** Resultado definitivo (com foto, sem foto, privacidade fechada): marca a checagem. Falha passageira do WAHA sobe sem marcar. */
    public function __invoke(Contact $contact, Channel $channel): void
    {
        $id = $contact->wa_id ?? $contact->lid;

        if ($id === null) {
            return;
        }

        $url = (new WahaContacts(new WahaClient($channel)))->profilePictureUrl($id);

        $contact->forceFill([
            'avatar_url' => $url !== null && str_starts_with($url, 'https://') ? $url : null,
            'avatar_checked_at' => now(),
        ])->save();
    }

    /**
     * Enfileira a busca no máximo uma vez por dia por contato, e uma tentativa por hora se a anterior falhou. Roda na fila, nunca no
     * caminho do webhook: WAHA lento ou fora do ar não atrasa a ingestão.
     */
    public function queueFor(Contact $contact, Channel $channel): void
    {
        $checked = $contact->avatar_checked_at;

        if ($checked !== null && $checked->gt(now()->subDay())) {
            return;
        }

        if (! Cache::add('contact-photo:'.$contact->id, 1, now()->addHour())) {
            return;
        }

        SyncContactPhotoJob::dispatch($contact->id, $channel->id);
    }
}
