<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BubbleFormattingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bolha_formata_o_texto_e_escapa_o_html(): void
    {
        $ticket = Ticket::factory()->for(Channel::factory()->connected()->create())->create();
        Message::factory()->for($ticket)->create(['body' => '*Jessé @ Dimetal:* <b>oi</b>']);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class)
            ->set('ticketId', $ticket->id)
            ->assertSeeHtml('<strong>Jessé @ Dimetal:</strong>')
            ->assertSeeHtml('&lt;b&gt;oi&lt;/b&gt;')
            ->assertDontSeeHtml('<b>oi</b>');
    }
}
