<?php

namespace App\Services\Inbox;

use App\Enums\TicketStatus;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
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

            $contact = $this->findExisting($waId, $lid) ?? new Contact;

            $contact->wa_id ??= $waId;

            if ($lid !== null && $contact->lid === null) {
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
     * O lid pode já pertencer a outro registro (criado antes de o telefone aparecer). Se for um registro só de lid, ele é
     * fundido neste; se a fusão for recusada, o lid fica onde está, sem violar o índice único e travar a ingestão.
     */
    private function claimLid(Contact $contact, string $lid): void
    {
        $owner = Contact::query()->where('lid', $lid)->first();

        if ($owner === null) {
            $contact->lid = $lid;

            return;
        }

        if ($contact->exists && $owner->wa_id === null && $this->mergeLidContact($contact, $owner)) {
            $contact->lid = $lid;
        }
    }

    /** Reaponta tickets e mensagens do registro do lid para o do telefone e remove o do lid; recusa se isso dobrar um ticket aberto. */
    private function mergeLidContact(Contact $phone, Contact $lid): bool
    {
        if ($this->bothHaveOpenTicketOnSameChannel($phone, $lid)) {
            return false;
        }

        Ticket::query()->where('contact_id', $lid->id)->update(['contact_id' => $phone->id]);
        Message::query()->where('contact_id', $lid->id)->update(['contact_id' => $phone->id]);

        // O que só o registro do lid tinha (nome editado, apelido, perfil) não se perde. Se foi o lid quem recebeu a edição
        // humana e o telefone ainda tem só o push name, os dados do lid valem: o push name não pode virar "nome editado".
        $lidWins = $lid->edited_at !== null && $phone->edited_at === null;

        foreach (['name', 'nickname', 'phone2', 'email', 'company', 'job_title', 'address', 'notes', 'birthday', 'urls', 'edited_at'] as $field) {
            if ($lid->getAttribute($field) !== null && ($lidWins || $phone->getAttribute($field) === null)) {
                $phone->setAttribute($field, $lid->getAttribute($field));
            }
        }

        $lid->delete();

        return true;
    }

    private function bothHaveOpenTicketOnSameChannel(Contact $phone, Contact $lid): bool
    {
        $open = fn (Contact $contact) => Ticket::query()->where('contact_id', $contact->id)->where('status', '<>', TicketStatus::ENCERRADO->value);

        return $open($lid)->whereIn('channel_id', $open($phone)->select('channel_id'))->exists();
    }

    private function phoneFromSenderAlt(?string $senderAlt): ?string
    {
        if ($senderAlt === null || ! preg_match('/^([1-9]\d{7,14})(?::\d+)?@s\.whatsapp\.net$/', $senderAlt, $m)) {
            return null;
        }

        return $m[1].'@c.us';
    }
}
