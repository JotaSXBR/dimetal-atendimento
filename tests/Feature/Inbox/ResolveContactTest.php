<?php

namespace Tests\Feature\Inbox;

use App\Enums\ClosedReason;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Inbox\ResolveContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResolveContactTest extends TestCase
{
    use RefreshDatabase;

    private const LID = '16449842241553@lid';

    private function resolve(string $from, ?string $alt = null, ?string $name = null): Contact
    {
        return (new ResolveContact)($from, $alt, $name);
    }

    public function test_lid_com_sender_alt_vira_telefone_sem_o_sufixo_do_dispositivo(): void
    {
        $contact = $this->resolve(self::LID, '5516997524950:53@s.whatsapp.net');

        $this->assertSame('5516997524950@c.us', $contact->wa_id);
        $this->assertSame(self::LID, $contact->lid);
    }

    public function test_sender_alt_sem_sufixo_de_dispositivo_tambem_vale(): void
    {
        $contact = $this->resolve(self::LID, '5516997524950@s.whatsapp.net');

        $this->assertSame('5516997524950@c.us', $contact->wa_id);
    }

    /** @return array<string, array{string|null}> */
    public static function alternativasInvalidas(): array
    {
        return [
            'ausente' => [null],
            'nao e telefone' => ['abc:1@s.whatsapp.net'],
            'zero a esquerda' => ['0516997524950:1@s.whatsapp.net'],
            'curto demais' => ['1234567:1@s.whatsapp.net'],
            'servidor errado' => ['5516997524950:1@lid'],
        ];
    }

    #[DataProvider('alternativasInvalidas')]
    public function test_sem_sender_alt_valido_o_contato_fica_so_com_o_lid(?string $alt): void
    {
        $contact = $this->resolve(self::LID, $alt);

        $this->assertNull($contact->wa_id);
        $this->assertSame(self::LID, $contact->lid);
    }

    public function test_telefone_nunca_e_reescrito_pelo_sender_alt(): void
    {
        $contact = $this->resolve('5511999888777@c.us', '5516997524950:1@s.whatsapp.net');

        $this->assertSame('5511999888777@c.us', $contact->wa_id);
        $this->assertNull($contact->lid);
    }

    public function test_nome_do_remetente_e_cortado_em_120_caracteres(): void
    {
        $contact = $this->resolve('5511999888777@c.us', null, '  '.str_repeat('x', 500).'  ');

        $this->assertSame(120, mb_strlen((string) $contact->name));
    }

    public function test_mesmo_lid_reutiliza_o_contato_e_depois_ganha_o_telefone(): void
    {
        $first = $this->resolve(self::LID);
        $second = $this->resolve(self::LID, '5516997524950:1@s.whatsapp.net');

        $this->assertTrue($first->is($second));
        $this->assertSame('5516997524950@c.us', $second->fresh()?->wa_id);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_contato_editado_mantem_o_nome_quando_chega_push_name(): void
    {
        $contact = Contact::factory()->edited()->create(['wa_id' => '5511999888777@c.us', 'name' => 'Maria Silva']);

        $this->resolve('5511999888777@c.us', null, '😎');

        $this->assertSame('Maria Silva', $contact->fresh()?->name);
    }

    public function test_contato_nunca_editado_recebe_o_novo_push_name(): void
    {
        $contact = Contact::factory()->create(['wa_id' => '5511999888777@c.us', 'name' => 'Maria']);

        $this->resolve('5511999888777@c.us', null, 'Maria S.');

        $this->assertSame('Maria S.', $contact->fresh()?->name);
    }

    public function test_lid_novo_com_o_mesmo_telefone_continua_o_mesmo_contato(): void
    {
        $phone = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'lid' => self::LID]);

        $resolved = $this->resolve('99999999999999@lid', '5516997524950:1@s.whatsapp.net');

        $this->assertTrue($resolved->is($phone));
        $this->assertSame('99999999999999@lid', $phone->fresh()?->lid, 'o lid atual passa a valer');
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_lid_novo_ja_visto_sem_telefone_e_fundido_quando_o_telefone_aparece(): void
    {
        $channel = Channel::factory()->create();
        $phone = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'lid' => self::LID]);
        $lidOnly = Contact::factory()->create(['wa_id' => null, 'lid' => '99999999999999@lid']);
        $ticket = Ticket::factory()->for($lidOnly)->for($channel)->create();

        $resolved = $this->resolve('99999999999999@lid', '5516997524950:1@s.whatsapp.net');

        $this->assertTrue($resolved->is($phone));
        $this->assertNull(Contact::query()->find($lidOnly->id));
        $this->assertSame($phone->id, $ticket->fresh()?->contact_id);
        $this->assertSame('99999999999999@lid', $phone->fresh()?->lid);
    }

    public function test_lid_so_com_ticket_ganha_o_telefone_e_o_ticket_segue(): void
    {
        $channel = Channel::factory()->create();
        $lidOnly = Contact::factory()->create(['wa_id' => null, 'lid' => self::LID]);
        $ticket = Ticket::factory()->for($lidOnly)->for($channel)->create();

        $resolved = $this->resolve(self::LID, '5516997524950:1@s.whatsapp.net');

        $this->assertTrue($resolved->is($lidOnly));
        $this->assertSame('5516997524950@c.us', $resolved->wa_id);
        $this->assertSame($lidOnly->id, $ticket->fresh()?->contact_id);
    }

    public function test_funde_o_registro_do_lid_no_do_telefone(): void
    {
        $channel = Channel::factory()->create();
        $other = Channel::factory()->create(['waha_session' => 'other']);
        $lidOnly = Contact::factory()->create(['wa_id' => null, 'lid' => self::LID, 'name' => 'Push', 'nickname' => 'Chapa']);
        $phone = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'lid' => null, 'nickname' => null]);
        $lidTicket = Ticket::factory()->for($lidOnly)->for($channel)->create();
        $phoneTicket = Ticket::factory()->for($phone)->for($other)->create();
        $message = Message::factory()->for($lidTicket)->create(['contact_id' => $lidOnly->id]);

        $resolved = $this->resolve(self::LID, '5516997524950:1@s.whatsapp.net');

        $this->assertTrue($resolved->is($phone));
        $this->assertSame(self::LID, $phone->fresh()?->lid);
        $this->assertSame('Chapa', $phone->fresh()?->nickname);
        $this->assertNull(Contact::query()->find($lidOnly->id));
        $this->assertSame($phone->id, $lidTicket->fresh()?->contact_id);
        $this->assertSame($phone->id, $phoneTicket->fresh()?->contact_id);
        $this->assertSame($phone->id, $message->fresh()?->contact_id);
    }

    public function test_fusao_mantem_o_nome_editado_do_registro_do_lid(): void
    {
        $lidOnly = Contact::factory()->edited()->create(['wa_id' => null, 'lid' => self::LID, 'name' => 'Maria Editada']);
        $phone = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'lid' => null, 'name' => 'push name']);

        $this->resolve(self::LID, '5516997524950:1@s.whatsapp.net', 'push novo');

        $fresh = $phone->fresh();
        $this->assertSame('Maria Editada', $fresh?->name);
        $this->assertNotNull($fresh?->edited_at);
    }

    public function test_dois_tickets_abertos_no_mesmo_canal_viram_um_com_as_mensagens_juntas(): void
    {
        $channel = Channel::factory()->create();
        $lidOnly = Contact::factory()->create(['wa_id' => null, 'lid' => self::LID]);
        $phone = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'lid' => null]);
        $older = Ticket::factory()->for($phone)->for($channel)->create(['status' => TicketStatus::AGUARDANDO, 'opened_at' => now()->subHour()]);
        $newer = Ticket::factory()->for($lidOnly)->for($channel)->create(['status' => TicketStatus::AGUARDANDO, 'opened_at' => now()->subMinutes(5)]);
        $fromOlder = Message::factory()->for($older)->create(['contact_id' => $phone->id]);
        $fromNewer = Message::factory()->for($newer)->create(['contact_id' => $lidOnly->id]);

        $resolved = $this->resolve(self::LID, '5516997524950:1@s.whatsapp.net');

        $this->assertTrue($resolved->is($phone));
        $this->assertSame(self::LID, $phone->fresh()?->lid);
        $this->assertNull(Contact::query()->find($lidOnly->id));
        $this->assertSame($older->id, $fromOlder->fresh()?->ticket_id);
        $this->assertSame($older->id, $fromNewer->fresh()?->ticket_id, 'a conversa do ticket mais novo foi para o que ficou');
        $this->assertSame($phone->id, $fromNewer->fresh()?->contact_id);
        $closed = $newer->fresh();
        $this->assertSame(TicketStatus::ENCERRADO, $closed?->status);
        $this->assertSame(ClosedReason::MESCLADO, $closed?->closed_reason);
        $this->assertSame($phone->id, $closed?->contact_id);
        $this->assertSame(1, Ticket::query()->open()->where('contact_id', $phone->id)->count());
        $this->assertSame(1, TicketEvent::query()->where('ticket_id', $newer->id)->where('type', 'merged')->count());
    }

    public function test_na_fusao_de_tickets_fica_o_que_ja_tem_atendente(): void
    {
        $channel = Channel::factory()->create();
        $agent = User::factory()->create();
        $lidOnly = Contact::factory()->create(['wa_id' => null, 'lid' => self::LID]);
        $phone = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'lid' => null]);
        $waiting = Ticket::factory()->for($phone)->for($channel)->create(['status' => TicketStatus::AGUARDANDO, 'opened_at' => now()->subHour()]);
        $assigned = Ticket::factory()->for($lidOnly)->for($channel)->create([
            'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $agent->id, 'opened_at' => now()->subMinutes(5),
        ]);

        $this->resolve(self::LID, '5516997524950:1@s.whatsapp.net');

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $assigned->fresh()?->status);
        $this->assertSame($phone->id, $assigned->fresh()?->contact_id);
        $this->assertSame(ClosedReason::MESCLADO, $waiting->fresh()?->closed_reason);
    }

    public function test_fusao_leva_o_espelho_do_google_do_registro_do_lid(): void
    {
        $lidOnly = Contact::factory()->edited()->create([
            'wa_id' => null, 'lid' => self::LID, 'email' => 'a@b.com',
            'google_resource_name' => 'people/c77', 'google_etag' => 'E7', 'google_payload_hash' => 'h', 'google_synced_at' => now(),
        ]);
        $phone = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'lid' => null]);

        $this->resolve(self::LID, '5516997524950:1@s.whatsapp.net');

        $fresh = $phone->fresh();
        $this->assertSame('people/c77', $fresh?->google_resource_name);
        $this->assertSame('E7', $fresh?->google_etag);
        $this->assertNull(Contact::query()->find($lidOnly->id));
    }

    public function test_mensagem_por_lid_com_telefone_trava_as_duas_identidades(): void
    {
        $locks = [];
        DB::listen(function ($query) use (&$locks): void {
            if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $locks[] = $query->bindings[0];
            }
        });

        $this->resolve(self::LID, '5516997524950:1@s.whatsapp.net');

        $this->assertEqualsCanonicalizing(['contact:'.self::LID, 'contact:5516997524950@c.us'], $locks);
        $this->assertSame($locks, collect($locks)->sort()->values()->all(), 'ordem fixa evita impasse entre duas ingestões');
    }

    public function test_resolucao_e_serializada_por_remetente(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->resolve('5511999888777@c.us');

        $this->assertNotEmpty(array_filter($queries, fn (string $sql) => str_contains($sql, 'pg_advisory_xact_lock')));
    }

    public function test_apelido_nao_e_sobrescrito(): void
    {
        $contact = Contact::factory()->create(['wa_id' => '5511999888777@c.us', 'nickname' => 'Maria da chapa']);

        $this->resolve('5511999888777@c.us', null, 'Maria');

        $this->assertSame('Maria da chapa', $contact->fresh()?->nickname);
    }
}
