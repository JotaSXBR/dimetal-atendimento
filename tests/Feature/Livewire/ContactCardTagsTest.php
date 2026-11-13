<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Contacts\ContactCard;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ContactCardTagsTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

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
}
