<?php

namespace Tests\Feature\Livewire;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\NoteOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Transcript;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Cada tipo de bolha tem a sua cor, e o âmbar fica só para nota interna e aviso: nada de dois significados na mesma cor. */
class BubbleColorsTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ticket = Ticket::factory()
            ->for(Contact::factory()->create())
            ->for(Channel::factory()->create())
            ->create(['status' => TicketStatus::EM_ATENDIMENTO]);
    }

    private function html(Message $message): string
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Transcript::class, ['ticketId' => $this->ticket->id])->html();
    }

    private function message(MessageDirection $direction, MessageOrigin $origin, string $body): Message
    {
        return Message::factory()->for($this->ticket)->create(['direction' => $direction, 'origin' => $origin, 'body' => $body, 'type' => 'text', 'sent_at' => now()]);
    }

    /** As classes do `div` da bolha que contém o texto. */
    private function bubble(string $html, string $body): string
    {
        $this->assertSame(1, preg_match('/<div x-data="messageEdit" class="([^"]*)"[^>]*>(?:(?!<div x-data="messageEdit").)*?'.preg_quote($body, '/').'/s', $html, $m), "bolha de '{$body}' ausente");

        return $m[1];
    }

    public function test_cliente_branca_com_borda_e_nossa_neutra_suave(): void
    {
        $this->message(MessageDirection::IN, MessageOrigin::CONTATO, 'Oi do cliente');
        $this->message(MessageDirection::OUT, MessageOrigin::PAINEL, 'Resposta do atendente');
        $html = $this->html($this->ticket->messages()->firstOrFail());

        $client = $this->bubble($html, 'Oi do cliente');
        $this->assertStringContainsString('bg-surface', $client);
        $this->assertStringContainsString('border-line', $client);
        $this->assertStringContainsString('bg-accent-soft', $this->bubble($html, 'Resposta do atendente'));
    }

    public function test_bolha_do_bot_tem_o_tom_do_bot_e_o_rotulo_no_mesmo_tom(): void
    {
        $this->message(MessageDirection::OUT, MessageOrigin::BOT, 'Resposta automática do bot');
        $html = $this->html($this->ticket->messages()->firstOrFail());

        $this->assertStringContainsString('bg-bot/15', $this->bubble($html, 'Resposta automática do bot'));
        $this->assertMatchesRegularExpression('/text-bot[^>]*>[^<]*Bot · resposta automática/', $html);
    }

    public function test_nota_interna_tem_o_fundo_amarelo_de_aviso_e_nao_o_ambar_translucido(): void
    {
        TicketNote::create(['ticket_id' => $this->ticket->id, 'user_id' => User::factory()->create()->id, 'origin' => NoteOrigin::EQUIPE, 'body' => 'Só a equipe vê']);
        $html = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Transcript::class, ['ticketId' => $this->ticket->id])->html();

        $this->assertMatchesRegularExpression('/data-testid="note-card" class="[^"]*bg-warn-soft[^"]*border-warn-line|data-testid="note-card" class="[^"]*border-warn-line[^"]*bg-warn-soft/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-testid="note-card" class="[^"]*bg-warn\/10/', $html);
        $this->assertStringContainsString('Nota interna', $html);
    }

    public function test_enviada_pelo_celular_e_tracejada_e_neutra_sem_ambar(): void
    {
        $this->message(MessageDirection::OUT, MessageOrigin::FORA_DO_PAINEL, 'Mandei pelo celular');
        $html = $this->html($this->ticket->messages()->firstOrFail());

        $bubble = $this->bubble($html, 'Mandei pelo celular');
        $this->assertStringContainsString('border-dashed', $bubble);
        $this->assertStringNotContainsString('warn', $bubble);
        $this->assertStringContainsString('Celular · fora do painel', $html);
    }
}
