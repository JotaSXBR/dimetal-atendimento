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

    public function test_ticket_aberto_na_ficha_do_historico_leva_para_a_fila(): void
    {
        $channel = Channel::factory()->create();
        $open = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::EM_ATENDIMENTO]);
        $current = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::ENCERRADO, 'closed_at' => now()]);

        $html = $this->card(['compact' => true, 'readonly' => true, 'currentTicketId' => $current->id])->html();

        $this->assertStringContainsString(route('inbox', ['ticket' => $open->id]), $html);
        $this->assertStringNotContainsString('$parent.select', $html);
    }

    public function test_na_fila_o_ticket_aberto_continua_abrindo_sem_recarregar(): void
    {
        $channel = $this->channelWith('a', '5516999990001');
        Ticket::factory()->create(['channel_id' => $this->channelWith('b', '5516988887777')->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::EM_ATENDIMENTO]);
        $current = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::AGUARDANDO]);

        $this->assertStringContainsString('$parent.select', $this->card(['compact' => true, 'currentTicketId' => $current->id])->html());
    }

    public function test_em_contatos_o_protocolo_leva_ao_ticket(): void
    {
        $channel = Channel::factory()->create();
        $closed = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::ENCERRADO, 'closed_at' => now()->subDay(), 'protocol' => '2026-000951']);
        $open = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'protocol' => '2026-000952']);

        $html = $this->card()->html();

        $this->assertMatchesRegularExpression('#<a href="[^"]*/historico\?ticketId='.$closed->id.'"[^>]*>\s*2026-000951\s*</a>#', $html);
        $this->assertMatchesRegularExpression('#<a href="[^"]*/inbox\?ticket='.$open->id.'"[^>]*>\s*2026-000952\s*</a>#', $html);
    }

    public function test_em_contatos_ticket_que_o_usuario_nao_ve_fica_sem_link(): void
    {
        $mine = Sector::factory()->create();
        $hidden = Ticket::factory()->create([
            'channel_id' => Channel::factory()->create()->id, 'contact_id' => $this->contact->id, 'sector_id' => Sector::factory()->create()->id,
            'status' => TicketStatus::ENCERRADO, 'closed_at' => now()->subDay(), 'protocol' => '2026-000953',
        ]);
        $agent = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $agent->sectors()->attach($mine);

        $html = Livewire::actingAs($agent)->test(ContactCard::class, ['contactId' => $this->contact->id])->html();

        $this->assertStringContainsString('2026-000953', $html);
        $this->assertStringNotContainsString('ticketId='.$hidden->id, $html);
    }

    private function channelWith(string $session, string $phone): Channel
    {
        return Channel::factory()->connected()->create(['waha_session' => $session, 'phone' => $phone]);
    }

    private function ticketOn(Channel $channel, TicketStatus $status, ?Sector $sector = null, ?User $assignee = null): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => $channel->id, 'contact_id' => $this->contact->id, 'sector_id' => $sector?->id,
            'assigned_user_id' => $assignee?->id, 'status' => $status,
            'closed_at' => $status === TicketStatus::ENCERRADO ? now()->subDay() : null,
        ]);
    }

    public function test_ticket_de_outro_numero_mostra_o_numero_e_o_do_mesmo_numero_nao_repete(): void
    {
        $a = $this->channelWith('a', '5516999990001');
        $b = $this->channelWith('b', '5516988887777');
        $current = $this->ticketOn($a, TicketStatus::EM_ATENDIMENTO);
        $this->ticketOn($b, TicketStatus::ENCERRADO);
        $this->ticketOn($a, TicketStatus::ENCERRADO);

        $html = $this->card(['compact' => true, 'currentTicketId' => $current->id])->assertSee('número (16) 98888-7777')->html();

        $this->assertStringNotContainsString('(16) 99999-0001', $html);
    }

    public function test_ticket_aberto_em_outro_numero_ganha_destaque_e_link_para_o_inbox(): void
    {
        $current = $this->ticketOn($this->channelWith('a', '5516999990001'), TicketStatus::EM_ATENDIMENTO);
        $open = $this->ticketOn($this->channelWith('b', '5516988887777'), TicketStatus::AGUARDANDO);

        $this->card(['compact' => true, 'currentTicketId' => $current->id])
            ->assertSee('também tem ticket aberto no número (16) 98888-7777')
            ->assertSeeHtml('$parent.select('.$open->id.')');
    }

    public function test_ticket_aberto_no_mesmo_numero_tambem_linka_sem_destaque(): void
    {
        $channel = $this->channelWith('a', '5516999990001');
        $current = $this->ticketOn($channel, TicketStatus::ENCERRADO);
        $open = $this->ticketOn($channel, TicketStatus::AGUARDANDO);

        $this->card(['compact' => true, 'currentTicketId' => $current->id])
            ->assertSeeHtml('$parent.select('.$open->id.')')
            ->assertDontSee('também tem ticket aberto');
    }

    public function test_somente_leitura_mostra_o_aberto_sem_link(): void
    {
        $current = $this->ticketOn($this->channelWith('a', '5516999990001'), TicketStatus::ENCERRADO);
        $open = $this->ticketOn($this->channelWith('b', '5516988887777'), TicketStatus::AGUARDANDO);

        $this->card(['compact' => true, 'readonly' => true, 'currentTicketId' => $current->id])
            ->assertSee((string) $open->protocol)
            ->assertDontSeeHtml('$parent.select(');
    }

    public function test_sem_acesso_nao_mostra_setor_nem_link(): void
    {
        $current = $this->ticketOn($this->channelWith('a', '5516999990001'), TicketStatus::AGUARDANDO);
        $hidden = $this->ticketOn($this->channelWith('b', '5516988887777'), TicketStatus::EM_ATENDIMENTO, Sector::factory()->create(['name' => 'Setor Reservado']), User::factory()->create());

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))
            ->test(ContactCard::class, ['contactId' => $this->contact->id, 'compact' => true, 'currentTicketId' => $current->id])
            ->assertSee((string) $hidden->protocol)
            ->assertDontSee('Setor Reservado')
            ->assertDontSeeHtml('$parent.select(');
    }

    public function test_refresh_traz_ticket_novo_e_nao_mexe_na_edicao(): void
    {
        $channel = $this->channelWith('a', '5516999990001');
        $current = $this->ticketOn($channel, TicketStatus::EM_ATENDIMENTO);
        $screen = $this->card(['compact' => true, 'currentTicketId' => $current->id]);

        $new = $this->ticketOn($this->channelWith('b', '5516988887777'), TicketStatus::AGUARDANDO);
        $screen->call('refresh')->assertSee((string) $new->protocol);

        $this->card(['compact' => true, 'currentTicketId' => $current->id])
            ->call('toggleEdit')->set('form.name', 'Digitado')->call('refresh')
            ->assertSet('editing', true)->assertSet('form.name', 'Digitado');
    }
}
