<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\UserRole;
use App\Jobs\HandleWahaEvent;
use App\Livewire\Inbox\Transcript;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageEdit;
use App\Models\MessageReaction;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\ApplyMessageChange;
use App\Services\Inbox\IngestMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Reação, edição e apagamento de uma mensagem que já existe: formatos conferidos nos DTOs do WAHA. */
class MessageChangesTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Channel $channel;

    private Ticket $ticket;

    private Message $target;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->ticket = Ticket::factory()->create(['channel_id' => $this->channel->id]);
        $this->target = Message::factory()->for($this->ticket)->create(['wa_message_id' => 'TARGET1', 'body' => 'Texto original']);
    }

    /** @param  array<string, mixed>  $payload */
    private function event(string $name, array $payload): WahaEvent
    {
        return WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => $this->channel->id,
            'event' => $name,
            'session' => 'main',
            'payload' => ['event' => $name, 'session' => 'main', 'payload' => $payload],
            'received_at' => now(),
        ]);
    }

    private function reaction(string $text, bool $fromMe = false, string $messageId = 'true_'.self::PEER.'_TARGET1'): WahaEvent
    {
        return $this->event('message.reaction', [
            'id' => 'false_'.self::PEER.'_RX'.uniqid(), 'from' => self::PEER, 'fromMe' => $fromMe, 'timestamp' => now()->timestamp,
            'reaction' => ['text' => $text, 'messageId' => $messageId],
        ]);
    }

    public function test_reacao_cria_e_troca_a_do_mesmo_autor(): void
    {
        $apply = app(ApplyMessageChange::class);

        $this->assertTrue($apply->reaction($this->reaction('👍')));
        $this->assertTrue($apply->reaction($this->reaction('❤️')));

        $reaction = MessageReaction::query()->sole();
        $this->assertSame('❤️', $reaction->emoji);
        $this->assertSame('contact', $reaction->by);

        $apply->reaction($this->reaction('👍', true));
        $this->assertSame(['contact', 'me'], MessageReaction::query()->orderBy('by')->pluck('by')->all());
    }

    public function test_texto_vazio_remove_a_reacao(): void
    {
        $apply = app(ApplyMessageChange::class);
        $apply->reaction($this->reaction('👍'));

        $this->assertTrue($apply->reaction($this->reaction('')));

        $this->assertSame(0, MessageReaction::query()->count());
    }

    public function test_reacao_nao_mexe_na_conversa(): void
    {
        $before = [$this->ticket->fresh()?->getAttributes(), Message::query()->count(), TicketEvent::query()->count()];
        $this->travel(1)->hour();

        app(ApplyMessageChange::class)->reaction($this->reaction('👍'));

        $this->assertSame($before, [$this->ticket->fresh()?->getAttributes(), Message::query()->count(), TicketEvent::query()->count()]);
    }

    public function test_edicao_atualiza_corpo_e_edited_at(): void
    {
        $event = $this->event('message.edited', ['id' => 'false_'.self::PEER.'_ED1', 'from' => self::PEER, 'fromMe' => false, 'body' => 'Texto corrigido', 'editedMessageId' => 'TARGET1', 'timestamp' => now()->timestamp]);

        $this->assertTrue(app(ApplyMessageChange::class)->edited($event));

        $this->target->refresh();
        $this->assertSame('Texto corrigido', $this->target->body);
        $this->assertNotNull($this->target->edited_at);
    }

    private function edit(string $body, string $suffix = 'ED'): WahaEvent
    {
        return $this->event('message.edited', ['id' => 'false_'.self::PEER.'_'.$suffix.uniqid(), 'from' => self::PEER, 'fromMe' => false, 'body' => $body, 'editedMessageId' => 'TARGET1', 'timestamp' => now()->timestamp]);
    }

    public function test_edicao_guarda_a_versao_anterior(): void
    {
        app(ApplyMessageChange::class)->edited($this->edit('Texto corrigido'));

        $edit = MessageEdit::query()->sole();
        $this->assertSame('Texto original', $edit->previous_body);
        $this->assertSame($this->target->id, $edit->message_id);
    }

    public function test_duas_edicoes_guardam_as_duas_anteriores_em_ordem(): void
    {
        $apply = app(ApplyMessageChange::class);

        $apply->edited($this->edit('Segunda versao'));
        $apply->edited($this->edit('Terceira versao'));

        $this->assertSame(['Texto original', 'Segunda versao'], $this->target->fresh()->edits->pluck('previous_body')->all());
        $this->assertSame('Terceira versao', $this->target->fresh()->body);
    }

    public function test_o_mesmo_evento_repetido_nao_duplica_a_versao(): void
    {
        $event = $this->edit('Texto corrigido');
        $apply = app(ApplyMessageChange::class);

        $apply->edited($event);
        $apply->edited($event);

        $this->assertSame(1, MessageEdit::query()->count());
    }

    public function test_evento_de_edicao_antigo_repetido_nao_reverte_o_texto(): void
    {
        $apply = app(ApplyMessageChange::class);
        $segunda = $this->edit('Segunda versao');
        $terceira = $this->edit('Terceira versao');
        $apply->edited($segunda);
        $apply->edited($terceira);

        $apply->edited($segunda);

        $this->assertSame('Terceira versao', $this->target->fresh()->body);
        $this->assertSame(['Texto original', 'Segunda versao'], $this->target->fresh()->edits->pluck('previous_body')->all());
    }

    public function test_tela_mostra_o_texto_novo_com_as_versoes_anteriores(): void
    {
        app(ApplyMessageChange::class)->edited($this->edit('Texto corrigido'));

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(Transcript::class, ['ticketId' => $this->ticket->id])
            ->assertSee('Texto corrigido')
            ->assertSee('editada')
            ->assertSee('Antes:')
            ->assertSee('Texto original');
    }

    public function test_apagada_grava_revoked_at_e_mantem_o_corpo(): void
    {
        $event = $this->event('message.revoked', ['revokedMessageId' => 'TARGET1', 'after' => ['id' => 'false_'.self::PEER.'_RV1']]);

        $this->assertTrue(app(ApplyMessageChange::class)->revoked($event));

        $this->target->refresh();
        $this->assertNotNull($this->target->revoked_at);
        $this->assertSame('Texto original', $this->target->body);
    }

    public function test_destino_ausente_pede_nova_tentativa(): void
    {
        $apply = app(ApplyMessageChange::class);

        $this->assertFalse($apply->reaction($this->reaction('👍', false, 'false_'.self::PEER.'_NOPE')));
        $this->assertFalse($apply->edited($this->event('message.edited', ['editedMessageId' => 'NOPE', 'body' => 'x'])));
        $this->assertFalse($apply->revoked($this->event('message.revoked', ['revokedMessageId' => 'NOPE'])));
        $this->assertSame(0, MessageReaction::query()->count());
    }

    public function test_job_reenfileira_a_reacao_sem_destino_e_descarta_na_sexta_tentativa(): void
    {
        $event = $this->reaction('👍', false, 'false_'.self::PEER.'_NOPE');
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

    public function test_evento_de_grupo_nao_pede_nova_tentativa(): void
    {
        $group = '120363000000@g.us';
        $events = [
            $this->event('message.reaction', ['from' => $group, 'fromMe' => false, 'reaction' => ['text' => '👍', 'messageId' => 'false_'.$group.'_NOPE']]),
            $this->event('message.edited', ['from' => $group, 'editedMessageId' => 'NOPE', 'body' => 'x']),
            $this->event('message.revoked', ['after' => ['from' => $group], 'revokedMessageId' => 'NOPE']),
        ];

        foreach ($events as $event) {
            $job = new class($event->id) extends HandleWahaEvent
            {
                public int $released = -1;

                public function attempts(): int
                {
                    return 1;
                }

                public function release($delay = 0): void
                {
                    $this->released = (int) $delay;
                }
            };

            $job->handle();

            $this->assertSame(-1, $job->released, $event->event.' de grupo não deve voltar para a fila');
        }
    }

    public function test_edicao_e_apagamento_em_message_any_nao_viram_mensagem(): void
    {
        $before = Message::query()->count();
        $edited = $this->event('message.any', [
            'id' => 'false_'.self::PEER.'_EDANY', 'from' => self::PEER, 'fromMe' => false, 'body' => 'Texto novo', 'editedMessageId' => 'TARGET1',
            'timestamp' => now()->timestamp, 'hasMedia' => false,
        ]);
        $revoked = $this->event('message.any', [
            'id' => 'false_'.self::PEER.'_RVANY', 'from' => self::PEER, 'fromMe' => false, 'body' => '', 'revokedMessageId' => 'TARGET1',
            'timestamp' => now()->timestamp, 'hasMedia' => false,
        ]);

        $this->assertNull(app(IngestMessage::class)($edited));
        $this->assertNull(app(IngestMessage::class)($revoked));
        $this->assertSame($before, Message::query()->count());
    }

    public function test_job_roteia_os_tres_eventos(): void
    {
        (new HandleWahaEvent($this->reaction('👍')->id))->handle();
        (new HandleWahaEvent($this->event('message.edited', ['editedMessageId' => 'TARGET1', 'body' => 'Novo'])->id))->handle();
        (new HandleWahaEvent($this->event('message.revoked', ['revokedMessageId' => 'TARGET1'])->id))->handle();

        $this->target->refresh();
        $this->assertSame(1, MessageReaction::query()->count());
        $this->assertSame('Novo', $this->target->body);
        $this->assertNotNull($this->target->revoked_at);
    }

    public function test_reacao_em_message_any_nao_vira_mensagem(): void
    {
        $before = Message::query()->count();
        $event = $this->event('message.any', [
            'id' => 'false_'.self::PEER.'_RXANY', 'from' => self::PEER, 'fromMe' => false, 'type' => 'reaction', 'body' => '👍',
            'timestamp' => now()->timestamp, 'hasMedia' => false,
            'reaction' => ['text' => '👍', 'messageId' => 'true_'.self::PEER.'_TARGET1'],
        ]);

        $this->assertNull(app(IngestMessage::class)($event));
        $this->assertSame($before, Message::query()->count());
    }

    public function test_mensagem_enviada_apagada_mostra_que_foi_no_celular(): void
    {
        $this->target->update(['revoked_at' => now(), 'direction' => MessageDirection::OUT]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(Transcript::class, ['ticketId' => $this->ticket->id])
            ->assertSee('Mensagem apagada no celular')
            ->assertDontSee('apagada pelo cliente')
            ->assertSee('Texto original');
    }

    public function test_tela_mostra_apagada_com_o_texto_editada_e_a_reacao(): void
    {
        $this->target->update(['revoked_at' => now(), 'edited_at' => now()]);
        MessageReaction::create(['message_id' => $this->target->id, 'by' => 'contact', 'emoji' => '👍', 'reacted_at' => now()]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(Transcript::class, ['ticketId' => $this->ticket->id])
            ->assertSee('apagada pelo cliente')
            ->assertSee('Texto original')
            ->assertSee('editada')
            ->assertSee('👍');
    }
}
