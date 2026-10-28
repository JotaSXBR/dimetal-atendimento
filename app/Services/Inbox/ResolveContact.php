<?php

namespace App\Services\Inbox;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Acha ou cria o contato de um remetente do WhatsApp.
 *
 * O WhatsApp entrega parte dos remetentes como `<id>@lid`. Quando o payload traz o telefone real em
 * `SenderAlt`, ele vira a identidade do contato e o lid fica guardado ao lado.
 *
 * Duas mensagens do mesmo remetente podem chegar em workers diferentes; o lock por remetente evita
 * que ambos façam "procura, não acha, insere" e um deles estoure o índice único.
 */
class ResolveContact
{
    private const NAME_LIMIT = 120;

    public function __invoke(string $from, ?string $senderAlt = null, ?string $displayName = null): Contact
    {
        return DB::transaction(function () use ($from, $senderAlt, $displayName): Contact {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['contact:'.$from]);

            $lid = str_ends_with($from, '@lid') ? $from : null;
            $waId = $lid === null ? $from : $this->phoneFromSenderAlt($senderAlt);
            $name = $displayName === null ? null : mb_substr(trim($displayName), 0, self::NAME_LIMIT);

            $contact = ($waId !== null ? Contact::query()->where('wa_id', $waId)->first() : null)
                ?? ($lid !== null ? Contact::query()->where('lid', $lid)->first() : null)
                ?? new Contact;

            $contact->wa_id ??= $waId;

            // O mesmo lid pode já pertencer a outro registro (contato criado antes de o telefone aparecer):
            // não o reatribui, para não violar o índice único e travar toda a ingestão desse remetente.
            if ($lid !== null && $contact->lid === null && ! $this->lidTaken($lid)) {
                $contact->lid = $lid;
            }

            if ($name !== null && $name !== '') {
                $contact->name = $name;
            }

            $contact->save();

            return $contact;
        });
    }

    private function lidTaken(string $lid): bool
    {
        return Contact::query()->where('lid', $lid)->exists();
    }

    private function phoneFromSenderAlt(?string $senderAlt): ?string
    {
        if ($senderAlt === null || ! preg_match('/^([1-9]\d{7,14})(?::\d+)?@s\.whatsapp\.net$/', $senderAlt, $m)) {
            return null;
        }

        return $m[1].'@c.us';
    }
}
