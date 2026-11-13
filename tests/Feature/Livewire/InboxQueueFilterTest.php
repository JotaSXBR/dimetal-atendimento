<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboxQueueFilterTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(string $name): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => Channel::query()->firstOrCreate(['waha_session' => 'main'], ['name' => 'WhatsApp'])->id,
            'contact_id' => Contact::factory()->create(['name' => $name])->id,
            'status' => TicketStatus::AGUARDANDO,
        ]);
    }

    private function page(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class);
    }

    public function test_filtro_por_etiqueta_do_contato_casa_pelo_contato_do_ticket(): void
    {
        $tag = Tag::factory()->contato()->create();
        $this->ticket('Com Etiqueta')->contact->tags()->attach($tag);
        $this->ticket('Sem Etiqueta');

        $this->page()->set('tagId', $tag->id)->assertSee('Com Etiqueta')->assertDontSee('Sem Etiqueta');
    }

    public function test_filtro_por_etiqueta_da_conversa_casa_pelo_ticket(): void
    {
        $tag = Tag::factory()->create();
        $this->ticket('Com Etiqueta')->tags()->attach($tag);
        $this->ticket('Sem Etiqueta');

        $this->page()->set('tagId', $tag->id)->assertSee('Com Etiqueta')->assertDontSee('Sem Etiqueta');
    }

    public function test_o_seletor_agrupa_as_etiquetas_por_escopo(): void
    {
        Tag::factory()->contato()->create(['name' => 'Revenda']);
        Tag::factory()->create(['name' => 'Orçamento']);

        $this->page()->assertSeeInOrder(['Do contato', 'Revenda', 'Da conversa', 'Orçamento']);
    }
}
