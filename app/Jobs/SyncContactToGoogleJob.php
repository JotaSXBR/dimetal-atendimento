<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Services\Google\GoogleAccount;
use App\Services\Google\SyncContactToGoogle;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envia um contato ao Google. Fila própria (`google`), com um processo só: o People API pede escrita sequencial e uma
 * lentidão dele não pode atrasar mensagem do WhatsApp. Sem repetição: quem reenvia é a varredura (`SyncPendingContacts`).
 * O bloqueio de unicidade cai quando o job começa, para uma edição feita durante o envio entrar na fila em seguida.
 */
class SyncContactToGoogleJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Menos que o `--timeout=120` do worker: 429 com espera de até 30 s cabe.
    public int $timeout = 110;

    public function __construct(public readonly int $contactId)
    {
        $this->onQueue('google');
    }

    /** Enfileira o envio se há conta Google conectada; sem ela não há o que enviar. */
    public static function dispatchWhenConnected(Contact $contact): void
    {
        if (app(GoogleAccount::class)->isConnected()) {
            self::dispatch($contact->id)->afterCommit();
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->contactId;
    }

    public function handle(SyncContactToGoogle $sync): void
    {
        $contact = Contact::query()->find($this->contactId);

        if ($contact !== null) {
            $sync($contact);
        }
    }
}
