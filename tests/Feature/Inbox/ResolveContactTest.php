<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
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

    public function test_nao_funde_quando_os_dois_tem_ticket_aberto_no_mesmo_canal(): void
    {
        $channel = Channel::factory()->create();
        $lidOnly = Contact::factory()->create(['wa_id' => null, 'lid' => self::LID]);
        $phone = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'lid' => null]);
        $lidTicket = Ticket::factory()->for($lidOnly)->for($channel)->create(['status' => TicketStatus::EM_ATENDIMENTO]);
        $phoneTicket = Ticket::factory()->for($phone)->for($channel)->create(['status' => TicketStatus::EM_ATENDIMENTO]);

        $resolved = $this->resolve(self::LID, '5516997524950:1@s.whatsapp.net');

        $this->assertTrue($resolved->is($phone));
        $this->assertNull($phone->fresh()?->lid);
        $this->assertSame(self::LID, $lidOnly->fresh()?->lid);
        $this->assertSame($lidOnly->id, $lidTicket->fresh()?->contact_id);
        $this->assertSame($phone->id, $phoneTicket->fresh()?->contact_id);
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
