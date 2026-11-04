<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** O WAHA exige `{fromMe}_{chat}_{id}`; o app guarda só o final e o chat varia entre `@c.us` e `@lid`. */
class MessageFullIdTest extends TestCase
{
    use RefreshDatabase;

    private ?Channel $channel = null;

    private function message(Contact $contact, MessageDirection $direction, ?string $waId = '3EB0ABC'): Message
    {
        $channel = $this->channel ??= Channel::factory()->create();
        $ticket = Ticket::query()->where('contact_id', $contact->id)->first() ?? Ticket::factory()->for($contact)->for($channel)->create();

        return Message::factory()->for($ticket)->create(['direction' => $direction, 'wa_message_id' => $waId ?? null]);
    }

    public function test_id_completo_de_entrada_e_de_saida_com_wa_id(): void
    {
        $contact = Contact::factory()->create(['wa_id' => '5516997524950@c.us']);

        $this->assertSame('false_5516997524950@c.us_3EB0ABC', $this->message($contact, MessageDirection::IN)->fullWaId());
        $this->assertSame('true_5516997524950@c.us_3EB0XYZ', $this->message($contact, MessageDirection::OUT, '3EB0XYZ')->fullWaId());
    }

    public function test_contato_so_com_lid_usa_o_lid_como_chat(): void
    {
        $contact = Contact::factory()->create(['wa_id' => null, 'lid' => '1234567890@lid']);

        $this->assertSame('false_1234567890@lid_3EB0ABC', $this->message($contact, MessageDirection::IN)->fullWaId());
    }

    public function test_sem_wa_message_id_devolve_null(): void
    {
        $contact = Contact::factory()->create(['wa_id' => '5516997524950@c.us']);

        $this->assertNull($this->message($contact, MessageDirection::OUT, null)->fullWaId());
    }
}
