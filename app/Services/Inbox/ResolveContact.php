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
            $lid = str_ends_with($from, '@lid') ? $from : null;
            $waId = $lid === null ? $from : $this->phoneFromSenderAlt($senderAlt);
            $this->lockIdentities($from, $waId);
            $name = $displayName === null ? null : mb_substr(trim($displayName), 0, self::NAME_LIMIT);

            $contact = $this->findExisting($waId, $lid) ?? new Contact;

            $contact->wa_id ??= $waId;

            // O lid também pode mudar para o mesmo telefone: o atual substitui o guardado (e funde o registro só de lid dele).
            if ($lid !== null && $contact->lid !== $lid) {
                $this->claimLid($contact, $lid);
            }

            // O push name é o que o cliente escolheu no WhatsApp: serve de nome até alguém editar o contato; depois, nunca.
            if ($name !== null && $name !== '' && $contact->edited_at === null) {
                $contact->name = $name;
            }

            $contact->save();

            return $contact;
        });
    }

    private function findExisting(?string $waId, ?string $lid): ?Contact
    {
        $byPhone = $waId === null ? null : Contact::query()->where('wa_id', $waId)->first();

        return $byPhone ?? ($lid === null ? null : Contact::query()->where('lid', $lid)->first());
    }

    /**
     * O lid pode já pertencer a outro registro (criado antes de o telefone aparecer). Sendo um registro só de lid, ele é
     * fundido neste; qualquer outro dono (que já tem telefone) o mantém, sem violar o índice único e travar a ingestão.
     */
    private function claimLid(Contact $contact, string $lid): void
    {
        $owner = Contact::query()->where('lid', $lid)->first();

        if ($owner === null) {
            $contact->lid = $lid;

            return;
        }

        if ($contact->exists && $owner->wa_id === null) {
            app(MergeLidContact::class)($contact, $owner);
            $contact->lid = $lid;
        }
    }

    /**
     * Trava o remetente e, quando o lid revela o telefone, o telefone também (em ordem fixa, para duas ingestões não se
     * travarem uma à outra): a mensagem do lid e a do telefone do mesmo cliente não correm juntas.
     */
    private function lockIdentities(string $from, ?string $waId): void
    {
        $keys = array_unique(array_filter(['contact:'.$from, $waId === null ? null : 'contact:'.$waId]));
        sort($keys);

        foreach ($keys as $key) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [$key]);
        }
    }

    private function phoneFromSenderAlt(?string $senderAlt): ?string
    {
        if ($senderAlt === null || ! preg_match('/^([1-9]\d{7,14})(?::\d+)?@s\.whatsapp\.net$/', $senderAlt, $m)) {
            return null;
        }

        return $m[1].'@c.us';
    }
}
