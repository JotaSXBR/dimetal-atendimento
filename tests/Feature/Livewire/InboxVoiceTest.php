<?php

namespace Tests\Feature\Livewire;

use App\Enums\ChannelStatus;
use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SendTicketMessageJob;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

class InboxVoiceTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private const WEBM = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02";

    private User $me;

    private Channel $channel;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Queue::fake();
        Sleep::fake();
        Storage::fake('local');

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        RateLimiter::clear('presence:'.$this->me->id);
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['wa_id' => self::PEER])->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
    }

    private function inbox()
    {
        return Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id);
    }

    private function presenceRequests(): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && ($r['chatId'] ?? null) === self::PEER)->count();
    }

    public function test_comecar_a_gravar_avisa_o_cliente_e_parar_volta_para_pausado(): void
    {
        Http::fake(['*' => Http::response([], 201)]);

        $this->inbox()->call('recording', true)->call('recording', false);

        $presences = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && isset($r['chatId']))
            ->map(fn (array $pair): string => $pair[0]['presence'])->values()->all();
        $this->assertSame(['recording', 'paused'], $presences);
    }

    public function test_depois_de_20_avisos_no_minuto_os_seguintes_sao_ignorados_sem_erro(): void
    {
        Http::fake(['*' => Http::response([], 201)]);
        $component = $this->inbox();

        foreach (range(1, 25) as $ignored) {
            $component->call('recording', true)->assertHasNoErrors();
        }

        $this->assertSame(20, $this->presenceRequests());
    }

    public function test_parar_de_gravar_nao_e_limitado(): void
    {
        Http::fake(['*' => Http::response([], 201)]);
        $component = $this->inbox();

        foreach (range(1, 20) as $ignored) {
            $component->call('recording', true);
        }

        $component->call('recording', false);

        $this->assertSame(21, $this->presenceRequests());
    }

    public function test_falha_do_waha_na_presenca_nao_derruba_nada(): void
    {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

        $this->inbox()->call('recording', true)->assertHasNoErrors();
    }

    public function test_canal_desconectado_nao_chama_o_waha(): void
    {
        Http::fake();
        $this->channel->update(['status' => ChannelStatus::STOPPED]);

        $this->inbox()->call('recording', true);

        Http::assertNothingSent();
    }

    public function test_quem_nao_pode_responder_nao_avisa_gravacao(): void
    {
        Http::fake();
        $this->ticket->update(['assigned_user_id' => null, 'status' => TicketStatus::AGUARDANDO]);

        Livewire::actingAs($this->me)->test(Inbox::class)->set('ticketId', $this->ticket->id)->call('recording', true)->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_gravacao_vira_mensagem_de_voz_com_copia_local_e_vai_pelo_send_voice(): void
    {
        Http::fake(['*' => Http::response(['id' => 'true_'.self::PEER.'_VOZ1'], 201)]);

        $this->inbox()
            ->set('voice', UploadedFile::fake()->createWithContent('audio.webm', self::WEBM))
            ->call('sendVoice')
            ->assertHasNoErrors();

        $message = Message::query()->where('direction', MessageDirection::OUT->value)->sole();
        $this->assertSame('media', $message->type);
        $this->assertSame('audio.webm', $message->media_name);
        $this->assertStringStartsWith('audio/', (string) $message->media_mime);
        Storage::disk('local')->assertExists((string) $message->local_path);
        Queue::assertPushed(SendTicketMessageJob::class, 1);

        app(SendTicketMessage::class)->deliver($message);

        $request = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/sendVoice'))->first()[0] ?? null;
        $this->assertNotNull($request);
        $this->assertTrue($request['convert']);
        $this->assertSame(base64_encode(self::WEBM), $request['file']['data']);
    }

    public function test_arquivo_que_nao_e_audio_nao_passa_como_voz(): void
    {
        $this->inbox()
            ->set('voice', UploadedFile::fake()->createWithContent('audio.webm', "MZ\x90\x00\x03\x00\x00\x00"))
            ->call('sendVoice')
            ->assertHasErrors('voice');

        $this->assertSame(0, Message::query()->where('direction', MessageDirection::OUT->value)->count());
    }

    public function test_voz_sem_permissao_de_resposta_e_recusada(): void
    {
        $this->ticket->update(['assigned_user_id' => null, 'status' => TicketStatus::AGUARDANDO]);

        Livewire::actingAs($this->me)->test(Inbox::class)
            ->set('ticketId', $this->ticket->id)
            ->set('voice', UploadedFile::fake()->createWithContent('audio.webm', self::WEBM))
            ->call('sendVoice')
            ->assertForbidden();
    }
}
