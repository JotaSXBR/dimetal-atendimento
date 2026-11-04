<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Quem pode responder, reagir, editar e apagar cada mensagem. Tudo exige poder responder ao ticket. */
class MessagePolicyTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond(); // `sent_at` é gravado em segundos: sem microssegundos no relógio, 900 s é exatamente a borda
        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function message(array $attributes = []): Message
    {
        return Message::factory()->for($this->ticket)->create(['wa_message_id' => strtoupper(bin2hex(random_bytes(6))), ...$attributes]);
    }

    /** @return array<string, mixed> */
    private function mine(array $attributes = []): array
    {
        return ['direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL, 'author_user_id' => $this->me->id, 'sent_at' => now(), ...$attributes];
    }

    public function test_mensagem_do_cliente_aceita_responder_e_reagir_mas_nao_editar_nem_apagar(): void
    {
        $message = $this->message();

        $this->assertTrue($this->me->can('reply', $message));
        $this->assertTrue($this->me->can('react', $message));
        $this->assertFalse($this->me->can('edit', $message));
        $this->assertFalse($this->me->can('revoke', $message));
    }

    public function test_mensagem_do_bot_e_do_celular_so_aceitam_responder_e_reagir(): void
    {
        foreach ([MessageOrigin::BOT, MessageOrigin::FORA_DO_PAINEL] as $origin) {
            $message = $this->message(['direction' => MessageDirection::OUT, 'origin' => $origin, 'author_user_id' => null]);

            $this->assertTrue($this->me->can('react', $message));
            $this->assertFalse($this->me->can('edit', $message), $origin->value);
            $this->assertFalse($this->me->can('revoke', $message), $origin->value);
        }
    }

    public function test_a_propria_mensagem_do_painel_aceita_tudo(): void
    {
        $message = $this->message($this->mine());

        foreach (['reply', 'react', 'edit', 'revoke'] as $ability) {
            $this->assertTrue($this->me->can($ability, $message), $ability);
        }
    }

    public function test_mensagem_de_outro_atendente_nao_se_edita_nem_se_apaga(): void
    {
        $other = User::factory()->create(['role' => UserRole::ADMIN]);
        $theirs = $this->message($this->mine(['author_user_id' => $other->id]));
        $mine = $this->message($this->mine());

        $this->assertFalse($this->me->can('edit', $theirs));
        $this->assertFalse($this->me->can('revoke', $theirs));
        $this->assertTrue($other->can('react', $mine), 'o admin responde e reage');
        $this->assertFalse($other->can('edit', $mine), 'nem o admin edita a de outro');
        $this->assertFalse($other->can('revoke', $mine), 'nem apaga');
    }

    public function test_edicao_vale_ate_15_min_e_apagar_nao_tem_janela(): void
    {
        $inside = $this->message($this->mine(['sent_at' => now()->subSeconds(900)]));
        $outside = $this->message($this->mine(['sent_at' => now()->subSeconds(901)]));

        $this->assertTrue($this->me->can('edit', $inside));
        $this->assertFalse($this->me->can('edit', $outside));
        $this->assertTrue($this->me->can('revoke', $outside));
    }

    public function test_so_texto_se_edita_mas_anexo_se_apaga(): void
    {
        $media = $this->message($this->mine(['type' => 'media', 'media_mime' => 'image/jpeg']));

        $this->assertFalse($this->me->can('edit', $media));
        $this->assertTrue($this->me->can('revoke', $media));
    }

    public function test_mensagem_apagada_ou_sem_wa_message_id_nao_aceita_acao(): void
    {
        $revoked = $this->message($this->mine(['revoked_at' => now()]));
        $pending = $this->message($this->mine(['wa_message_id' => null]));

        foreach ([$revoked, $pending] as $message) {
            foreach (['react', 'edit', 'revoke'] as $ability) {
                $this->assertFalse($this->me->can($ability, $message), $ability);
            }
        }
    }

    public function test_sem_poder_responder_ao_ticket_nada_passa(): void
    {
        $message = $this->message($this->mine());
        $this->ticket->update(['status' => TicketStatus::ENCERRADO]);
        $stranger = User::factory()->create(['role' => UserRole::ATENDENTE]);

        foreach (['reply', 'react', 'edit', 'revoke'] as $ability) {
            $this->assertFalse($this->me->can($ability, $message->refresh()), 'encerrado: '.$ability);
            $this->assertFalse($stranger->can($ability, $message), 'outro atendente: '.$ability);
        }
    }
}
