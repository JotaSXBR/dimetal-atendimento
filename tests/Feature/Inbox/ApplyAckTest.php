<?php

namespace Tests\Feature\Inbox;

use App\Enums\DeliveryStatus;
use App\Jobs\HandleWahaEvent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\WahaEvent;
use App\Services\Inbox\ApplyAck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApplyAckTest extends TestCase
{
    use RefreshDatabase;

    private const WA_ID = 'true_5516997524950@c.us_ABC';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    }

    private function message(?DeliveryStatus $status = DeliveryStatus::SENT): Message
    {
        $ticket = Ticket::factory()->for($this->channel)->create();

        return Message::factory()->for($ticket)->create([
            'wa_message_id' => self::WA_ID,
            'delivery_status' => $status,
        ]);
    }

    private function ack(int $code, string $id = self::WA_ID): WahaEvent
    {
        return WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => $this->channel->id,
            'event' => 'message.ack',
            'session' => 'main',
            'payload' => ['payload' => ['id' => $id, 'ack' => $code]],
            'received_at' => now(),
        ]);
    }

    public function test_ack_2_marca_entregue_com_horario(): void
    {
        $message = $this->message();

        $this->assertTrue(app(ApplyAck::class)($this->ack(2)));

        $this->assertSame(DeliveryStatus::DELIVERED, $message->fresh()?->delivery_status);
        $this->assertNotNull($message->fresh()?->delivered_at);
        $this->assertNull($message->fresh()?->read_at);
    }

    /** @return array<string, array{int}> */
    public static function codigosDeLeitura(): array
    {
        return ['lido' => [3], 'reproduzido' => [4]];
    }

    #[DataProvider('codigosDeLeitura')]
    public function test_ack_3_e_4_marcam_lido(int $code): void
    {
        $message = $this->message();

        app(ApplyAck::class)($this->ack($code));

        $this->assertSame(DeliveryStatus::READ, $message->fresh()?->delivery_status);
        $this->assertNotNull($message->fresh()?->read_at);
    }

    public function test_ack_atrasado_nunca_rebaixa_o_estado(): void
    {
        $message = $this->message(DeliveryStatus::READ);

        app(ApplyAck::class)($this->ack(1));
        app(ApplyAck::class)($this->ack(2));

        $this->assertSame(DeliveryStatus::READ, $message->fresh()?->delivery_status);
    }

    /** @return array<string, array{int}> */
    public static function codigosIgnorados(): array
    {
        return ['pendente' => [0], 'desconhecido' => [99]];
    }

    #[DataProvider('codigosIgnorados')]
    public function test_ack_pendente_ou_desconhecido_e_ignorado(int $code): void
    {
        $message = $this->message();

        $this->assertTrue(app(ApplyAck::class)($this->ack($code)));

        $this->assertSame(DeliveryStatus::SENT, $message->fresh()?->delivery_status);
    }

    public function test_ack_negativo_marca_falha_apenas_se_nada_melhor(): void
    {
        $sent = $this->message();
        app(ApplyAck::class)($this->ack(-1));
        $this->assertSame(DeliveryStatus::FAILED, $sent->fresh()?->delivery_status);

        $sent->update(['delivery_status' => DeliveryStatus::READ]);
        app(ApplyAck::class)($this->ack(-1));
        $this->assertSame(DeliveryStatus::READ, $sent->fresh()?->delivery_status);
    }

    public function test_ack_de_mensagem_inexistente_devolve_false(): void
    {
        $this->assertFalse(app(ApplyAck::class)($this->ack(2, 'true_x@c.us_NOPE')));
    }

    public function test_job_reenfileira_quando_a_mensagem_ainda_nao_existe_e_descarta_na_sexta_tentativa(): void
    {
        $event = $this->ack(2, 'true_x@c.us_NOPE');

        $job = new class($event->id) extends HandleWahaEvent
        {
            public int $released = -1;

            public int $attempt = 1;

            public function attempts(): int
            {
                return $this->attempt;
            }

            public function release($delay = 0): void
            {
                $this->released = (int) $delay;
            }
        };

        $job->handle();
        $this->assertSame(5, $job->released);

        $job->released = -1;
        $job->attempt = 6;
        $job->handle();
        $this->assertSame(-1, $job->released);
    }
}
