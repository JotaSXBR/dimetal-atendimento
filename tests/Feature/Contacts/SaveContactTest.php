<?php

namespace Tests\Feature\Contacts;

use App\Actions\Contacts\CreateContact;
use App\Actions\Contacts\SaveContact;
use App\Livewire\Forms\ContactForm;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SaveContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_texto_vazio_vira_null_e_a_edicao_e_marcada(): void
    {
        $contact = Contact::factory()->create(['company' => 'Antiga']);

        $saved = app(SaveContact::class)($contact, ['name' => '  Ana  ', 'company' => '', 'email' => '   ', 'urls' => ['', ' https://a.com ']]);

        $fresh = $saved->fresh();
        $this->assertSame('Ana', $fresh?->name);
        $this->assertNull($fresh?->company);
        $this->assertNull($fresh?->email);
        $this->assertSame(['https://a.com'], $fresh?->urls);
        $this->assertNotNull($fresh?->edited_at);
    }

    public function test_urls_vazias_viram_null(): void
    {
        $contact = Contact::factory()->create(['urls' => ['https://a.com']]);

        app(SaveContact::class)($contact, ['urls' => ['', '  ']]);

        $this->assertNull($contact->fresh()?->urls);
    }

    public function test_a_primeira_edicao_e_preservada_na_segunda(): void
    {
        $contact = Contact::factory()->create();
        $this->travelTo(now()->setTime(10, 0));
        app(SaveContact::class)($contact, ['name' => 'Um']);
        $first = $contact->fresh()?->edited_at;

        $this->travelTo(now()->addDay());
        app(SaveContact::class)($contact->fresh() ?? $contact, ['name' => 'Dois']);

        $this->assertEquals($first, $contact->fresh()?->edited_at);
    }

    public function test_telefone_principal_nao_muda_mesmo_se_vier_nos_dados(): void
    {
        $contact = Contact::factory()->create(['wa_id' => '5516997524950@c.us']);

        app(SaveContact::class)($contact, ['name' => 'Ana', 'wa_id' => '5511111111111@c.us', 'google_resource_name' => 'people/x']);

        $this->assertSame('5516997524950@c.us', $contact->fresh()?->wa_id);
        $this->assertNull($contact->fresh()?->google_resource_name);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return list<string>
     */
    private function errorsFor(array $override): array
    {
        $form = new ContactForm(new class extends Component {}, 'form');
        $data = array_replace(['name' => 'Ana', 'birthday' => '', 'urls' => []], $override);

        return array_values(Validator::make($data, $form->rules(), $form->messages())->errors()->keys());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function entradasInvalidas(): array
    {
        return [
            'sem nome' => [['name' => ''], 'name'],
            'nome longo' => [['name' => str_repeat('a', 121)], 'name'],
            'email invalido' => [['email' => 'nao-e-email'], 'email'],
            'aniversario inexistente' => [['birthday' => '1990-02-30'], 'birthday'],
            'url ftp' => [['urls' => ['ftp://x.com']], 'urls.0'],
            'url com senha' => [['urls' => ['https://u:p@x.com']], 'urls.0'],
            'url sem host' => [['urls' => ['https://']], 'urls.0'],
            'seis urls' => [['urls' => array_fill(0, 6, 'https://a.com')], 'urls'],
            'bidi no nome' => [['name' => "Ana\u{202E}"], 'name'],
            'nul na empresa' => [['company' => "A\x00B"], 'company'],
            'quebra de linha no nome' => [['name' => "A\nB"], 'name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('entradasInvalidas')]
    public function test_regras_recusam(array $override, string $campo): void
    {
        $this->assertContains($campo, $this->errorsFor($override));
    }

    public function test_quebra_de_linha_vale_em_observacoes_e_endereco(): void
    {
        $this->assertSame([], $this->errorsFor(['notes' => "linha 1\nlinha 2", 'address' => "Rua A\nCentro", 'birthday' => '1990-02-28', 'urls' => ['https://a.com']]));
    }

    public function test_criar_normaliza_o_telefone_e_nao_duplica(): void
    {
        $first = app(CreateContact::class)('(16) 99752-4950', 'Ana');
        $again = app(CreateContact::class)('+55 16 99752-4950', 'Outra');

        $this->assertSame('5516997524950@c.us', $first['contact']->wa_id);
        $this->assertTrue($first['created']);
        $this->assertNotNull($first['contact']->edited_at);
        $this->assertSame($first['contact']->id, $again['contact']->id);
        $this->assertFalse($again['created']);
        $this->assertSame('Ana', $again['contact']->fresh()?->name);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_celular_com_ou_sem_o_nono_digito_abre_o_mesmo_contato(): void
    {
        $semNove = Contact::factory()->create(['wa_id' => '551187654321@c.us']);

        $result = app(CreateContact::class)('(11) 98765-4321', 'Ana');

        $this->assertSame($semNove->id, $result['contact']->id);
        $this->assertFalse($result['created']);
        $this->assertSame(1, Contact::query()->count());

        $comNove = Contact::factory()->create(['wa_id' => '5521987654321@c.us']);

        $this->assertSame($comNove->id, app(CreateContact::class)('(21) 8765-4321', 'Bia')['contact']->id);
    }

    public function test_zero_de_tronco_na_frente_nao_impede_o_ddi(): void
    {
        $result = app(CreateContact::class)('011 98765-4321', 'Ana');

        $this->assertSame('5511987654321@c.us', $result['contact']->wa_id);
    }

    public function test_corrida_no_cadastro_nao_estoura_o_indice_unico(): void
    {
        $fired = false;
        // O concorrente grava logo depois da consulta de existência e antes do insert, como numa corrida de verdade.
        DB::listen(function ($query) use (&$fired): void {
            if (! $fired && str_contains($query->sql, '"wa_id" in')) {
                $fired = true;
                DB::table('contacts')->insert(['wa_id' => '5516997524950@c.us', 'name' => 'Concorrente', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $result = app(CreateContact::class)('(16) 99752-4950', 'Ana');

        $this->assertFalse($result['created']);
        $this->assertSame('Concorrente', $result['contact']->name);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_telefone_invalido_falha_no_campo_phone(): void
    {
        try {
            app(CreateContact::class)('12', 'Ana');
            $this->fail('Deveria recusar o telefone.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('phone', $e->errors());
        }
    }
}
