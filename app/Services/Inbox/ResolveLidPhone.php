<?php

namespace App\Services\Inbox;

use App\Models\Channel;
use App\Models\Contact;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaContacts;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Descobre no WAHA o telefone de um contato que só tem o `@lid` e o grava pelo caminho normal (`ResolveContact`), que funde
 * o registro com o do telefone se já existir. Nunca lança: sem resposta, o contato segue como "Número oculto".
 */
class ResolveLidPhone
{
    public function __construct(private readonly ResolveContact $resolveContact) {}

    public function __invoke(Channel $channel, string $lid): void
    {
        if (! Contact::query()->where('lid', $lid)->whereNull('wa_id')->exists()) {
            return;
        }

        try {
            $phone = (new WahaContacts(new WahaClient($channel)))->phoneForLid($lid);
            $digits = $phone === null ? '' : (string) preg_replace('/\D/', '', explode('@', $phone)[0]);

            if ($digits !== '') {
                $contact = ($this->resolveContact)($lid, $digits.'@s.whatsapp.net');
                app(FillContactName::class)->queueFor($contact, $channel);
            }
        } catch (Throwable $e) {
            Log::warning('Importação: telefone do lid não resolvido', ['canal' => $channel->id, 'lid' => $lid, 'erro' => $e->getMessage()]);
        }
    }
}
