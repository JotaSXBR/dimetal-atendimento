<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Contacts\Index;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ContactsIndexTest extends TestCase
{
    use RefreshDatabase;

    private function page(): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->test(Index::class);
    }

    public function test_visitante_vai_para_o_login_e_atendente_entra(): void
    {
        $this->get('/contatos')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/contatos')->assertOk();
    }

    public function test_busca_por_nome_telefone_email_e_empresa(): void
    {
        Contact::factory()->create(['name' => 'Maria Souza', 'wa_id' => '5511911112222@c.us']);
        Contact::factory()->create(['name' => 'João Lima', 'wa_id' => '5516997524950@c.us']);
        Contact::factory()->create(['name' => 'Pedro', 'wa_id' => '5521933334444@c.us', 'email' => 'pedro@tornearia.com.br']);
        Contact::factory()->create(['name' => 'Ana', 'wa_id' => '5531955556666@c.us', 'company' => 'Aços Brasil']);

        $this->page()->set('search', 'souza')->assertSee('Maria Souza')->assertDontSee('João Lima');
        $this->page()->set('search', '16997')->assertSee('João Lima')->assertDontSee('Maria Souza');
        $this->page()->set('search', '(16) 99752')->assertSee('João Lima');
        $this->page()->set('search', 'tornearia')->assertSee('Pedro')->assertDontSee('João Lima');
        $this->page()->set('search', 'aços')->assertSee('Ana')->assertDontSee('Pedro');
    }

    public function test_curinga_digitado_nao_vira_curinga_da_busca(): void
    {
        Contact::factory()->create(['name' => 'Maria']);
        Contact::factory()->create(['name' => '100% Aço']);

        $this->page()->set('search', '%')->assertSee('100% Aço')->assertDontSee('Maria');
        $this->page()->set('search', '_')->assertDontSee('Maria');
    }

    public function test_lista_pagina_de_25_em_25_por_nome(): void
    {
        foreach (range(1, 30) as $n) {
            Contact::factory()->create(['name' => sprintf('Cliente %02d', $n)]);
        }

        $this->page()->assertSee('Cliente 25')->assertDontSee('Cliente 26');
    }

    public function test_novo_contato_cria_e_abre_a_ficha(): void
    {
        $page = $this->page()->set('newPhone', '(16) 99752-4950')->set('newName', 'Ana Paula')->call('createContact')->assertHasNoErrors();

        $contact = Contact::query()->where('wa_id', '5516997524950@c.us')->firstOrFail();
        $page->assertSet('selectedId', $contact->id)->assertSeeLivewire('contacts.contact-card');
        $this->assertSame('Ana Paula', $contact->name);
    }

    public function test_telefone_repetido_abre_o_existente_com_aviso_sem_duplicar(): void
    {
        $existing = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'name' => 'Dona Maria']);

        $this->page()->set('newPhone', '+55 16 99752-4950')->set('newName', 'Outra')->call('createContact')
            ->assertSet('selectedId', $existing->id)
            ->assertSee('já estava cadastrado');

        $this->assertSame(1, Contact::query()->count());
        $this->assertSame('Dona Maria', $existing->fresh()?->name);
    }

    public function test_telefone_invalido_mostra_erro(): void
    {
        $this->page()->set('newPhone', '12')->set('newName', 'Ana')->call('createContact')->assertHasErrors(['newPhone']);

        $this->assertSame(0, Contact::query()->count());
    }

    public function test_novo_contato_exige_nome(): void
    {
        $this->page()->set('newPhone', '16997524950')->set('newName', '')->call('createContact')->assertHasErrors(['newName']);
    }
}
