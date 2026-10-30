<?php

namespace Tests\Feature\Domain;

use App\Models\Contact;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactProfileSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_grava_e_le_o_perfil_completo_com_os_tipos_certos(): void
    {
        $contact = Contact::factory()->create([
            'phone2' => '+5516333344',
            'email' => 'ana@exemplo.com.br',
            'company' => 'Metalúrgica Ana',
            'job_title' => 'Compras',
            'address' => 'Rua A, 10',
            'notes' => 'Cliente antigo',
            'birthday' => '1990-02-28',
            'urls' => ['https://exemplo.com.br'],
            'edited_at' => now(),
            'google_resource_name' => 'people/c123',
            'google_etag' => 'etag1',
            'google_payload_hash' => 'abc',
            'google_synced_at' => now(),
            'google_attempted_at' => now(),
            'google_error' => 'quota',
        ]);

        $fresh = Contact::query()->findOrFail($contact->id);

        $this->assertSame(['https://exemplo.com.br'], $fresh->urls);
        $this->assertSame('1990-02-28', $fresh->birthday?->toDateString());
        $this->assertNotNull($fresh->edited_at);
        $this->assertNotNull($fresh->google_synced_at);
        $this->assertSame('people/c123', $fresh->google_resource_name);
    }

    public function test_contato_novo_nasce_sem_edicao_e_sem_espelho(): void
    {
        $contact = Contact::query()->findOrFail(Contact::factory()->create()->id);

        $this->assertNull($contact->edited_at);
        $this->assertNull($contact->google_resource_name);
        $this->assertNull($contact->urls);
    }

    public function test_factory_edited_marca_a_edicao(): void
    {
        $this->assertNotNull(Contact::factory()->edited()->create()->edited_at);
    }

    public function test_dois_contatos_nao_dividem_o_mesmo_contato_do_google(): void
    {
        Contact::factory()->create(['google_resource_name' => 'people/c1']);

        $this->expectException(UniqueConstraintViolationException::class);

        Contact::factory()->create(['google_resource_name' => 'people/c1']);
    }
}
