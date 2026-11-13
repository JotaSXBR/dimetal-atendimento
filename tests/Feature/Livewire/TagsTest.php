<?php

namespace Tests\Feature\Livewire;

use App\Enums\TagColor;
use App\Enums\TagScope;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Settings\Tags;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TagsTest extends TestCase
{
    use RefreshDatabase;

    private function supervisor(): User
    {
        return User::factory()->create(['role' => UserRole::SUPERVISOR]);
    }

    public function test_so_supervisor_e_admin_configuram(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/configuracoes/etiquetas')->assertForbidden();
        $this->actingAs($this->supervisor())->get('/configuracoes/etiquetas')->assertOk();
    }

    public function test_atendente_nao_abre_o_componente(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->test(Tags::class)->assertForbidden();
    }

    public function test_cria_edita_e_apaga_removendo_os_dois_vinculos(): void
    {
        $page = Livewire::actingAs($this->supervisor())->test(Tags::class);

        $page->call('newTag', 'conversa')->set('name', 'VIP')->set('color', 'azul')->call('save')->assertHasNoErrors();
        $page->call('newTag', 'contato')->set('name', 'Revenda')->call('save')->assertHasNoErrors();
        $ticketTag = Tag::query()->where('name', 'VIP')->firstOrFail();
        $contactTag = Tag::query()->where('name', 'Revenda')->firstOrFail();
        $ticket = Ticket::factory()->create();
        $ticket->tags()->attach($ticketTag);
        $contact = Contact::factory()->create();
        $contact->tags()->attach($contactTag);

        $page->call('edit', $ticketTag->id)->set('name', 'Cliente VIP')->call('save');
        $this->assertSame('Cliente VIP', $ticketTag->fresh()?->name);

        $page->call('delete', $ticketTag->id)->call('delete', $contactTag->id);
        $this->assertSame(0, Tag::query()->count());
        $this->assertSame(0, $ticket->tags()->count());
        $this->assertSame(0, $contact->tags()->count());
    }

    public function test_etiqueta_nova_guarda_escopo_e_tom_da_paleta(): void
    {
        Livewire::actingAs($this->supervisor())->test(Tags::class)
            ->call('newTag', 'contato')->set('name', 'Inadimplente')->set('color', 'rosa')->call('save')->assertHasNoErrors();

        $tag = Tag::query()->firstOrFail();
        $this->assertSame(TagScope::CONTATO, $tag->scope);
        $this->assertSame(TagColor::ROSA, $tag->color);
    }

    public function test_nome_e_unico_entre_os_dois_escopos_e_a_cor_e_da_paleta(): void
    {
        Tag::factory()->contato()->create(['name' => 'VIP']);

        Livewire::actingAs($this->supervisor())->test(Tags::class)
            ->call('newTag', 'conversa')->set('name', 'VIP')->call('save')->assertHasErrors(['name' => 'unique'])
            ->set('name', 'Outra')->set('color', '#2F855A')->call('save')->assertHasErrors(['color'])
            ->set('color', 'azul')->set('scope', 'qualquer')->call('save')->assertHasErrors(['scope']);
    }

    public function test_escopo_so_muda_enquanto_a_etiqueta_nao_esta_em_uso(): void
    {
        $free = Tag::factory()->create(['name' => 'Livre']);
        $used = Tag::factory()->create(['name' => 'Usada']);
        Ticket::factory()->create()->tags()->attach($used);
        $page = Livewire::actingAs($this->supervisor())->test(Tags::class);

        $page->call('edit', $free->id)->set('scope', 'contato')->call('save')->assertHasNoErrors();
        $this->assertSame(TagScope::CONTATO, $free->fresh()?->scope);

        $page->call('edit', $used->id)->set('scope', 'contato')->call('save')->assertHasErrors(['scope']);
        $this->assertSame(TagScope::CONVERSA, $used->fresh()?->scope);
    }

    public function test_a_tela_mostra_duas_listas_com_o_uso_de_cada_etiqueta(): void
    {
        Tag::factory()->contato()->create(['name' => 'Revenda']);
        Tag::factory()->create(['name' => 'Orçamento']);

        Livewire::actingAs($this->supervisor())->test(Tags::class)
            ->assertSeeInOrder(['Do contato', 'Revenda', 'Da conversa', 'Orçamento']);
    }

    public function test_chip_aparece_na_lista_do_inbox_e_o_filtro_esconde_os_outros(): void
    {
        $channel = Channel::factory()->create();
        $tag = Tag::factory()->create(['name' => 'Orçamento']);
        $tagged = Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::AGUARDANDO, 'contact_id' => Contact::factory()->create(['name' => 'Com Etiqueta'])->id]);
        $tagged->tags()->attach($tag);
        Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::AGUARDANDO, 'contact_id' => Contact::factory()->create(['name' => 'Sem Etiqueta'])->id]);

        $page = Livewire::actingAs($this->supervisor())->test(Inbox::class)
            ->assertSee('Com Etiqueta')->assertSee('Sem Etiqueta')->assertSee('Orçamento');

        $page->set('tagId', $tag->id)->assertSee('Com Etiqueta')->assertDontSee('Sem Etiqueta');
    }
}
