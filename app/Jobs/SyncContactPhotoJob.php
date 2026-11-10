<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Contact;
use App\Services\Inbox\SyncContactPhoto;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Busca no WAHA a URL da foto do contato (a foto em si nunca é baixada; ver {@see SyncContactPhoto}). A foto é enfeite: falha do WAHA
 * (429, 5xx, fora do ar) só é registrada e não marca a checagem, então a próxima mensagem do contato, depois de 1 h, tenta de novo.
 */
class SyncContactPhotoJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $contactId, public readonly int $channelId)
    {
        $this->onQueue('media');
    }

    public function handle(SyncContactPhoto $sync): void
    {
        $contact = Contact::query()->find($this->contactId);
        $channel = Channel::query()->find($this->channelId);

        // O contato pode ter sido fundido (lid -> telefone) antes de o job rodar.
        if ($contact === null || $channel === null) {
            return;
        }

        try {
            $sync($contact, $channel);
        } catch (Throwable $e) {
            Log::warning('Foto do contato: o WAHA não respondeu', ['contact_id' => $contact->id, 'error' => $e->getMessage()]);
        }
    }
}
