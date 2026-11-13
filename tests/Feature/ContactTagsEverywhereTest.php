<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Contacts\Index as ContactsIndex;
use App\Livewire\History\Index as HistoryIndex;
use App\Livewire\Inbox\NewConversation;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A etiqueta do contato aparece nas listas além da Fila: Contatos (com filtro), Histórico (lista e painel) e Nova conversa. */
class ContactTagsEverywhereTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    }

    public function test_contatos_filtra_pela_etiqueta_do_contato_e_mostra_os_chips_na_linha(): void
    {
        $tag = Tag::factory()->contato()->create(['name' => 'Revenda']);
        Tag::factory()->create(['name' => 'Só da conversa']);
        Contact::factory()->create(['name' => 'Com Etiqueta'])->tags()->attach($tag);
        Contact::factory()->create(['name' => 'Sem Etiqueta']);

        $page = Livewire::actingAs($this->admin)->test(ContactsIndex::class)
            ->assertSee('Com Etiqueta')->assertSee('Sem Etiqueta')->assertSeeHtml('data-testid="contact-chip"')
            ->assertDontSee('Só da conversa');

        $page->set('tagId', $tag->id)->assertSee('Com Etiqueta')->assertDontSee('Sem Etiqueta');
    }

    public function test_historico_mostra_a_etiqueta_do_contato_na_lista_e_no_painel(): void
    {
        $contact = Contact::factory()->create(['name' => 'Cliente Antigo']);
        $contact->tags()->attach(Tag::factory()->contato()->create(['name' => 'Inadimplente']));
        $ticket = Ticket::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => Channel::factory()->create()->id,
            'status' => TicketStatus::ENCERRADO,
            'closed_at' => now()->subHour(),
        ]);

        Livewire::actingAs($this->admin)->test(HistoryIndex::class)
            ->assertSeeHtml('data-testid="history-chip"')
            ->call('select', $ticket->id)
            ->assertSeeHtml('data-testid="history-panel-chip"');
    }

    public function test_nova_conversa_mostra_a_etiqueta_do_contato_nos_resultados(): void
    {
        Contact::factory()->create(['name' => 'Maria Souza'])->tags()->attach(Tag::factory()->contato()->create(['name' => 'VIP']));

        Livewire::actingAs($this->admin)->test(NewConversation::class)
            ->set('search', 'Maria')
            ->assertSeeHtml('data-testid="match-chip"');
    }
}
