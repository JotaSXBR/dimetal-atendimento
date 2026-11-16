<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Events\TicketUpdated;
use App\Livewire\Contacts\ContactCard;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ContactCardTagsTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

    private ?Channel $channel = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contact = Contact::factory()->create(['wa_id' => '5516997524950@c.us']);
    }

    /** @param  array<string, mixed>  $props */
    private function card(array $props = []): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))
            ->test(ContactCard::class, ['contactId' => $this->contact->id, ...$props]);
    }

    public function test_aplica_e_remove_etiqueta_do_contato(): void
    {
        $tag = Tag::factory()->contato()->create(['name' => 'Revenda']);

        $this->card()->call('addTag', $tag->id)->assertSee('Revenda');
        $this->assertSame([$tag->id], $this->contact->tags()->pluck('tags.id')->all());

        $this->card()->call('removeTag', $tag->id);
        $this->assertSame(0, $this->contact->tags()->count());
    }

    public function test_etiqueta_da_conversa_nao_entra_no_contato(): void
    {
        $tag = Tag::factory()->create();

        $this->card()->call('addTag', $tag->id);

        $this->assertSame(0, $this->contact->tags()->count());
    }

    public function test_ficha_somente_leitura_recebe_403_ao_aplicar_ou_remover(): void
    {
        $tag = Tag::factory()->contato()->create();
        $this->contact->tags()->attach($tag);

        $this->card(['readonly' => true])->call('addTag', $tag->id)->assertForbidden();
        $this->card(['readonly' => true])->call('removeTag', $tag->id)->assertForbidden();
        $this->assertSame(1, $this->contact->tags()->count());
    }

    public function test_aplicar_etiqueta_nao_toca_o_contato_nem_chama_o_google(): void
    {
        Queue::fake();
        $tag = Tag::factory()->contato()->create();
        $before = $this->contact->fresh();

        $this->travel(5)->minutes();
        $this->card()->call('addTag', $tag->id)->call('removeTag', $tag->id)->call('addTag', $tag->id);

        $after = $this->contact->fresh();
        $this->assertEquals($before?->updated_at, $after?->updated_at);
        $this->assertNull($after?->edited_at);
        Queue::assertNothingPushed();
    }

    public function test_o_limite_por_contato_recusa_a_etiqueta_extra(): void
    {
        $this->contact->tags()->attach(Tag::factory()->contato()->count(ContactCard::MAX_TAGS)->create()->modelKeys());
        $extra = Tag::factory()->contato()->create();

        $this->card()->call('addTag', $extra->id)->assertHasErrors(['tag']);
        $this->assertSame(ContactCard::MAX_TAGS, $this->contact->tags()->count());
    }

    public function test_as_duas_fichas_mostram_as_etiquetas_do_contato(): void
    {
        $this->contact->tags()->attach(Tag::factory()->contato()->create(['name' => 'Inadimplente']));

        $this->card(['compact' => true])->assertSee('Inadimplente');
        $this->card(['compact' => false])->assertSee('Inadimplente');
    }

    public function test_sem_etiqueta_do_contato_no_catalogo_o_seletor_nao_aparece(): void
    {
        Tag::factory()->create(['name' => 'Só da conversa']);

        $this->card()->assertDontSee('Adicionar etiqueta');
    }

    private function ticketOf(Contact $contact, TicketStatus $status): Ticket
    {
        $this->channel ??= Channel::factory()->create();

        return Ticket::factory()->create(['channel_id' => $this->channel->id, 'contact_id' => $contact->id, 'status' => $status]);
    }

    public function test_mudar_a_etiqueta_avisa_a_fila_na_hora_e_os_outros_atendentes_so_dos_tickets_abertos(): void
    {
        Event::fake([TicketUpdated::class]);
        $open = $this->ticketOf($this->contact, TicketStatus::AGUARDANDO);
        $closed = $this->ticketOf($this->contact, TicketStatus::ENCERRADO);
        $tag = Tag::factory()->contato()->create();

        $this->card()->call('addTag', $tag->id)->assertDispatched('tags-changed');
        $this->card()->call('removeTag', $tag->id)->assertDispatched('tags-changed');

        Event::assertDispatched(TicketUpdated::class, fn (TicketUpdated $event): bool => $event->ticketId === $open->id);
        Event::assertNotDispatched(TicketUpdated::class, fn (TicketUpdated $event): bool => $event->ticketId === $closed->id);
    }

    public function test_etiqueta_que_ja_esta_no_contato_nao_avisa_ninguem(): void
    {
        Event::fake([TicketUpdated::class]);
        $this->ticketOf($this->contact, TicketStatus::AGUARDANDO);
        $tag = Tag::factory()->contato()->create();
        $this->contact->tags()->attach($tag);

        $this->card()->call('addTag', $tag->id)->assertNotDispatched('tags-changed');

        Event::assertNotDispatched(TicketUpdated::class);
    }

    public function test_a_fila_se_atualiza_quando_o_painel_avisa_que_as_etiquetas_mudaram(): void
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->ticketOf($this->contact, TicketStatus::AGUARDANDO);
        $tag = Tag::factory()->contato()->create(['name' => 'Revenda']);

        $inbox = Livewire::actingAs($user)->test(Inbox::class);
        // O nome também aparece no filtro de etiquetas da fila: o que conta é o chip a mais no card.
        $before = substr_count($inbox->html(), 'Revenda');
        $this->contact->tags()->attach($tag);

        $inbox->dispatch('tags-changed');

        $this->assertGreaterThan($before, substr_count($inbox->html(), 'Revenda'));
    }

    public function test_remover_etiqueta_pede_o_foco_de_volta_ao_seletor(): void
    {
        $tag = Tag::factory()->contato()->create();

        $this->card()->call('addTag', $tag->id)->call('removeTag', $tag->id)->assertDispatched('tag-removed');
    }

    public function test_remover_etiqueta_que_nao_estava_nao_pede_foco(): void
    {
        $this->card()->call('removeTag', Tag::factory()->contato()->create()->id)->assertNotDispatched('tag-removed');
    }

    public function test_remover_oferece_desfazer_so_quando_removeu(): void
    {
        $tag = Tag::factory()->contato()->create(['name' => 'Revenda']);
        $this->contact->tags()->attach($tag);

        $this->card()->call('removeTag', Tag::factory()->contato()->create()->id)->assertNotDispatched('toast-show');
        $this->card()->call('removeTag', $tag->id)
            ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains(json_encode($params, JSON_UNESCAPED_UNICODE), 'Etiqueta «Revenda» removida.')
                && str_contains(json_encode($params), 'undo-contact-tag'));
    }

    public function test_desfazer_recoloca_a_etiqueta_do_contato(): void
    {
        $tag = Tag::factory()->contato()->create();
        $this->contact->tags()->attach($tag);

        $this->card()->call('removeTag', $tag->id)->call('undoRemove', $this->contact->id, $tag->id);

        $this->assertSame([$tag->id], $this->contact->tags()->pluck('tags.id')->all());
    }

    public function test_desfazer_de_outro_contato_nao_faz_nada(): void
    {
        $tag = Tag::factory()->contato()->create();

        $this->card()->call('undoRemove', $this->contact->id + 1000, $tag->id);

        $this->assertSame(0, $this->contact->tags()->count());
    }

    public function test_desfazer_no_limite_de_etiquetas_recusa_com_o_erro_de_sempre(): void
    {
        $this->contact->tags()->attach(Tag::factory()->contato()->count(ContactCard::MAX_TAGS)->create()->modelKeys());
        $removed = Tag::factory()->contato()->create();

        $this->card()->call('undoRemove', $this->contact->id, $removed->id)->assertHasErrors(['tag']);
        $this->assertSame(ContactCard::MAX_TAGS, $this->contact->tags()->count());
    }
}
