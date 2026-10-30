<?php

namespace App\Actions\Contacts;

use App\Jobs\SyncContactToGoogleJob;
use App\Models\Contact;
use App\Services\Google\GoogleAccount;
use Illuminate\Database\Eloquent\Builder;

/**
 * Rede de segurança do envio ao Google: enfileira o que ficou para trás (job perdido, conta recém-conectada, erro antigo).
 * Contato com erro só volta depois de uma hora, para um problema persistente não virar martelada na API.
 */
class SyncPendingContacts
{
    private const RETRY_AFTER_ERROR_HOURS = 1;

    public function __construct(private readonly GoogleAccount $account) {}

    /** @return int quantos contatos foram enfileirados */
    public function __invoke(): int
    {
        if (! $this->account->isConnected()) {
            return 0;
        }

        $count = 0;

        Contact::query()
            ->pendingGoogle()
            ->where(fn (Builder $query) => $query
                ->whereNull('google_error')
                ->orWhere('google_attempted_at', '<=', now()->subHours(self::RETRY_AFTER_ERROR_HOURS)))
            ->each(function (Contact $contact) use (&$count): void {
                SyncContactToGoogleJob::dispatch($contact->id);
                $count++;
            });

        return $count;
    }
}
