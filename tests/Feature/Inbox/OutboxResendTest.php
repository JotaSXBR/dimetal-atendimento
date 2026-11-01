<?php

namespace Tests\Feature\Inbox;

use App\Enums\ChannelStatus;
use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SendTicketMessageJob;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\ResendTicketMessage;
use App\Services\Inbox\SendFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class OutboxResendTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function failed(?string $code, array $attributes = []): Message
    {
        return Message::factory()->for($this->ticket)->create(array_merge([
            'direction' => MessageDirection::OUT,
            'origin' => MessageOrigin::PAINEL,
            'author_user_id' => $this->me->id,
            'wa_message_id' => null,
            'delivery_status' => DeliveryStatus::FAILED,
            'failure_code' => $code,
            'failure_reason' => 'motivo',
        ], $attributes));
    }

    private function service(): ResendTicketMessage
    {
        return app(ResendTicketMessage::class);
    }

    public function test_regra_de_reenvio_por_tipo_de_falha(): void
    {
        $this->assertSame('safe', $this->service()->kind($this->failed(SendFailure::EXPIRED), $this->me));
        $this->assertSame('confirm', $this->service()->kind($this->failed(SendFailure::UNKNOWN), $this->me));
        $this->assertSame('confirm', $this->service()->kind($this->failed(SendFailure::REFUSED), $this->me));
        $this->assertSame('none', $this->service()->kind($this->failed(SendFailure::RATE_LIMIT), $this->me));
    }

    public function test_so_reenvia_o_que_o_painel_mandou_e_nao_saiu(): void
    {
        $this->assertSame('none', $this->service()->kind($this->failed(SendFailure::EXPIRED, ['wa_message_id' => 'JA']), $this->me));
        $this->assertSame('none', $this->service()->kind($this->failed(SendFailure::EXPIRED, ['delivery_status' => DeliveryStatus::SENT]), $this->me));
        $this->assertSame('none', $this->service()->kind($this->failed(SendFailure::EXPIRED, ['origin' => MessageOrigin::FORA_DO_PAINEL]), $this->me));
        $this->assertSame('none', $this->service()->kind($this->failed(SendFailure::EXPIRED, ['type' => 'media']), $this->me));
    }

    public function test_mensagem_de_outro_atendente_so_o_supervisor_reenvia(): void
    {
        $other = $this->failed(SendFailure::EXPIRED, ['author_user_id' => User::factory()->create()->id]);

        $this->assertSame('none', $this->service()->kind($other, $this->me));
        $this->assertSame('safe', $this->service()->kind($other, User::factory()->create(['role' => UserRole::SUPERVISOR])));
    }

    public function test_falha_que_pede_confirmacao_nao_reenvia_sem_ela(): void
    {
        $message = $this->failed(SendFailure::UNKNOWN);

        $this->assertSame('confirm', ($this->service())($message, $this->me, false));

        $this->assertSame(DeliveryStatus::FAILED, $message->fresh()?->delivery_status);
        Queue::assertNothingPushed();
    }

    public function test_reenvio_confirmado_usa_a_mesma_linha_e_vai_para_a_fila_de_saida(): void
    {
        $message = $this->failed(SendFailure::UNKNOWN);

        $this->assertSame('sent', ($this->service())($message, $this->me, true));

        $fresh = $message->fresh();
        $this->assertNull($fresh?->delivery_status);
        $this->assertNull($fresh->failure_code);
        $this->assertNull($fresh->failure_reason);
        $this->assertSame(1, Message::query()->count());
        Queue::assertPushedOn('outbound', SendTicketMessageJob::class, fn ($job) => $job->messageId === $message->id);
    }

    public function test_dois_toques_enviam_uma_vez_so(): void
    {
        $message = $this->failed(SendFailure::EXPIRED);

        $this->assertSame('sent', ($this->service())($message, $this->me, true));
        $this->assertSame('none', ($this->service())($message, $this->me, true));

        Queue::assertPushed(SendTicketMessageJob::class, 1);
    }

    public function test_tela_mostra_reenviar_com_confirmacao_so_para_o_que_pode_ter_saido(): void
    {
        $this->failed(SendFailure::UNKNOWN, ['body' => 'incerta']);
        $this->failed(SendFailure::EXPIRED, ['body' => 'expirada']);
        $this->failed(SendFailure::RATE_LIMIT, ['body' => 'limitada']);

        $screen = Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id);

        $html = $screen->html();
        $this->assertSame(2, substr_count($html, '>Reenviar</button>'));
        $this->assertSame(1, substr_count($html, 'wire:confirm="Esta mensagem pode já ter sido enviada'));
    }

    public function test_reenviar_pela_tela_enfileira_e_recusa_sem_confirmacao_quando_precisa(): void
    {
        $unknown = $this->failed(SendFailure::UNKNOWN);

        Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id)
            ->call('resend', $unknown->id)
            ->assertSee('Confirme antes de reenviar');
        Queue::assertNotPushed(SendTicketMessageJob::class);

        Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id)->call('resend', $unknown->id, true);
        Queue::assertPushedOn('outbound', SendTicketMessageJob::class);
    }

    public function test_aguardando_conexao_aparece_na_bolha(): void
    {
        Message::factory()->for($this->ticket)->create([
            'direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL, 'delivery_status' => DeliveryStatus::QUEUED, 'wa_message_id' => null,
        ]);

        Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id)
            ->assertSee('Aguardando conexão')
            ->assertSee('sai sozinha quando a conexão voltar');
    }

    public function test_faixa_de_aviso_quando_o_canal_nao_esta_conectado(): void
    {
        $this->ticket->channel()->firstOrFail()->update(['status' => ChannelStatus::STOPPED]);

        Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id)
            ->assertSee('WhatsApp deste canal está desconectado')
            ->assertSee('saem sozinhas quando a conexão voltar');
    }

    public function test_sem_faixa_quando_o_canal_esta_conectado(): void
    {
        Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id)
            ->assertDontSee('WhatsApp deste canal está desconectado');
    }
}
