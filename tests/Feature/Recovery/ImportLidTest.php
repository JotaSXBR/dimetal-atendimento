<?php

namespace Tests\Feature\Recovery;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\ImportHistory;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaContacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportLidTest extends TestCase
{
    use RefreshDatabase;

    private const LID = '98765432100@lid';

    private const PHONE = '5516997524950@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        User::factory()->create();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main', 'backfill_days' => 10]);
    }

    /** @return list<array<string, mixed>> */
    private function lidChat(): array
    {
        $at = now()->subDays(6);

        return [['id' => 'false_'.self::LID.'_L1', 'from' => self::LID, 'fromMe' => false, 'body' => 'oi', 'timestamp' => $at->getTimestamp(), 'hasMedia' => false]];
    }

    public function test_phone_for_lid_devolve_o_pn_e_null_no_404(): void
    {
        Http::fake([
            '*/api/main/lids/98765432100%40lid' => Http::response(['lid' => self::LID, 'pn' => self::PHONE]),
            '*/api/main/lids/*' => Http::response(['message' => 'not found'], 404),
        ]);
        $contacts = new WahaContacts(new WahaClient($this->channel));

        $this->assertSame(self::PHONE, $contacts->phoneForLid(self::LID));
        $this->assertNull($contacts->phoneForLid('1@lid'));
    }

    public function test_importacao_resolve_o_telefone_e_funde_com_o_contato_existente(): void
    {
        $existing = Contact::factory()->create(['wa_id' => self::PHONE, 'lid' => null]);
        $old = Ticket::factory()->create(['contact_id' => $existing->id, 'channel_id' => $this->channel->id, 'status' => 'encerrado', 'opened_at' => now()->subDays(20), 'closed_at' => now()->subDays(20)]);
        Http::fake([
            '*/api/main/chats/all/messages*' => Http::response($this->lidChat(), 200),
            '*/api/main/lids/*' => Http::response(['lid' => self::LID, 'pn' => self::PHONE]),
        ]);

        app(ImportHistory::class)($this->channel);

        $contact = Contact::query()->sole();
        $this->assertSame(self::PHONE, $contact->wa_id);
        $this->assertSame(self::LID, $contact->lid);
        $this->assertSame(2, Ticket::query()->where('contact_id', $contact->id)->count());
        $this->assertSame($old->id, Ticket::query()->where('contact_id', $contact->id)->min('id'));
        $this->assertSame(1, Message::query()->where('contact_id', $contact->id)->count());
    }

    public function test_contato_so_lid_ganha_o_telefone(): void
    {
        Http::fake([
            '*/api/main/chats/all/messages*' => Http::response($this->lidChat(), 200),
            '*/api/main/lids/*' => Http::response(['lid' => self::LID, 'pn' => self::PHONE]),
        ]);

        app(ImportHistory::class)($this->channel);

        $this->assertSame(self::PHONE, Contact::query()->sole()->wa_id);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_contains($request->url(), '/lids/')));
    }

    public function test_erro_do_waha_nao_derruba_a_importacao(): void
    {
        Http::fake([
            '*/api/main/chats/all/messages*' => Http::response($this->lidChat(), 200),
            '*/api/main/lids/*' => Http::response('boom', 500),
        ]);

        $service = app(ImportHistory::class);
        $service($this->channel);

        $this->assertTrue($service->complete);
        $contact = Contact::query()->sole();
        $this->assertNull($contact->wa_id);
        $this->assertSame(1, Message::query()->count());
    }
}
