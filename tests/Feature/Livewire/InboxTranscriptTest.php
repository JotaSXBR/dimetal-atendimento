<?php

namespace Tests\Feature\Livewire;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Inbox\Transcript;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\SendFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Attributes\On;
use Livewire\Livewire;
use ReflectionAttribute;
use ReflectionMethod;
use Tests\TestCase;

class InboxTranscriptTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(?Sector $sector = null): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => Channel::query()->first()?->id ?? Channel::factory()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::AGUARDANDO,
            'sector_id' => $sector?->id,
        ]);
    }

    private function say(Ticket $ticket, string $body): Message
    {
        return Message::factory()->for($ticket)->create(['body' => $body]);
    }

    /** @return array{0: User, 1: Ticket} um admin e um ticket em atendimento com ele */
    private function mine(): array
    {
        $me = User::factory()->create(['role' => UserRole::ADMIN, 'sign_messages' => false]);
        $ticket = $this->ticket();
        $ticket->update(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $me->id]);

        return [$me, $ticket];
    }

    public function test_mostra_as_200_ultimas_mensagens_em_ordem(): void
    {
        $ticket = $this->ticket();

        foreach (range(1, 205) as $n) {
            $this->say($ticket, "msg-{$n};");
        }

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(Transcript::class, ['ticketId' => $ticket->id])
            ->assertSeeInOrder(['msg-6;', 'msg-100;', 'msg-205;'])
            ->assertDontSee('msg-5;');
    }

    public function test_usuario_sem_acesso_ao_ticket_nao_ve_mensagens(): void
    {
        $ticket = $this->ticket(Sector::factory()->create());
        $this->say($ticket, 'segredo;');

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))
            ->test(Transcript::class, ['ticketId' => $ticket->id])
            ->assertDontSee('segredo;');
    }

    public function test_evento_de_outro_ticket_nao_atualiza(): void
    {
        $ticket = $this->ticket();
        $other = $this->ticket();
        $screen = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(Transcript::class, ['ticketId' => $ticket->id]);

        $this->say($ticket, 'nova;');

        $screen->dispatch('echo-private:inbox,.message.created', ['ticket_id' => $other->id])->assertDontSee('nova;');
        $screen->dispatch('echo-private:inbox,.message.created', ['ticket_id' => $ticket->id])->assertSee('nova;');
    }

    public function test_ticket_updated_do_ticket_aberto_atualiza(): void
    {
        // Eco do celular casando com a linha pendente e fusão de contatos mexem em mensagens e disparam só `ticket.updated`.
        $ticket = $this->ticket();
        $other = $this->ticket();
        $screen = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(Transcript::class, ['ticketId' => $ticket->id]);

        $this->say($ticket, 'nova;');

        $screen->dispatch('echo-private:inbox,.ticket.updated', ['ticket_id' => $other->id])->assertDontSee('nova;');
        $screen->dispatch('echo-private:inbox,.ticket.updated', ['ticket_id' => $ticket->id])->assertSee('nova;');
    }

    public function test_bloco_citado_aparece_escapado_e_com_ancora_da_original(): void
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $ticket = $this->ticket();
        $original = Message::factory()->for($ticket)->create(['wa_message_id' => 'ORIG1', 'body' => 'preço?']);
        Message::factory()->for($ticket)->create([
            'direction' => MessageDirection::OUT,
            'origin' => MessageOrigin::PAINEL,
            'author_user_id' => $user->id,
            'body' => 'Segue',
            'quoted_wa_message_id' => 'ORIG1',
            'quoted_snapshot' => ['author' => 'Maria', 'type' => 'text', 'excerpt' => '<script>x</script> *a'],
        ]);
        Message::factory()->for($ticket)->create(['body' => 'outra', 'quoted_wa_message_id' => 'FORA', 'quoted_snapshot' => ['author' => 'Maria', 'type' => 'text', 'excerpt' => 'antiga']]);

        $html = Livewire::actingAs($user)->test(Transcript::class, ['ticketId' => $ticket->id])->html();

        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt; *a', $html);
        $this->assertStringContainsString('data-quote-target="'.$original->id.'"', $html);
        $this->assertStringContainsString('data-quote-target=""', $html, 'original fora da página: o bloco aparece mas não rola');
        $this->assertStringContainsString('id="message-'.$original->id.'"', $html);
    }

    /** @param array<string, mixed> $snapshot */
    private function quotedReply(Ticket $ticket, array $snapshot): string
    {
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        Message::factory()->for($ticket)->create(['wa_message_id' => 'ORIGM', 'body' => 'resposta', 'quoted_wa_message_id' => 'ORIGM', 'quoted_snapshot' => $snapshot]);

        return Livewire::actingAs($user)->test(Transcript::class, ['ticketId' => $ticket->id])->html();
    }

    public function test_bloco_citado_de_foto_mostra_a_miniatura_e_o_rotulo(): void
    {
        $ticket = $this->ticket();
        $photo = Message::factory()->for($ticket)->create(['type' => 'media', 'body' => '', 'media_mime' => 'image/jpeg', 'media_name' => 'foto.jpg', 'media_ref' => 'files/foto.jpg']);

        $html = $this->quotedReply($ticket, ['author' => 'Maria', 'type' => 'media', 'excerpt' => 'Foto', 'media' => ['message_id' => $photo->id, 'kind' => 'image', 'name' => 'foto.jpg']]);

        $this->assertSame(1, preg_match('/<img[^>]*size-14[^>]*>/', $html, $thumb));
        $this->assertStringContainsString(route('midia', $photo), $thumb[0]);
        $this->assertStringContainsString('x-on:error', $thumb[0], 'a miniatura some sozinha se a mídia expirou');
        $this->assertStringNotContainsString('onerror', $thumb[0], 'sem atributo inline: a CSP vai ficar em modo de bloqueio');
        $this->assertStringContainsString('Foto', $html);
    }

    public function test_bloco_citado_de_documento_mostra_o_nome_e_nenhuma_imagem(): void
    {
        $ticket = $this->ticket();

        $html = $this->quotedReply($ticket, ['author' => 'Maria', 'type' => 'media', 'excerpt' => 'COTAÇÃO DIMETAL.docx', 'media' => ['message_id' => 1, 'kind' => 'document', 'name' => 'COTAÇÃO DIMETAL.docx']]);

        $this->assertStringContainsString('COTAÇÃO DIMETAL.docx', $html);
        $this->assertStringNotContainsString('size-14', $html);
    }

    public function test_bloco_citado_antigo_sem_media_continua_so_com_texto(): void
    {
        $html = $this->quotedReply($this->ticket(), ['author' => 'Maria', 'type' => 'media', 'excerpt' => 'Foto']);

        $this->assertStringContainsString('Foto', $html);
        $this->assertStringNotContainsString('size-14', $html);
    }

    public function test_nome_do_arquivo_citado_sai_escapado(): void
    {
        $html = $this->quotedReply($this->ticket(), ['author' => 'Maria', 'type' => 'media', 'excerpt' => '<b>x</b>.docx', 'media' => ['message_id' => 1, 'kind' => 'document', 'name' => '<b>x</b>.docx']]);

        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;.docx', $html);
    }

    public function test_transcript_refresh_atualiza(): void
    {
        $ticket = $this->ticket();
        $screen = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(Transcript::class, ['ticketId' => $ticket->id]);

        $this->say($ticket, 'nova;');

        $screen->dispatch('transcript-refresh')->assertSee('nova;');
    }

    public function test_digitar_barra_nao_consulta_o_transcript(): void
    {
        [$me, $ticket] = $this->mine();
        $screen = Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $screen->set('draft', '/');

        $transcriptQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'limit 200'));
        $this->assertSame([], $transcriptQueries);
    }

    public function test_inbox_nao_escuta_message_status(): void
    {
        $events = array_map(
            fn (ReflectionAttribute $a): string => $a->getArguments()[0],
            (new ReflectionMethod(Inbox::class, 'refresh'))->getAttributes(On::class),
        );

        $this->assertNotContains('echo-private:inbox,.message.status', $events);
        $this->assertContains('echo-private:inbox,.ticket.updated', $events);
    }

    public function test_envio_pelo_composer_avisa_o_transcript(): void
    {
        Queue::fake();
        [$me, $ticket] = $this->mine();

        Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id)
            ->set('draft', 'oi do atendente')->call('send')
            ->assertDispatched('transcript-refresh');
    }

    public function test_reenviar_avisa_o_transcript(): void
    {
        Queue::fake();
        [$me, $ticket] = $this->mine();
        $failed = Message::factory()->for($ticket)->create([
            'direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL, 'author_user_id' => $me->id,
            'delivery_status' => DeliveryStatus::FAILED, 'failure_code' => SendFailure::EXPIRED, 'wa_message_id' => null,
        ]);

        Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id)
            ->call('resend', $failed->id, true)
            ->assertDispatched('transcript-refresh');
    }

    public function test_menu_da_bolha_e_um_chevron_sem_linha_propria(): void
    {
        [$me, $ticket] = $this->mine();
        $this->say($ticket, 'Bom dia')->update(['wa_message_id' => 'W1']);

        $html = Livewire::actingAs($me)->test(Transcript::class, ['ticketId' => $ticket->id])->html();

        $this->assertStringContainsString('data-testid="message-menu-button"', $html);
        $this->assertStringNotContainsString('mt-1 flex justify-end', $html, 'o menu não ocupa mais uma linha entre o texto e o horário');
    }

    public function test_mensagem_apagada_nao_tem_menu(): void
    {
        [$me, $ticket] = $this->mine();
        $this->say($ticket, 'Oi')->update(['wa_message_id' => 'W1', 'revoked_at' => now()]);

        $html = Livewire::actingAs($me)->test(Transcript::class, ['ticketId' => $ticket->id])->html();

        $this->assertStringNotContainsString('data-testid="message-menu-button"', $html);
    }

    public function test_modo_leitura_nao_tem_menu(): void
    {
        [$me, $ticket] = $this->mine();
        $this->say($ticket, 'Oi')->update(['wa_message_id' => 'W1']);
        $this->actingAs($me);

        $html = view('livewire.inbox.transcript', ['messages' => $ticket->messages()->get(), 'readonly' => true])->render();

        $this->assertStringNotContainsString('data-testid="message-menu-button"', $html);
    }
}
