<?php

namespace Tests\Feature\Contacts;

use App\Enums\UserRole;
use App\Livewire\Contacts\ContactCard;
use App\Livewire\Contacts\Index;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChatButtonsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
    }

    private function dispatchHtml(Contact $contact): string
    {
        return "\$dispatch('start-conversation', { contactId: {$contact->id} })";
    }

    public function test_lista_de_contatos_tem_conversar_e_a_janela(): void
    {
        $contact = Contact::factory()->create(['wa_id' => '5516999999999@c.us']);

        Livewire::actingAs($this->me)->test(Index::class)
            ->assertSeeHtml($this->dispatchHtml($contact))
            ->assertSeeLivewire('inbox.new-conversation');
    }

    public function test_contato_so_com_lid_tem_o_botao_desabilitado_com_o_motivo(): void
    {
        $contact = Contact::factory()->create(['wa_id' => null, 'lid' => '12345@lid']);

        Livewire::actingAs($this->me)->test(Index::class)
            ->assertDontSeeHtml($this->dispatchHtml($contact))
            ->assertSeeHtml('data-testid="chat-disabled"');
    }

    public function test_ficha_compacta_e_completa_tem_conversar_mas_o_historico_nao(): void
    {
        $contact = Contact::factory()->create(['wa_id' => '5516999999999@c.us']);

        Livewire::actingAs($this->me)->test(ContactCard::class, ['contactId' => $contact->id, 'compact' => true])
            ->assertSeeHtml($this->dispatchHtml($contact));

        Livewire::actingAs($this->me)->test(ContactCard::class, ['contactId' => $contact->id])
            ->assertSeeHtml($this->dispatchHtml($contact));

        Livewire::actingAs($this->me)->test(ContactCard::class, ['contactId' => $contact->id, 'compact' => true, 'readonly' => true])
            ->assertDontSeeHtml($this->dispatchHtml($contact));
    }
}
