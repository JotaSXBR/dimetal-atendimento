<?php

namespace Tests\Feature\Bot;

use App\Enums\ClosedReason;
use App\Enums\MessageOrigin;
use App\Enums\NoteOrigin;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketMessageJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Bot\Effects\LiveEffects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class LiveEffectsTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private Contact $contact;

    private LiveEffects $fx;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Queue::fake();
        Sleep::fake();
        Http::fake();
        $this->contact = Contact::factory()->create(['name' => 'Maria', 'wa_id' => '5516997524950@c.us']);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create(['waha_session' => 'main'])->id,
            'contact_id' => $this->contact->id,
            'status' => TicketStatus::PENDENTE,
        ]);
        $this->fx = new LiveEffects($this->ticket);
    }

    public function test_say_grava_mensagem_do_bot_e_despacha_o_envio(): void
    {
        $this->fx->say('Oi');
        $this->fx->say('  ');

        $messages = Message::query()->where('ticket_id', $this->ticket->id)->get();
        $this->assertCount(1, $messages);
        $this->assertSame(MessageOrigin::BOT, $messages->first()->origin);
        $this->assertSame('Oi', $messages->first()->body);
        Queue::assertPushed(SendTicketMessageJob::class, 1);
    }

    public function test_tag_note_close_e_save_contact_field_fazem_o_que_os_blocos_faziam(): void
    {
        $tag = Tag::factory()->create();

        $this->fx->tag($tag);
        $this->fx->tag($tag);
        $this->fx->note('Coletado: CNPJ');
        $this->fx->saveContactField('name', 'Ana');
        $this->fx->close();

        $this->assertSame([$tag->id], $this->ticket->tags()->pluck('tags.id')->all());
        $note = TicketNote::query()->where('ticket_id', $this->ticket->id)->sole();
        $this->assertSame(NoteOrigin::BOT, $note->origin);
        $this->assertSame('Coletado: CNPJ', $note->body);
        $this->assertSame('Ana', $this->contact->refresh()->name);
        $this->ticket->refresh();
        $this->assertSame(TicketStatus::ENCERRADO, $this->ticket->status);
        $this->assertSame(ClosedReason::BOT, $this->ticket->closed_reason);
    }

    public function test_enter_queue_com_atribuicao_devolve_o_id_de_quem_assumiu(): void
    {
        $sector = Sector::factory()->create(['name' => 'Vendas']);
        $agent = User::factory()->create(['active' => true]);
        $agent->sectors()->attach($sector);

        $this->assertSame($agent->id, $this->fx->enterQueue($sector, $agent));
        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $this->ticket->refresh()->status);
        $this->assertSame($agent->id, $this->ticket->assigned_user_id);
    }

    public function test_enter_queue_com_pessoa_inativa_fica_na_fila(): void
    {
        $sector = Sector::factory()->create(['name' => 'Vendas']);
        $off = User::factory()->create(['active' => false]);

        $this->assertNull($this->fx->enterQueue($sector, $off));
        $this->assertSame(TicketStatus::AGUARDANDO, $this->ticket->refresh()->status);
        $this->assertNull($this->ticket->assigned_user_id);
    }

    public function test_wait_mostra_digitando_e_pausa(): void
    {
        $this->fx->wait(3);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/startTyping'));
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/stopTyping'));
        Sleep::assertSlept(fn ($duration): bool => $duration->totalSeconds === 3.0);
    }

    public function test_wait_nao_quebra_se_o_waha_falhar(): void
    {
        Http::fake(['*' => Http::response('erro', 500)]);

        $this->fx->wait(1);

        Sleep::assertSleptTimes(1);
    }

    public function test_tag_grava_no_contato_ou_no_ticket_conforme_o_escopo(): void
    {
        $contactTag = Tag::factory()->contato()->create();
        $ticketTag = Tag::factory()->create();
        $before = $this->contact->fresh();

        $this->fx->tag($contactTag);
        $this->fx->tag($ticketTag);
        $this->fx->tag($contactTag);

        $this->assertSame([$contactTag->id], $this->contact->tags()->pluck('tags.id')->all());
        $this->assertSame([$ticketTag->id], $this->ticket->tags()->pluck('tags.id')->all());
        $this->assertEquals($before?->updated_at, $this->contact->fresh()?->updated_at);
    }

    public function test_has_tag_olha_onde_o_escopo_manda(): void
    {
        $contactTag = Tag::factory()->contato()->create();
        $ticketTag = Tag::factory()->create();

        $this->assertFalse($this->fx->hasTag($contactTag));
        $this->assertFalse($this->fx->hasTag($ticketTag));

        $this->contact->tags()->attach($contactTag);
        $this->ticket->tags()->attach($ticketTag);

        $this->assertTrue($this->fx->hasTag($contactTag));
        $this->assertTrue($this->fx->hasTag($ticketTag));
    }

    public function test_is_open_contact_name_returning_protocol(): void
    {
        $this->assertTrue($this->fx->isOpen(), 'sem horário cadastrado, está aberto');
        $this->assertSame('Maria', $this->fx->contactName());
        $this->assertFalse($this->fx->returning());
        $this->assertSame($this->ticket->protocol, $this->fx->protocol());

        Ticket::factory()->create(['contact_id' => $this->contact->id, 'channel_id' => $this->ticket->channel_id, 'status' => TicketStatus::ENCERRADO]);
        $this->assertTrue($this->fx->returning());

        $this->contact->update(['name' => null]);
        $this->assertSame('', (new LiveEffects($this->ticket->refresh()))->contactName());
    }
}
