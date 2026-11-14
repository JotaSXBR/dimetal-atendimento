<?php

namespace Tests\Feature\Inbox;

use App\Enums\NoteOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SendTicketMessageJob;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Inbox\Transcript;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class SaveNoteTest extends TestCase
{
    use RefreshDatabase;

    private ?Channel $channel = null;

    private function ticket(User $owner, TicketStatus $status = TicketStatus::EM_ATENDIMENTO): Ticket
    {
        return Ticket::factory()->create(['channel_id' => ($this->channel ??= Channel::factory()->create())->id, 'status' => $status, 'assigned_user_id' => $owner->id]);
    }

    public function test_salvar_nota_grava_limpa_o_campo_e_nao_envia_nada(): void
    {
        Queue::fake();
        Http::fake();
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = $this->ticket($me);

        Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id)
            ->set('draft', 'combinado X')
            ->call('saveNote')
            ->assertSet('draft', '')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('ticket_notes', ['ticket_id' => $ticket->id, 'user_id' => $me->id, 'body' => 'combinado X']);
        $this->assertSame(0, Message::query()->count());
        $this->assertNull($ticket->fresh()->last_outbound_at);
        Queue::assertNotPushed(SendTicketMessageJob::class);
        Http::assertNothingSent();
    }

    public function test_nota_vazia_ou_longa_demais_e_recusada(): void
    {
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = $this->ticket($me);
        $screen = Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id);

        $screen->set('draft', '')->call('saveNote')->assertHasErrors('draft');
        $screen->set('draft', str_repeat('a', 4097))->call('saveNote')->assertHasErrors('draft');

        $this->assertSame(0, TicketNote::query()->count());
    }

    public function test_nota_em_ticket_encerrado_ou_de_outro_atendente_e_recusada(): void
    {
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $outro = User::factory()->create(['role' => UserRole::ATENDENTE]);

        $encerrado = $this->ticket($me, TicketStatus::ENCERRADO);
        Livewire::actingAs($me)->test(Inbox::class)->call('select', $encerrado->id)->set('draft', 'x')->call('saveNote')->assertForbidden();

        $alheio = $this->ticket($outro);
        Livewire::actingAs($me)->test(Inbox::class)->set('ticketId', $alheio->id)->set('draft', 'x')->call('saveNote')->assertStatus(404);

        $this->assertSame(0, TicketNote::query()->count());
    }

    public function test_so_o_autor_apaga_pelo_transcript(): void
    {
        $autor = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $ticket = $this->ticket($autor);
        $note = TicketNote::factory()->for($ticket)->create(['user_id' => $autor->id]);
        $outraDoutroTicket = TicketNote::factory()->for($this->ticket($autor))->create(['user_id' => $autor->id]);

        Livewire::actingAs($admin)->test(Transcript::class, ['ticketId' => $ticket->id])->call('deleteNote', $note->id)->assertForbidden();
        $this->assertDatabaseHas('ticket_notes', ['id' => $note->id]);

        $screen = Livewire::actingAs($autor)->test(Transcript::class, ['ticketId' => $ticket->id]);
        $screen->call('deleteNote', $outraDoutroTicket->id);
        $this->assertDatabaseHas('ticket_notes', ['id' => $outraDoutroTicket->id]);

        $screen->call('deleteNote', $note->id);
        $this->assertDatabaseMissing('ticket_notes', ['id' => $note->id]);
        $this->assertTrue(TicketEvent::query()->where(['ticket_id' => $ticket->id, 'type' => 'note_deleted', 'actor_user_id' => $autor->id])->exists());
    }

    /** @return array{0: User, 1: Ticket, 2: TicketNote} */
    private function noteOf(): array
    {
        $autor = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = $this->ticket($autor);
        $note = TicketNote::factory()->for($ticket)->create(['user_id' => $autor->id, 'body' => 'Ligar amanhã', 'created_at' => now()->subHour()->startOfSecond()]);

        return [$autor, $ticket, $note];
    }

    private function undoToken(Testable $screen): string
    {
        $params = json_encode(collect($screen->effects['dispatches'] ?? [])->firstWhere('name', 'toast-show')['params'] ?? [], JSON_UNESCAPED_UNICODE);
        preg_match('/"token":"([A-Za-z0-9]{32})"/', (string) $params, $match);

        return $match[1] ?? '';
    }

    public function test_apagar_a_nota_oferece_desfazer(): void
    {
        [$autor, $ticket, $note] = $this->noteOf();

        $screen = Livewire::actingAs($autor)->test(Transcript::class, ['ticketId' => $ticket->id])->call('deleteNote', $note->id);

        $screen->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains(json_encode($params, JSON_UNESCAPED_UNICODE), 'Nota apagada.') && str_contains(json_encode($params), 'undo-note-delete'));
        $this->assertNotSame('', $this->undoToken($screen));
        $this->assertDatabaseMissing('ticket_notes', ['id' => $note->id]);
    }

    public function test_desfazer_recria_a_nota_identica_e_registra_o_evento(): void
    {
        [$autor, $ticket, $note] = $this->noteOf();
        $screen = Livewire::actingAs($autor)->test(Transcript::class, ['ticketId' => $ticket->id])->call('deleteNote', $note->id);

        $screen->call('restoreNote', $this->undoToken($screen));

        $restored = TicketNote::query()->where('ticket_id', $ticket->id)->sole();
        $this->assertSame(['Ligar amanhã', $autor->id, NoteOrigin::EQUIPE], [$restored->body, $restored->user_id, $restored->origin]);
        $this->assertTrue($note->created_at->equalTo($restored->created_at));
        $this->assertTrue(TicketEvent::query()->where(['ticket_id' => $ticket->id, 'type' => 'note_restored', 'actor_user_id' => $autor->id])->exists());
    }

    public function test_o_mesmo_token_nao_recria_duas_vezes(): void
    {
        [$autor, $ticket, $note] = $this->noteOf();
        $screen = Livewire::actingAs($autor)->test(Transcript::class, ['ticketId' => $ticket->id])->call('deleteNote', $note->id);
        $token = $this->undoToken($screen);

        $screen->call('restoreNote', $token)->call('restoreNote', $token);

        $this->assertSame(1, TicketNote::query()->where('ticket_id', $ticket->id)->count());
        $this->assertSame(1, TicketEvent::query()->where('type', 'note_restored')->count());
    }

    public function test_token_de_outro_usuario_ou_inventado_ou_vencido_nao_faz_nada(): void
    {
        [$autor, $ticket, $note] = $this->noteOf();
        $screen = Livewire::actingAs($autor)->test(Transcript::class, ['ticketId' => $ticket->id])->call('deleteNote', $note->id);
        $token = $this->undoToken($screen);
        $outro = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($outro)->test(Transcript::class, ['ticketId' => $ticket->id])->call('restoreNote', $token);
        $screen->call('restoreNote', str_repeat('x', 32));
        $this->assertSame(0, TicketNote::query()->count());

        Cache::forget("undo-note:{$autor->id}:{$token}");
        $screen->call('restoreNote', $token);
        $this->assertSame(0, TicketNote::query()->count());
    }

    public function test_desfazer_so_recria_no_ticket_do_proprio_componente(): void
    {
        [$autor, $ticket, $note] = $this->noteOf();
        $outroTicket = $this->ticket($autor);
        $screen = Livewire::actingAs($autor)->test(Transcript::class, ['ticketId' => $ticket->id])->call('deleteNote', $note->id);

        $token = $this->undoToken($screen);
        Livewire::actingAs($autor)->test(Transcript::class, ['ticketId' => $outroTicket->id])->call('restoreNote', $token);

        $this->assertSame(0, TicketNote::query()->count());

        // O painel de outro ticket não gasta o token: voltar ao ticket certo ainda desfaz.
        $screen->call('restoreNote', $token);
        $this->assertSame(1, TicketNote::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_o_botao_apagar_da_nota_nao_pede_confirmacao(): void
    {
        [$autor, $ticket] = $this->noteOf();

        $html = Livewire::actingAs($autor)->test(Transcript::class, ['ticketId' => $ticket->id])->html();

        $this->assertStringContainsString('deleteNote(', $html);
        $this->assertStringNotContainsString('data-confirm="Apagar esta nota', $html);
    }

    public function test_desfazer_com_o_ticket_apagado_na_janela_nao_quebra_nem_recria(): void
    {
        [$autor, $ticket, $note] = $this->noteOf();
        $screen = Livewire::actingAs($autor)->test(Transcript::class, ['ticketId' => $ticket->id])->call('deleteNote', $note->id);
        $token = $this->undoToken($screen);

        $ticket->delete();
        $screen->call('restoreNote', $token)->assertOk();

        $this->assertSame(0, TicketNote::query()->count());
    }

    public function test_desfazer_de_usuario_inativado_na_janela_nao_recria(): void
    {
        [$autor, $ticket, $note] = $this->noteOf();
        $screen = Livewire::actingAs($autor)->test(Transcript::class, ['ticketId' => $ticket->id])->call('deleteNote', $note->id);
        $token = $this->undoToken($screen);

        $autor->forceFill(['active' => false])->save();
        $screen->call('restoreNote', $token);

        $this->assertSame(0, TicketNote::query()->where('ticket_id', $ticket->id)->count());
    }
}
