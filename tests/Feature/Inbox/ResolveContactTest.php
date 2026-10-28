<?php

namespace Tests\Feature\Inbox;

use App\Models\Contact;
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

    public function test_lid_que_pertence_a_outro_contato_nao_e_reatribuido_nem_quebra(): void
    {
        $lidOnly = Contact::factory()->create(['wa_id' => null, 'lid' => self::LID]);
        $phone = Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'lid' => null]);

        $resolved = $this->resolve(self::LID, '5516997524950:1@s.whatsapp.net');

        $this->assertTrue($resolved->is($phone));
        $this->assertNull($phone->fresh()?->lid);
        $this->assertSame(self::LID, $lidOnly->fresh()?->lid);
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
