<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\SendTicketMedia;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/** Responder: o `reply_to` vai com o id completo da citada e o trecho fica guardado na mensagem enviada. */
class SendQuotedMessageTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    private Ticket $ticket;

    private User $author;

    private Message $original;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Queue::fake();
        Sleep::fake();
        Storage::fake('local');
        Http::fake(['*/api/send*' => Http::response(['id' => 'true_5516997524950@c.us_OUT1'], 201), '*' => Http::response([], 201)]);

        $this->author = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()
            ->for(Contact::factory()->create(['wa_id' => self::PEER, 'name' => 'Maria']))
            ->for(Channel::factory()->connected()->create(['waha_session' => 'main']))
            ->create(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $this->author->id]);
        $this->original = Message::factory()->for($this->ticket)->create(['wa_message_id' => 'ORIG1', 'body' => 'Quanto custa a chapa?']);
    }

    private function sentTo(string $suffix): ?Request
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), $suffix))->first()[0] ?? null;
    }

    public function test_texto_citado_envia_reply_to_com_o_id_completo_e_grava_o_trecho(): void
    {
        $service = app(SendTicketMessage::class);

        $message = $service->create($this->ticket, $this->author, 'R$ 120 a unidade', MessageOrigin::PAINEL, $this->original);
        $service->deliver($message);

        $this->assertSame('false_'.self::PEER.'_ORIG1', $this->sentTo('/api/sendText')['reply_to']);
        $message->refresh();
        $this->assertSame('ORIG1', $message->quoted_wa_message_id);
        $this->assertSame(['author' => 'Maria', 'type' => 'text', 'excerpt' => 'Quanto custa a chapa?'], $message->quoted_snapshot);
    }

    public function test_citacao_de_mensagem_nossa_usa_true_no_id(): void
    {
        $mine = Message::factory()->for($this->ticket)->create([
            'direction' => 'out', 'origin' => MessageOrigin::PAINEL, 'author_user_id' => $this->author->id, 'wa_message_id' => 'MINE1', 'body' => 'Olá',
        ]);
        $service = app(SendTicketMessage::class);

        $service->deliver($service->create($this->ticket, $this->author, 'Tudo bem?', MessageOrigin::PAINEL, $mine));

        $this->assertSame('true_'.self::PEER.'_MINE1', $this->sentTo('/api/sendText')['reply_to']);
    }

    public function test_anexo_citado_leva_reply_to(): void
    {
        $file = UploadedFile::fake()->createWithContent('foto.jpg', self::JPEG);

        $message = app(SendTicketMedia::class)($this->ticket, $this->author, $file, 'Segue', false, $this->original);
        app(SendTicketMessage::class)->deliver($message);

        $this->assertSame('false_'.self::PEER.'_ORIG1', $this->sentTo('/api/sendImage')['reply_to']);
        $this->assertSame('ORIG1', $message->refresh()->quoted_wa_message_id);
    }

    public function test_sem_citacao_o_envio_continua_igual(): void
    {
        $service = app(SendTicketMessage::class);

        $service->deliver($service->create($this->ticket, $this->author, 'Oi'));

        $this->assertArrayNotHasKey('reply_to', $this->sentTo('/api/sendText')->data());
        $this->assertNull(Message::query()->where('body', 'Oi')->sole()->quoted_wa_message_id);
    }
}
