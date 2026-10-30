<?php

namespace Tests\Feature\Sla;

use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\ResolveContact;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A fusão de contatos (lid + telefone) não pode esconder a espera de um cliente nem reiniciá-la. */
class MergeWaitingTest extends TestCase
{
    use RefreshDatabase;

    private const LID = '16449842241553@lid';

    private Channel $channel;

    private Contact $phone;

    private Contact $lid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-30 13:30:00', 'UTC'));
        $this->channel = Channel::factory()->create();
        $this->phone = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'lid' => null]);
        $this->lid = Contact::factory()->create(['wa_id' => null, 'lid' => self::LID]);
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-10-30 {$time}:00", 'UTC');
    }

    /** @param array<string, mixed> $override */
    private function ticket(Contact $contact, array $override): Ticket
    {
        return Ticket::factory()->for($contact)->for($this->channel)->create(array_replace(['status' => TicketStatus::AGUARDANDO], $override));
    }

    private function merge(): void
    {
        (new ResolveContact)(self::LID, '5516997524950:1@s.whatsapp.net');
    }

    public function test_espera_do_ticket_que_perdeu_vai_para_o_que_ficou(): void
    {
        $agent = User::factory()->create();
        $survivor = $this->ticket($this->phone, [
            'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $agent->id, 'opened_at' => $this->at('09:00'),
            'last_inbound_at' => $this->at('09:30'), 'last_outbound_at' => $this->at('09:40'),
        ]);
        $this->ticket($this->lid, [
            'opened_at' => $this->at('12:00'), 'last_inbound_at' => $this->at('13:00'),
            'waiting_since' => $this->at('13:00'), 'sla_warn_at' => $this->at('13:07'), 'sla_due_at' => $this->at('13:10'),
            'sla_warned_at' => $this->at('13:08'), 'sla_breached_at' => $this->at('13:10'),
        ]);

        $this->merge();

        $kept = $survivor->fresh();
        $this->assertEquals($this->at('13:00'), $kept?->waiting_since);
        $this->assertEquals($this->at('13:10'), $kept->sla_due_at);
        $this->assertEquals($this->at('13:10'), $kept->sla_breached_at);
    }

    public function test_com_duas_esperas_vale_a_mais_antiga_com_as_marcas_dela(): void
    {
        $survivor = $this->ticket($this->phone, [
            'opened_at' => $this->at('09:00'), 'last_inbound_at' => $this->at('13:20'), 'waiting_since' => $this->at('13:20'),
            'sla_warn_at' => $this->at('13:27'), 'sla_due_at' => $this->at('13:30'),
        ]);
        $this->ticket($this->lid, [
            'opened_at' => $this->at('12:00'), 'last_inbound_at' => $this->at('13:00'), 'waiting_since' => $this->at('13:00'),
            'sla_warn_at' => $this->at('13:07'), 'sla_due_at' => $this->at('13:10'), 'sla_warned_at' => $this->at('13:08'),
        ]);

        $this->merge();

        $kept = $survivor->fresh();
        $this->assertEquals($this->at('13:00'), $kept?->waiting_since);
        $this->assertEquals($this->at('13:10'), $kept->sla_due_at);
        $this->assertEquals($this->at('13:08'), $kept->sla_warned_at);
        $this->assertEquals($this->at('13:20'), $kept->last_inbound_at);
    }

    public function test_resposta_do_outro_ticket_depois_da_espera_encerra_a_espera(): void
    {
        $survivor = $this->ticket($this->phone, [
            'opened_at' => $this->at('09:00'), 'last_inbound_at' => $this->at('13:00'), 'waiting_since' => $this->at('13:00'),
            'sla_warn_at' => $this->at('13:07'), 'sla_due_at' => $this->at('13:10'),
        ]);
        $this->ticket($this->lid, [
            'opened_at' => $this->at('12:00'), 'last_inbound_at' => $this->at('12:30'), 'last_outbound_at' => $this->at('13:05'),
        ]);

        $this->merge();

        $kept = $survivor->fresh();
        $this->assertNull($kept?->waiting_since);
        $this->assertNull($kept->sla_due_at);
        $this->assertNull($kept->sla_warn_at);
    }
}
