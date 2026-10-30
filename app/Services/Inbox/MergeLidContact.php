<?php

namespace App\Services\Inbox;

use App\Enums\ClosedReason;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Funde o registro que só tem o lid no registro do telefone, quando o WhatsApp revela os dois do mesmo cliente.
 * Roda dentro da transação e do lock de `ResolveContact`. Nenhuma mensagem se perde: tickets e mensagens do lid passam para
 * o telefone e, se os dois tinham ticket aberto no mesmo canal, as conversas viram uma só.
 */
class MergeLidContact
{
    private const PROFILE_FIELDS = ['name', 'nickname', 'phone2', 'email', 'company', 'job_title', 'address', 'notes', 'birthday', 'urls', 'edited_at'];

    /** O espelho do Google do registro do lid não fica órfão: o telefone passa a apontar para o mesmo contato lá. */
    private const MIRROR_FIELDS = ['google_resource_name', 'google_etag', 'google_payload_hash', 'google_synced_at'];

    public function __invoke(Contact $phone, Contact $lid): void
    {
        $this->mergeOpenTickets($phone, $lid);

        Ticket::query()->where('contact_id', $lid->id)->update(['contact_id' => $phone->id]);
        Message::query()->where('contact_id', $lid->id)->update(['contact_id' => $phone->id]);

        $this->mergeProfile($phone, $lid);

        // Antes de o telefone ser salvo: o `google_resource_name` é único.
        $lid->delete();
    }

    private function mergeOpenTickets(Contact $phone, Contact $lid): void
    {
        $phoneTickets = $this->openTickets($phone);

        foreach ($this->openTickets($lid) as $channelId => $lidTicket) {
            $phoneTicket = $phoneTickets->get($channelId);

            if ($phoneTicket !== null) {
                $this->mergeTickets($phoneTicket, $lidTicket);
            }
        }
    }

    /**
     * @return Collection<int, Ticket> abertos do contato, por canal
     */
    private function openTickets(Contact $contact): Collection
    {
        return Ticket::query()->where('contact_id', $contact->id)->where('status', '<>', TicketStatus::ENCERRADO->value)->get()->keyBy('channel_id');
    }

    /** Fica o ticket que já tem atendente; empatando, o mais antigo. O outro é encerrado como "mesclado". */
    private function mergeTickets(Ticket $first, Ticket $second): void
    {
        $ordered = [$first, $second];
        usort($ordered, fn (Ticket $x, Ticket $y): int => [$x->assigned_user_id === null, $x->opened_at->getTimestamp(), $x->id] <=> [$y->assigned_user_id === null, $y->opened_at->getTimestamp(), $y->id]);
        [$survivor, $loser] = $ordered;

        Message::query()->where('ticket_id', $loser->id)->update(['ticket_id' => $survivor->id]);

        $survivor->forceFill([
            'last_inbound_at' => $this->later($survivor->last_inbound_at, $loser->last_inbound_at),
            'last_outbound_at' => $this->later($survivor->last_outbound_at, $loser->last_outbound_at),
            'first_human_reply_at' => $this->earlier($survivor->first_human_reply_at, $loser->first_human_reply_at),
        ])->save();

        $loser->forceFill(['status' => TicketStatus::ENCERRADO, 'closed_at' => now(), 'closed_reason' => ClosedReason::MESCLADO])->save();
        TicketEvent::create(['ticket_id' => $loser->id, 'type' => 'merged', 'payload' => ['into_ticket_id' => $survivor->id]]);

        TicketUpdated::dispatch($survivor);
        TicketUpdated::dispatch($loser);
    }

    private function later(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        return $a === null || ($b !== null && $b->gt($a)) ? $b : $a;
    }

    private function earlier(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        return $a === null || ($b !== null && $b->lt($a)) ? $b : $a;
    }

    /**
     * O que só o registro do lid tinha (nome editado, apelido, perfil) não se perde. Se foi o lid quem recebeu a edição
     * humana e o telefone ainda tem só o push name, os dados do lid valem: o push name não pode virar "nome editado".
     */
    private function mergeProfile(Contact $phone, Contact $lid): void
    {
        $lidWins = $lid->edited_at !== null && $phone->edited_at === null;

        foreach (self::PROFILE_FIELDS as $field) {
            if ($lid->getAttribute($field) !== null && ($lidWins || $phone->getAttribute($field) === null)) {
                $phone->setAttribute($field, $lid->getAttribute($field));
            }
        }

        if ($phone->google_resource_name === null) {
            foreach (self::MIRROR_FIELDS as $field) {
                $phone->setAttribute($field, $lid->getAttribute($field));
            }
        }
    }
}
