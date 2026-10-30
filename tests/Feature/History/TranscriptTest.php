<?php

namespace Tests\Feature\History;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Inbox\SendFailure;
use App\Support\TicketEventText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptTest extends TestCase
{
    use RefreshDatabase;

    private function render(bool $readonly): string
    {
        $user = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $this->actingAs($user);

        $ticket = Ticket::factory()->create();
        Message::factory()->for($ticket)->create([
            'direction' => MessageDirection::OUT,
            'origin' => MessageOrigin::PAINEL,
            'author_user_id' => $user->id,
            'wa_message_id' => null,
            'body' => 'Segue a cotacao',
            'delivery_status' => DeliveryStatus::FAILED,
            'failure_code' => SendFailure::EXPIRED,
            'failure_reason' => 'Expirou',
        ]);

        return view('livewire.inbox.transcript', ['messages' => $ticket->messages()->get(), 'readonly' => $readonly])->render();
    }

    public function test_modo_leitura_mostra_a_mensagem_mas_nao_o_reenviar(): void
    {
        $readonly = $this->render(true);

        $this->assertStringContainsString('Segue a cotacao', $readonly);
        $this->assertStringNotContainsString('Reenviar', $readonly);
    }

    public function test_modo_normal_continua_oferecendo_o_reenviar(): void
    {
        $this->assertStringContainsString('Reenviar', $this->render(false));
    }

    public function test_event_text_rotula_e_detalha_o_encerramento(): void
    {
        $event = new TicketEvent(['type' => 'closed', 'payload' => ['outcome' => 'resolvido', 'summary' => 'Cotacao enviada']]);

        $this->assertSame('Encerrado', TicketEventText::label('closed'));
        $this->assertSame('tipo_novo', TicketEventText::label('tipo_novo'));
        $this->assertSame('Resolvido · Cotacao enviada', TicketEventText::detail($event));
    }
}
