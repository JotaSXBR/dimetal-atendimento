<?php

namespace Tests\Feature\Livewire;

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

    public function test_cria_edita_e_apaga_removendo_o_vinculo(): void
    {
        $page = Livewire::actingAs($this->supervisor())->test(Tags::class);

        $page->set('name', 'VIP')->set('color', '#2F855A')->call('save')->assertHasNoErrors();
        $tag = Tag::query()->firstOrFail();
        $ticket = Ticket::factory()->create();
        $ticket->tags()->attach($tag);

        $page->call('edit', $tag->id)->set('name', 'Cliente VIP')->call('save');
        $this->assertSame('Cliente VIP', $tag->fresh()?->name);

        $page->call('delete', $tag->id);
        $this->assertSame(0, Tag::query()->count());
        $this->assertSame(0, $ticket->tags()->count());
    }

    public function test_nome_unico_e_cor_valida(): void
    {
        Tag::factory()->create(['name' => 'VIP']);

        Livewire::actingAs($this->supervisor())->test(Tags::class)
            ->set('name', 'VIP')->set('color', '#2F855A')->call('save')->assertHasErrors(['name' => 'unique'])
            ->set('name', 'Outra')->set('color', 'vermelho')->call('save')->assertHasErrors(['color' => 'regex']);
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
