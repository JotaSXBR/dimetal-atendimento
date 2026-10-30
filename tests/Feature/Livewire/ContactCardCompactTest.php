<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Contacts\ContactCard;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ContactCardCompactTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contact = Contact::factory()->create(['name' => 'Joaquim Silva', 'company' => 'Metalúrgica Norte', 'notes' => 'Prefere falar de manhã']);
        $this->user = User::factory()->create(['role' => UserRole::ADMIN]);
    }

    /** @param  array<string, mixed>  $props */
    private function card(array $props = []): Testable
    {
        return Livewire::actingAs($this->user)->test(ContactCard::class, ['contactId' => $this->contact->id, ...$props]);
    }

    public function test_inbox_mostra_o_resumo_e_esconde_o_formulario(): void
    {
        $this->card(['compact' => true])
            ->assertSee('Joaquim Silva')
            ->assertSee('Metalúrgica Norte')
            ->assertSee('Prefere falar de manhã')
            ->assertSee('Editar contato')
            ->assertDontSee('Salvar contato');
    }

    public function test_editar_abre_o_formulario_e_cancelar_volta_ao_resumo(): void
    {
        $this->card(['compact' => true])
            ->call('toggleEdit')
            ->assertSee('Salvar contato')
            ->assertSee('Cancelar')
            ->call('toggleEdit')
            ->assertDontSee('Salvar contato');
    }

    public function test_salvar_grava_e_volta_ao_resumo(): void
    {
        $this->card(['compact' => true])
            ->call('toggleEdit')
            ->set('form.name', 'Joaquim S. Filho')
            ->call('save')
            ->assertSet('editing', false)
            ->assertSee('Joaquim S. Filho')
            ->assertDontSee('Salvar contato');

        $this->assertSame('Joaquim S. Filho', $this->contact->fresh()?->name);
    }

    public function test_aviso_do_google_so_aparece_com_erro(): void
    {
        $this->card(['compact' => true])->assertDontSee('Reenviar');

        $this->contact->forceFill(['google_error' => 'Quota excedida'])->save();

        $this->card(['compact' => true])->assertSee('Reenviar');
    }

    public function test_em_contatos_o_formulario_continua_aberto(): void
    {
        $this->card()->assertSee('Salvar contato')->assertDontSee('Editar contato');
    }

    public function test_somente_leitura_nao_edita_nem_salva(): void
    {
        $this->card(['compact' => true, 'readonly' => true])
            ->assertSee('Joaquim Silva')
            ->assertDontSee('Editar contato')
            ->call('toggleEdit')->assertForbidden();

        $this->card(['compact' => true, 'readonly' => true])->call('save')->assertForbidden();
        $this->card(['compact' => true, 'readonly' => true])->call('resync')->assertForbidden();
    }

    public function test_lista_de_anteriores_nao_repete_o_ticket_atual_e_so_linka_o_que_o_usuario_ve(): void
    {
        $channel = Channel::factory()->create();
        $mine = Sector::factory()->create();
        $other = Sector::factory()->create();
        $make = fn (Sector $sector, TicketStatus $status): Ticket => Ticket::factory()->create([
            'channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'sector_id' => $sector->id,
            'status' => $status, 'closed_at' => $status === TicketStatus::ENCERRADO ? now()->subDay() : null,
        ]);
        $visible = $make($mine, TicketStatus::ENCERRADO);
        $hidden = $make($other, TicketStatus::ENCERRADO);
        $current = $make($mine, TicketStatus::EM_ATENDIMENTO);
        $agent = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $agent->sectors()->attach($mine);

        $html = Livewire::actingAs($agent)->test(ContactCard::class, ['contactId' => $this->contact->id, 'compact' => true, 'currentTicketId' => $current->id])->html();

        $this->assertStringNotContainsString((string) $current->protocol, $html);
        $this->assertStringContainsString('ticketId='.$visible->id, $html);
        $this->assertStringContainsString((string) $hidden->protocol, $html);
        $this->assertStringNotContainsString('ticketId='.$hidden->id, $html);
    }
}
