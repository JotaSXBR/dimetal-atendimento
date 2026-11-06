<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Contact;
use App\Services\Inbox\FillContactName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Busca no WAHA o nome do contato (ver {@see FillContactName}). O nome é enfeite: falha do WAHA só é registrada. */
class FillContactNameJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $contactId, public readonly int $channelId)
    {
        $this->onQueue('inbound');
    }

    public function handle(FillContactName $fill): void
    {
        $contact = Contact::query()->find($this->contactId);
        $channel = Channel::query()->find($this->channelId);

        // O contato pode ter sido fundido (lid → telefone) antes de o job rodar.
        if ($contact === null || $channel === null) {
            return;
        }

        try {
            $fill($contact, $channel);
        } catch (Throwable $e) {
            Log::warning('Nome do contato não resolvido', ['contato' => $contact->id, 'erro' => $e->getMessage()]);
        }
    }
}
