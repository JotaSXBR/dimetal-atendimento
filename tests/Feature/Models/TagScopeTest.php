<?php

namespace Tests\Feature\Models;

use App\Enums\ChannelKind;
use App\Enums\TagColor;
use App\Enums\TagScope;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TagScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_etiqueta_nova_e_da_conversa_e_tem_o_tom_padrao(): void
    {
        $tag = Tag::query()->create(['name' => 'VIP'])->refresh();

        $this->assertSame(TagScope::CONVERSA, $tag->scope);
        $this->assertSame(TagColor::default(), $tag->color);
    }

    public function test_aplicar_etiqueta_no_contato_nao_toca_o_contato(): void
    {
        $contact = Contact::factory()->create();
        $before = $contact->fresh();
        $tag = Tag::factory()->contato()->create();

        $this->travel(5)->minutes();
        $contact->tags()->attach($tag);

        $after = $contact->fresh();
        $this->assertEquals($before->updated_at, $after->updated_at);
        $this->assertNull($after->edited_at);
        $this->assertSame([$tag->id], $contact->tags()->pluck('tags.id')->all());
    }

    public function test_apagar_a_etiqueta_ou_o_contato_nao_deixa_linha_orfa(): void
    {
        $contact = Contact::factory()->create();
        $other = Contact::factory()->create();
        $tag = Tag::factory()->contato()->create();
        $kept = Tag::factory()->contato()->create();
        $contact->tags()->attach([$tag->id, $kept->id]);
        $other->tags()->attach($tag);

        $tag->delete();
        $this->assertSame(1, DB::table('contact_tag')->count());

        $contact->delete();
        $this->assertSame(0, DB::table('contact_tag')->count());
    }

    public function test_o_mesmo_par_contato_etiqueta_nao_repete(): void
    {
        $contact = Contact::factory()->create();
        $tag = Tag::factory()->contato()->create();
        DB::table('contact_tag')->insert(['contact_id' => $contact->id, 'tag_id' => $tag->id]);

        $this->expectException(QueryException::class);
        DB::table('contact_tag')->insert(['contact_id' => $contact->id, 'tag_id' => $tag->id]);
    }

    public function test_os_escopos_separam_etiqueta_de_contato_e_de_conversa(): void
    {
        $contact = Tag::factory()->contato()->create();
        $ticket = Tag::factory()->create();

        $this->assertSame([$contact->id], Tag::query()->ofContact()->pluck('id')->all());
        $this->assertSame([$ticket->id], Tag::query()->ofTicket()->pluck('id')->all());
    }

    public function test_canal_novo_e_whatsapp_e_o_ticket_guarda_quando_o_atendente_viu(): void
    {
        $channel = Channel::factory()->create()->refresh();
        $this->assertSame(ChannelKind::WHATSAPP, $channel->kind);
        $this->assertNotSame('', ChannelKind::WHATSAPP->icon());
        $this->assertSame('WhatsApp', ChannelKind::WHATSAPP->label());

        $ticket = Ticket::factory()->create(['channel_id' => $channel->id]);
        $this->assertNull($ticket->fresh()->agent_seen_at);

        $ticket->forceFill(['agent_seen_at' => now()])->save();
        $this->assertNotNull($ticket->fresh()->agent_seen_at);
    }
}
