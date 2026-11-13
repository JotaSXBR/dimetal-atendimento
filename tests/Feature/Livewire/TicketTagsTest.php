<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Events\TicketUpdated;
use App\Livewire\History\Index;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Inbox\TicketTags;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Support\TicketTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class TicketTagsTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
        ]);
    }

    /** @param  array<string, mixed>  $props */
    private function block(array $props = [], ?User $as = null): Testable
    {
        return Livewire::actingAs($as ?? $this->user)->test(TicketTags::class, ['ticketId' => $this->ticket->id, ...$props]);
    }

    public function test_aplica_a_etiqueta_da_conversa_com_evento_e_aviso_a_fila(): void
    {
        Event::fake([TicketUpdated::class]);
        $tag = Tag::factory()->create(['name' => 'Orçamento']);

        $this->block()->call('addTag', $tag->id)->assertSee('Orçamento');

        $this->assertSame([$tag->id], $this->ticket->tags()->pluck('tags.id')->all());
        $event = TicketEvent::query()->where('ticket_id', $this->ticket->id)->where('type', 'tag_added')->firstOrFail();
        $this->assertSame($this->user->id, $event->actor_user_id);
        $this->assertSame(['tag_id' => $tag->id, 'tag' => 'Orçamento'], $event->payload);
        Event::assertDispatched(TicketUpdated::class);
    }

    public function test_o_seletor_aplica_e_volta_a_vazio(): void
    {
        $tag = Tag::factory()->create();

        $this->block()->set('addTagId', (string) $tag->id)->assertSet('addTagId', '');

        $this->assertSame(1, $this->ticket->tags()->count());
    }

    public function test_remove_inclusive_a_que_o_bot_colocou(): void
    {
        $tag = Tag::factory()->create(['name' => 'Boleto']);
        $this->ticket->tags()->attach($tag);

        $this->block()->call('removeTag', $tag->id);

        $this->assertSame(0, $this->ticket->tags()->count());
        $event = TicketEvent::query()->where('type', 'tag_removed')->firstOrFail();
        $this->assertSame(['tag_id' => $tag->id, 'tag' => 'Boleto'], $event->payload);
    }

    public function test_etiqueta_do_contato_nao_entra_na_conversa(): void
    {
        $tag = Tag::factory()->contato()->create();

        $this->block()->call('addTag', $tag->id);

        $this->assertSame(0, $this->ticket->tags()->count());
        $this->assertSame(0, TicketEvent::query()->where('type', 'tag_added')->count());
    }

    public function test_repetir_ou_remover_o_que_nao_esta_nao_grava_evento(): void
    {
        $tag = Tag::factory()->create();

        $this->block()->call('addTag', $tag->id)->call('addTag', $tag->id)->call('removeTag', Tag::factory()->create()->id);

        $this->assertSame(1, TicketEvent::query()->where('type', 'tag_added')->count());
        $this->assertSame(0, TicketEvent::query()->where('type', 'tag_removed')->count());
    }

    public function test_quem_nao_ve_o_ticket_nao_le_nem_altera(): void
    {
        $other = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $other->sectors()->attach(Sector::factory()->create());

        $this->block(as: $other)->assertNotFound();
        $this->assertSame(0, $this->ticket->tags()->count());
    }

    public function test_ticket_encerrado_e_somente_leitura_recebe_403(): void
    {
        $tag = Tag::factory()->create();
        $this->ticket->tags()->attach($tag);

        $this->block(['readonly' => true])->call('addTag', Tag::factory()->create()->id)->assertForbidden();
        $this->block(['readonly' => true])->call('removeTag', $tag->id)->assertForbidden();

        $this->ticket->forceFill(['status' => TicketStatus::ENCERRADO, 'closed_at' => now()])->save();
        $this->block()->call('removeTag', $tag->id)->assertForbidden();
        $this->assertSame(1, $this->ticket->tags()->count());
    }

    public function test_somente_leitura_mostra_os_chips_sem_remover_nem_seletor(): void
    {
        $this->ticket->tags()->attach(Tag::factory()->create(['name' => 'Entrega']));
        Tag::factory()->create(['name' => 'Sobrou']);

        $this->block(['readonly' => true])->assertSee('Entrega')->assertDontSee('Remover etiqueta')->assertDontSee('Adicionar etiqueta');
    }

    public function test_seletor_so_oferece_etiquetas_da_conversa_que_faltam(): void
    {
        $this->ticket->tags()->attach(Tag::factory()->create(['name' => 'Aplicada']));
        Tag::factory()->create(['name' => 'Livre']);
        Tag::factory()->contato()->create(['name' => 'DoContato']);

        $html = $this->block()->html();

        $this->assertStringContainsString('Livre</option>', $html);
        $this->assertStringNotContainsString('Aplicada</option>', $html);
        $this->assertStringNotContainsString('DoContato', $html);
    }

    /** Duas pessoas (ou dois cliques) no mesmo instante: a pivô tem chave única, então a segunda não pode estourar nem duplicar a auditoria. */
    public function test_aplicar_o_que_outra_pessoa_acabou_de_aplicar_nao_estoura_nem_duplica_evento(): void
    {
        $tag = Tag::factory()->create();
        $screen = $this->block();
        $this->ticket->tags()->attach($tag);

        $screen->call('addTag', $tag->id)->assertHasNoErrors();

        $this->assertSame(1, $this->ticket->tags()->count());
        $this->assertSame(0, TicketEvent::query()->where('type', 'tag_added')->count());
    }

    public function test_remover_o_que_outra_pessoa_acabou_de_remover_nao_grava_segundo_evento(): void
    {
        $tag = Tag::factory()->create();
        $this->ticket->tags()->attach($tag);
        $screen = $this->block();
        $this->block()->call('removeTag', $tag->id);

        $screen->call('removeTag', $tag->id);

        $this->assertSame(1, TicketEvent::query()->where('type', 'tag_removed')->count());
    }

    public function test_a_tela_se_atualiza_quando_outra_pessoa_muda_as_etiquetas(): void
    {
        $screen = $this->block();
        $this->ticket->tags()->attach(Tag::factory()->create(['name' => 'DeOutraPessoa']));

        $screen->assertDontSee('DeOutraPessoa')->call('refresh')->assertSee('DeOutraPessoa');
    }

    public function test_remover_tambem_avisa_a_fila(): void
    {
        Event::fake([TicketUpdated::class]);
        $tag = Tag::factory()->create();
        $this->ticket->tags()->attach($tag);

        $this->block()->call('removeTag', $tag->id);

        Event::assertDispatched(TicketUpdated::class);
    }

    /** Decisão do dono: quem vê o ticket aberto etiqueta, não só o responsável. */
    public function test_atendente_que_ve_o_ticket_de_outro_responsavel_tambem_etiqueta(): void
    {
        $sector = Sector::factory()->create();
        $mate = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $mate->sectors()->attach($sector);
        $this->ticket->forceFill(['sector_id' => $sector->id, 'assigned_user_id' => $this->user->id])->save();
        $tag = Tag::factory()->create();

        $this->block(as: $mate)->call('addTag', $tag->id);

        $this->assertSame(1, $this->ticket->tags()->count());
    }

    public function test_o_painel_do_inbox_e_o_do_historico_montam_o_bloco_com_o_modo_certo(): void
    {
        $tag = Tag::factory()->create(['name' => 'Aplicada']);
        $this->ticket->tags()->attach($tag);
        Tag::factory()->create(['name' => 'Livre']);

        $open = Livewire::actingAs($this->user)->test(Inbox::class)->call('select', $this->ticket->id)->html();
        $this->assertStringContainsString('Adicionar etiqueta à conversa', $open);

        $this->ticket->forceFill(['status' => TicketStatus::ENCERRADO, 'closed_at' => now()])->save();
        $history = Livewire::actingAs($this->user)->test(Index::class)->call('select', $this->ticket->id)->html();
        $this->assertStringContainsString('Aplicada', $history);
        $this->assertStringNotContainsString('Adicionar etiqueta à conversa', $history);
    }

    public function test_a_linha_do_tempo_diz_quem_e_qual_etiqueta(): void
    {
        $tag = Tag::factory()->create(['name' => 'Urgente']);
        $this->block()->call('addTag', $tag->id)->call('removeTag', $tag->id);

        $events = TicketEvent::query()->where('ticket_id', $this->ticket->id)->orderBy('id')->get();
        $timeline = new TicketTimeline($events);
        $sentences = $events->map(fn (TicketEvent $e): string => $timeline->sentence($e))->values()->all();

        $this->assertStringContainsString('adicionou a etiqueta Urgente', $sentences[0]);
        $this->assertStringContainsString('removeu a etiqueta Urgente', $sentences[1]);
    }
}
