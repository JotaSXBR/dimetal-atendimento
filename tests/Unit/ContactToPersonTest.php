<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Services\Google\ContactToPerson;
use Tests\TestCase;

class ContactToPersonTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function contact(array $attributes = []): Contact
    {
        return new Contact(array_replace(['wa_id' => '5516997524950@c.us', 'name' => 'Maria Silva', 'edited_at' => now()], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function map(array $attributes = []): array
    {
        return (new ContactToPerson)->map($this->contact($attributes));
    }

    public function test_perfil_completo(): void
    {
        $person = $this->map([
            'phone2' => '(16) 3333-4444',
            'email' => 'maria@silva.com.br',
            'company' => 'Silva Aços',
            'job_title' => 'Compras',
            'address' => "Rua A, 10\nCentro",
            'notes' => "Cliente antigo\nPaga em 28 dias",
            'birthday' => '1990-02-28',
            'urls' => ['https://silva.com.br'],
        ]);

        $this->assertSame([['unstructuredName' => 'Maria Silva']], $person['names']);
        $this->assertSame([['value' => '+5516997524950'], ['value' => '(16) 3333-4444']], $person['phoneNumbers']);
        $this->assertSame([['value' => 'maria@silva.com.br']], $person['emailAddresses']);
        $this->assertSame([['name' => 'Silva Aços', 'title' => 'Compras']], $person['organizations']);
        $this->assertSame([['formattedValue' => "Rua A, 10\nCentro"]], $person['addresses']);
        $this->assertSame([['value' => "Cliente antigo\nPaga em 28 dias", 'contentType' => 'TEXT_PLAIN']], $person['biographies']);
        $this->assertSame([['date' => ['year' => 1990, 'month' => 2, 'day' => 28]]], $person['birthdays']);
        $this->assertSame([['value' => 'https://silva.com.br']], $person['urls']);
    }

    public function test_campos_vazios_ficam_ausentes_e_apelido_nunca_vai(): void
    {
        $person = $this->map(['nickname' => 'Maria da chapa', 'company' => null, 'email' => '']);

        $this->assertSame(['names', 'phoneNumbers'], array_keys($person));
        $this->assertStringNotContainsString('chapa', (string) json_encode($person));
    }

    public function test_segundo_telefone_igual_ao_do_whatsapp_nao_repete(): void
    {
        $this->assertCount(1, $this->map(['phone2' => '+55 16 99752-4950'])['phoneNumbers']);
        $this->assertCount(1, $this->map(['phone2' => '16997524950'])['phoneNumbers']);
    }

    public function test_empresa_ou_cargo_sozinhos(): void
    {
        $this->assertSame([['name' => 'Silva']], $this->map(['company' => 'Silva'])['organizations']);
        $this->assertSame([['title' => 'Compras']], $this->map(['job_title' => 'Compras'])['organizations']);
    }

    public function test_texto_com_caractere_bidi_ou_de_controle_e_descartado(): void
    {
        $person = $this->map(['name' => "Maria\u{202E}", 'company' => "Ac\x00me", 'email' => "a@b.com\u{200F}", 'address' => "Rua\u{202A}", 'notes' => "ok\x07"]);

        $this->assertArrayNotHasKey('names', $person);
        $this->assertArrayNotHasKey('organizations', $person);
        $this->assertArrayNotHasKey('emailAddresses', $person);
        $this->assertArrayNotHasKey('addresses', $person);
        $this->assertArrayNotHasKey('biographies', $person);
    }

    public function test_quebra_de_linha_so_vale_em_endereco_e_observacoes(): void
    {
        $person = $this->map(['name' => "Maria\nSilva", 'company' => "A\tB"]);

        $this->assertArrayNotHasKey('names', $person);
        $this->assertArrayNotHasKey('organizations', $person);
    }

    public function test_url_com_credencial_ou_esquema_invalido_e_descartada(): void
    {
        $person = $this->map(['urls' => ['https://u:p@x.com', 'ftp://x.com', 'javascript:alert(1)', 'https://ok.com', 'sem-esquema.com']]);

        $this->assertSame([['value' => 'https://ok.com']], $person['urls']);
    }

    public function test_so_um_nome_aniversario_e_biografia(): void
    {
        $person = $this->map(['birthday' => '1990-02-28', 'notes' => 'x']);

        $this->assertCount(1, $person['names']);
        $this->assertCount(1, $person['birthdays']);
        $this->assertCount(1, $person['biographies']);
    }

    public function test_mascara_completa_de_campos(): void
    {
        $this->assertSame(
            ['names', 'phoneNumbers', 'emailAddresses', 'organizations', 'addresses', 'biographies', 'birthdays', 'urls'],
            (new ContactToPerson)->fields(),
        );
    }

    public function test_hash_igual_para_o_mesmo_conteudo_em_qualquer_ordem(): void
    {
        $mapper = new ContactToPerson;

        $this->assertSame($mapper->hash(['b' => 1, 'a' => ['y' => 2, 'x' => 3]]), $mapper->hash(['a' => ['x' => 3, 'y' => 2], 'b' => 1]));
        $this->assertNotSame($mapper->hash(['a' => 1]), $mapper->hash(['a' => 2]));
    }

    public function test_elegibilidade(): void
    {
        $mapper = new ContactToPerson;

        $this->assertTrue($mapper->isEligible($this->contact()));
        $this->assertTrue($mapper->isEligible($this->contact(['wa_id' => null, 'lid' => '123@lid', 'email' => 'a@b.com'])));
        $this->assertFalse($mapper->isEligible($this->contact(['wa_id' => null, 'lid' => '123@lid'])), 'só LID e sem e-mail');
        $this->assertFalse($mapper->isEligible($this->contact(['edited_at' => null])), 'nunca editado');
    }
}
