<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Contacts\Index as ContactsIndex;
use App\Livewire\History\Index as HistoryIndex;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContactPhotoViewTest extends TestCase
{
    use RefreshDatabase;

    private const CDN = 'https://pps.whatsapp.net/v/t61/foto.jpg?sig=abc';

    private function ticketFor(Contact $contact, User $owner, TicketStatus $status = TicketStatus::EM_ATENDIMENTO): Ticket
    {
        return Ticket::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => Channel::factory()->create()->id,
            'status' => $status,
            'assigned_user_id' => $owner->id,
        ]);
    }

    public function test_fila_e_cabecalho_do_ticket_mostram_a_foto_do_contato_pela_rota_propria(): void
    {
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $contact = Contact::factory()->create(['avatar_url' => self::CDN]);
        $ticket = $this->ticketFor($contact, $me);
        $route = '/avatares/contacts/'.$contact->id;

        $html = Livewire::actingAs($me)->test(Inbox::class)->assertSee($route, false)->call('select', $ticket->id)->html();

        $this->assertGreaterThanOrEqual(2, substr_count($html, $route));
        $this->assertStringNotContainsString('pps.whatsapp.net', $html);
    }

    public function test_contato_sem_foto_fica_com_monograma(): void
    {
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $contact = Contact::factory()->create(['avatar_url' => null, 'name' => 'Maria Souza']);
        $this->ticketFor($contact, $me);

        Livewire::actingAs($me)->test(Inbox::class)
            ->assertDontSee('/avatares/contacts/'.$contact->id, false)
            ->assertSee('MS');
    }

    public function test_contatos_e_historico_mostram_a_foto(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $contact = Contact::factory()->create(['avatar_url' => self::CDN]);
        $this->ticketFor($contact, $admin, TicketStatus::ENCERRADO)->forceFill(['closed_at' => now()])->save();
        $route = '/avatares/contacts/'.$contact->id;

        Livewire::actingAs($admin)->test(ContactsIndex::class)->assertSee($route, false);
        Livewire::actingAs($admin)->test(HistoryIndex::class)->assertSee($route, false);
    }
}
