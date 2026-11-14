<?php

namespace Tests\Feature\Contacts;

use App\Enums\UserRole;
use App\Livewire\Contacts\Index;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** A ficha de Contatos abre numa gaveta lateral ao clicar, em vez de uma coluna que fica vazia. */
class ContactsDrawerTest extends TestCase
{
    use RefreshDatabase;

    private function page(): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->test(Index::class);
    }

    public function test_sem_selecao_nao_ha_coluna_vazia_nem_ficha(): void
    {
        Contact::factory()->create(['name' => 'Ana Paula']);

        $this->page()->assertSet('showCard', false)
            ->assertDontSee('Selecione um contato para ver e editar a ficha.')
            ->assertDontSeeLivewire('contacts.contact-card')
            ->assertSeeHtml('data-testid="contact-drawer"');
    }

    public function test_clicar_no_contato_abre_a_gaveta_com_a_ficha(): void
    {
        $contact = Contact::factory()->create(['name' => 'Ana Paula']);

        $this->page()->call('select', $contact->id)
            ->assertSet('showCard', true)->assertSet('selectedId', $contact->id)->assertSeeLivewire('contacts.contact-card');
    }

    public function test_fechar_a_gaveta_limpa_a_selecao_e_tira_a_ficha(): void
    {
        $contact = Contact::factory()->create();

        $this->page()->call('select', $contact->id)->set('showCard', false)
            ->assertSet('selectedId', null)->assertDontSeeLivewire('contacts.contact-card');
    }

    public function test_novo_contato_abre_a_ficha_na_gaveta_inclusive_o_que_ja_existia(): void
    {
        $existing = Contact::factory()->create(['wa_id' => '5516997524950@c.us']);

        $this->page()->set('newPhone', '(16) 99111-2222')->set('newName', 'Maria')->call('createContact')
            ->assertSet('showCard', true)->assertSeeLivewire('contacts.contact-card');

        $this->page()->set('newPhone', '+55 16 99752-4950')->set('newName', 'Outra')->call('createContact')
            ->assertSet('showCard', true)->assertSet('selectedId', $existing->id)->assertSee('Este número já estava cadastrado');
    }

    public function test_buscar_e_trocar_de_pagina_nao_reabrem_a_gaveta(): void
    {
        $contact = Contact::factory()->create();

        $this->page()->call('select', $contact->id)->set('showCard', false)
            ->set('search', 'ana')->assertSet('showCard', false)
            ->call('gotoPage', 2)->assertSet('showCard', false)->assertDontSeeLivewire('contacts.contact-card');
    }

    /** "Conversar" é um botão irmão do que abre a ficha, nunca filho dele: um clique não faz as duas coisas. */
    public function test_conversar_da_linha_nao_fica_dentro_do_botao_que_abre_a_ficha(): void
    {
        Contact::factory()->create(['wa_id' => '5516999999999@c.us']);

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$this->page()->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $opens = 0;

        foreach ($dom->getElementsByTagName('button') as $button) {
            if (str_starts_with($button->getAttribute('wire:click'), 'select(')) {
                $opens++;
                $this->assertStringNotContainsString('start-conversation', $dom->saveHTML($button));
            }
        }

        $this->assertSame(1, $opens);
    }

    public function test_contato_inexistente_responde_404_e_nao_abre_a_gaveta(): void
    {
        $this->page()->call('select', 999999)->assertNotFound();
    }
}
