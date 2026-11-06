<?php

namespace Tests\Feature\Inbox;

use App\Jobs\FillContactNameJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Services\Inbox\FillContactName;
use App\Services\Inbox\ResolveContact;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FillContactNameTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '5516997524950@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Cache::flush();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    }

    private function waha(?string $agenda, ?string $push): void
    {
        Http::fake(['*/api/contacts*' => Http::response(['id' => self::PHONE, 'name' => $agenda, 'pushname' => $push])]);
    }

    private function fill(Contact $contact): void
    {
        app(FillContactName::class)($contact, $this->channel);
    }

    public function test_contact_names_devolve_agenda_e_push(): void
    {
        $this->waha('Maria da Loja', 'Maria');
        $client = new WahaContacts(new WahaClient($this->channel));

        $this->assertSame(['agenda' => 'Maria da Loja', 'push' => 'Maria'], $client->contactNames(self::PHONE));
    }

    public function test_contact_names_vazio_no_404(): void
    {
        Http::fake(['*/api/contacts*' => Http::response(['message' => 'x'], 404)]);

        $this->assertSame(['agenda' => null, 'push' => null], (new WahaContacts(new WahaClient($this->channel)))->contactNames(self::PHONE));
    }

    public function test_nome_da_agenda_vale_mais_que_o_push_name_ja_gravado(): void
    {
        $contact = Contact::factory()->create(['wa_id' => self::PHONE, 'name' => 'Mariazinha 😎']);
        $this->waha('Maria da Loja', 'Maria');

        $this->fill($contact);

        $this->assertSame('Maria da Loja', $contact->fresh()?->name);
        $this->assertSame('agenda', $contact->fresh()?->name_source);
    }

    public function test_sem_agenda_o_push_name_so_preenche_contato_sem_nome(): void
    {
        $semNome = Contact::factory()->create(['wa_id' => self::PHONE, 'name' => null]);
        $comNome = Contact::factory()->create(['wa_id' => '5516981110000@c.us', 'name' => 'Já tinha']);
        $this->waha(null, 'Maria');

        $this->fill($semNome);
        $this->fill($comNome);

        $this->assertSame('Maria', $semNome->fresh()?->name);
        $this->assertNull($semNome->fresh()?->name_source);
        $this->assertSame('Já tinha', $comNome->fresh()?->name);
    }

    public function test_contato_editado_nunca_muda(): void
    {
        $contact = Contact::factory()->edited()->create(['wa_id' => self::PHONE, 'name' => 'Nome do atendente']);
        $this->waha('Maria da Loja', 'Maria');

        $this->fill($contact);

        $this->assertSame('Nome do atendente', $contact->fresh()?->name);
        Http::assertNothingSent();
    }

    public function test_push_name_novo_nao_apaga_o_nome_da_agenda(): void
    {
        $contact = Contact::factory()->create(['wa_id' => self::PHONE, 'name' => 'Maria da Loja', 'name_source' => 'agenda']);

        app(ResolveContact::class)(self::PHONE, null, 'Mari 😎');

        $this->assertSame('Maria da Loja', $contact->fresh()?->name);
    }

    public function test_contato_so_lid_consulta_pelo_lid(): void
    {
        $contact = Contact::factory()->create(['wa_id' => null, 'lid' => '98765@lid', 'name' => null]);
        $this->waha('Maria da Loja', null);

        $this->fill($contact);

        Http::assertSent(fn ($request): bool => str_contains(urldecode($request->url()), 'contactId=98765@lid'));
        $this->assertSame('Maria da Loja', $contact->fresh()?->name);
    }

    public function test_enfileira_uma_vez_por_dia_e_so_sem_agenda_e_sem_edicao(): void
    {
        Queue::fake();
        $contact = Contact::factory()->create(['wa_id' => self::PHONE]);
        $service = app(FillContactName::class);

        $service->queueFor($contact, $this->channel);
        $service->queueFor($contact, $this->channel);
        $service->queueFor(Contact::factory()->edited()->create(['wa_id' => '5516981110000@c.us']), $this->channel);
        $service->queueFor(Contact::factory()->create(['wa_id' => '5516982220000@c.us', 'name_source' => 'agenda']), $this->channel);

        Queue::assertPushed(FillContactNameJob::class, 1);
    }

    public function test_erro_do_waha_no_job_nao_estoura_e_deixa_o_nome(): void
    {
        $contact = Contact::factory()->create(['wa_id' => self::PHONE, 'name' => 'Maria']);
        Http::fake(['*/api/contacts*' => Http::response('boom', 500)]);

        (new FillContactNameJob($contact->id, $this->channel->id))->handle(app(FillContactName::class));

        $this->assertSame('Maria', $contact->fresh()?->name);
    }
}
