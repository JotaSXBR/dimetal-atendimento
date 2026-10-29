<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Contacts\ContactCard;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ContactCardTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contact = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'name' => 'Push Name']);
        $this->user = User::factory()->create(['role' => UserRole::ATENDENTE]);
    }

    private function card(): Testable
    {
        return Livewire::actingAs($this->user)->test(ContactCard::class, ['contactId' => $this->contact->id]);
    }

    public function test_edita_e_salva_o_perfil_marcando_a_edicao(): void
    {
        $this->card()
            ->set('form.name', 'Maria Silva')
            ->set('form.company', 'Metalúrgica Silva')
            ->set('form.email', 'maria@silva.com.br')
            ->set('form.birthday', '1990-02-28')
            ->set('form.urls', ['https://silva.com.br'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('contact-saved');

        $fresh = $this->contact->fresh();
        $this->assertSame('Maria Silva', $fresh?->name);
        $this->assertSame('Metalúrgica Silva', $fresh?->company);
        $this->assertSame('maria@silva.com.br', $fresh?->email);
        $this->assertSame('1990-02-28', $fresh?->birthday?->toDateString());
        $this->assertSame(['https://silva.com.br'], $fresh?->urls);
        $this->assertNotNull($fresh?->edited_at);
    }

    public function test_mostra_os_dados_atuais_na_abertura(): void
    {
        $this->contact->update(['company' => 'Acme']);

        $this->card()->assertSet('form.name', 'Push Name')->assertSet('form.company', 'Acme');
    }

    public function test_mostra_erro_de_validacao_e_nao_grava(): void
    {
        $this->card()->set('form.email', 'nao-e-email')->call('save')->assertHasErrors(['form.email']);

        $this->assertNull($this->contact->fresh()?->edited_at);
    }

    public function test_telefone_aparece_formatado_e_nao_e_editavel(): void
    {
        $this->card()->assertSee('(16) 99752-4950')->assertDontSee('5516997524950@c.us')->assertDontSeeHtml('wire:model="form.waId"');
    }

    public function test_contactid_nao_pode_ser_trocado_pelo_navegador(): void
    {
        $other = Contact::factory()->create();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->card()->set('contactId', $other->id);
    }

    public function test_visitante_sem_login_nao_acessa_o_inbox(): void
    {
        $this->get('/inbox')->assertRedirect('/login');
    }

    public function test_adiciona_e_remove_endereco_de_site_ate_o_limite(): void
    {
        $card = $this->card();

        for ($i = 0; $i < 7; $i++) {
            $card->call('addUrl');
        }

        $card->assertCount('form.urls', 5)->call('removeUrl', 0)->assertCount('form.urls', 4);
    }

    public function test_o_inbox_mostra_a_ficha_do_contato_do_ticket(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $ticket = Ticket::factory()->for($this->contact)->for(Channel::factory()->connected()->create())->create();

        Livewire::actingAs($admin)->test(Inbox::class)->set('ticketId', $ticket->id)->assertSeeLivewire('contacts.contact-card');
    }
}
