<?php

namespace Tests\Feature\Recovery;

use App\Enums\ClosedReason;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Events\MessageCreated;
use App\Events\TicketQueued;
use App\Events\TicketUpdated;
use App\Jobs\ResolveMessageMediaJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Inbox\ImportHistory;
use App\Services\Inbox\IngestMode;
use App\Services\Inbox\RecoverMessages;
use App\Services\Inbox\RecoveryResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const A = '5516997524950@c.us';

    private const B = '5516981110000@c.us';

    private const C = '5516982220000@c.us';

    private const D = '5516983330000@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        User::factory()->create();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main', 'backfill_days' => 5]);
    }

    /** @param  array<string, mixed>  $extra */
    private function in(string $peer, string $id, \DateTimeInterface $at, array $extra = []): array
    {
        return array_merge(['id' => 'false_'.$peer.'_'.$id, 'from' => $peer, 'fromMe' => false, 'body' => 'cliente '.$id, 'timestamp' => $at->getTimestamp(), 'hasMedia' => false, '_data' => ['Info' => ['PushName' => 'Maria '.$id]]], $extra);
    }

    /** @param  array<string, mixed>  $extra */
    private function out(string $peer, string $id, \DateTimeInterface $at, array $extra = []): array
    {
        return array_merge(['id' => 'true_'.$peer.'_'.$id, 'from' => '5516999990001@c.us', 'to' => $peer, 'fromMe' => true, 'body' => 'empresa '.$id, 'timestamp' => $at->getTimestamp(), 'hasMedia' => false], $extra);
    }

    /** @param  list<array<string, mixed>>  $messages */
    private function import(array $messages): RecoveryResult
    {
        Http::fake(['*/api/main/chats/all/messages*' => Http::response($messages, 200)]);

        return app(ImportHistory::class)($this->channel);
    }

    public function test_chat_respondido_vira_ticket_encerrado_de_importacao(): void
    {
        $first = now()->subDays(2)->subHour();
        $last = now()->subDays(2);

        $result = $this->import([$this->in(self::A, 'A1', $first), $this->out(self::A, 'A2', $last)]);

        $ticket = Ticket::query()->sole();
        $this->assertSame(TicketStatus::ENCERRADO, $ticket->status);
        $this->assertSame(ClosedReason::IMPORTADO, $ticket->closed_reason);
        $this->assertSame($first->getTimestamp(), $ticket->opened_at->getTimestamp());
        $this->assertSame($last->getTimestamp(), $ticket->closed_at?->getTimestamp());
        $this->assertNull($ticket->waiting_since);
        $this->assertNull($ticket->first_human_reply_at);
        $this->assertSame(2, Message::query()->where('ticket_id', $ticket->id)->count());
        $contact = Contact::query()->sole();
        $this->assertSame(self::A, $contact->wa_id);
        $this->assertSame('Maria A1', $contact->name);
        $this->assertSame(2, $result->messages);
        $this->assertSame(1, $result->conversations);
    }

    public function test_cliente_sem_resposta_ate_48h_abre_ticket_aguardando(): void
    {
        $at = now()->subHours(3);

        $service = app(ImportHistory::class);
        Http::fake(['*/api/main/chats/all/messages*' => Http::response([$this->in(self::B, 'B1', $at)], 200)]);
        $service($this->channel);

        $ticket = Ticket::query()->sole();
        $this->assertSame(TicketStatus::AGUARDANDO, $ticket->status);
        $this->assertNull($ticket->closed_reason);
        $this->assertSame($at->getTimestamp(), $ticket->waiting_since?->getTimestamp());
        $this->assertSame(0, Message::query()->where('origin', MessageOrigin::BOT->value)->count());
        $this->assertSame(1, $service->open);
    }

    public function test_cliente_sem_resposta_ha_mais_de_48h_entra_encerrado(): void
    {
        $this->import([$this->in(self::C, 'C1', now()->subDays(4))]);

        $ticket = Ticket::query()->sole();
        $this->assertSame(TicketStatus::ENCERRADO, $ticket->status);
        $this->assertSame(ClosedReason::IMPORTADO, $ticket->closed_reason);
    }

    public function test_importada_nao_dispara_som_nem_tempo_real_nem_eventos_de_ticket(): void
    {
        Event::fake([TicketQueued::class, MessageCreated::class, TicketUpdated::class]);

        $this->import([$this->in(self::A, 'A1', now()->subDays(3)), $this->out(self::A, 'A2', now()->subDays(3)->addMinute()), $this->in(self::C, 'C1', now()->subDays(4))]);

        Event::assertNotDispatched(TicketQueued::class);
        Event::assertNotDispatched(MessageCreated::class);
        Event::assertNotDispatched(TicketUpdated::class);
        $this->assertSame(0, TicketEvent::query()->count());
    }

    public function test_contato_com_ticket_aberto_recebe_as_novas_nele(): void
    {
        $contact = Contact::factory()->create(['wa_id' => self::D]);
        $open = Ticket::factory()->create(['contact_id' => $contact->id, 'channel_id' => $this->channel->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'opened_at' => now()->subDay()]);

        $this->import([
            $this->in(self::D, 'D1', now()->subDays(3)),
            $this->out(self::D, 'D2', now()->subDays(3)->addMinute()),
            $this->in(self::D, 'D3', now()->subHours(12)),
            $this->out(self::D, 'D4', now()->subHours(11)),
        ]);

        $imported = Ticket::query()->where('closed_reason', ClosedReason::IMPORTADO->value)->sole();
        $this->assertSame(['D1', 'D2'], Message::query()->where('ticket_id', $imported->id)->orderBy('id')->pluck('wa_message_id')->all());
        $this->assertSame(['D3', 'D4'], Message::query()->where('ticket_id', $open->id)->orderBy('id')->pluck('wa_message_id')->all());
    }

    public function test_importar_de_novo_nao_cria_nada(): void
    {
        $messages = [$this->in(self::A, 'A1', now()->subDays(2)), $this->out(self::A, 'A2', now()->subDays(2)->addMinute()), $this->in(self::B, 'B1', now()->subHours(3))];
        $this->import($messages);
        $counts = [Ticket::query()->count(), Message::query()->count(), Contact::query()->count()];

        $second = $this->import($messages);

        $this->assertSame(0, $second->messages);
        $this->assertSame($counts, [Ticket::query()->count(), Message::query()->count(), Contact::query()->count()]);
    }

    public function test_midia_importada_vai_para_a_fila_media(): void
    {
        $media = ['hasMedia' => true, 'body' => '', 'media' => ['filename' => 'a.jpg', 'mimetype' => 'image/jpeg', 'size' => 10]];

        $this->import([$this->in(self::A, 'A1', now()->subDays(2), $media), $this->out(self::A, 'A2', now()->subDays(2)->addMinute())]);

        Queue::assertPushed(ResolveMessageMediaJob::class, fn (ResolveMessageMediaJob $job): bool => $job->queue === 'media');
    }

    public function test_para_no_prazo_e_marca_incompleta(): void
    {
        Http::fake(['*/api/main/chats/all/messages*' => Http::response([$this->in(self::A, 'A1', now()->subDays(2)), $this->out(self::A, 'A2', now()->subDays(2)->addMinute())], 200)]);
        $service = app(ImportHistory::class);

        $result = $service($this->channel, now()->subSecond()->toImmutable());

        $this->assertFalse($service->complete);
        $this->assertSame(0, $result->messages);
        $this->assertSame(0, Message::query()->count());
    }

    public function test_continuar_depois_conclui_sem_duplicar(): void
    {
        // Cada mensagem gravada "gasta" 10 s: o prazo de 5 s vence depois do primeiro chat.
        $this->app->bind(RecoverMessages::class, fn () => new class extends RecoverMessages
        {
            public function ingest(Channel $channel, array $data, IngestMode $mode): ?Message
            {
                $message = parent::ingest($channel, $data, $mode);
                Carbon::setTestNow(Carbon::now()->addSeconds(10));

                return $message;
            }
        });
        $messages = [
            $this->in(self::A, 'A1', now()->subDays(3)), $this->out(self::A, 'A2', now()->subDays(3)->addMinute()),
            $this->in(self::C, 'C1', now()->subDays(4)), $this->out(self::C, 'C2', now()->subDays(4)->addMinute()),
        ];
        Http::fake(['*/api/main/chats/all/messages*' => Http::response($messages, 200)]);
        $service = app(ImportHistory::class);

        $first = $service($this->channel, now()->addSeconds(5)->toImmutable());
        $this->assertFalse($service->complete);
        $this->assertGreaterThan(0, $first->messages);
        $this->assertLessThan(4, Message::query()->count());

        $second = $service($this->channel);

        $this->assertTrue($service->complete);
        $this->assertSame(4, Message::query()->count());
        $this->assertSame(4, $first->messages + $second->messages);
        $this->assertSame(0, $service($this->channel)->messages);
    }

    public function test_passa_do_teto_de_mensagens_para_de_ler_e_avisa_no_log(): void
    {
        Log::spy();
        $service = new class(app(RecoverMessages::class)) extends ImportHistory
        {
            public const MAX_MESSAGES = 3;
        };
        Http::fake(['*/api/main/chats/all/messages*' => Http::response([
            $this->in(self::A, 'A1', now()->subDays(2)), $this->out(self::A, 'A2', now()->subDays(2)->addMinute()),
            $this->in(self::C, 'C1', now()->subDays(3)), $this->out(self::C, 'C2', now()->subDays(3)->addMinute()),
            $this->in(self::B, 'B1', now()->subDays(4)),
        ], 200)]);

        $result = $service($this->channel);

        $this->assertTrue($service->truncated);
        $this->assertLessThan(5, $result->messages);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'teto'))->once();
    }

    public function test_o_teto_mantem_as_mensagens_mais_recentes(): void
    {
        $service = new class(app(RecoverMessages::class)) extends ImportHistory
        {
            public const MAX_MESSAGES = 2;
        };
        $all = [
            $this->in(self::B, 'B1', now()->subDays(4)),
            $this->in(self::C, 'C1', now()->subDays(2)),
            $this->in(self::A, 'A1', now()->subDay()),
        ];
        // Cada fatia de 24 h só devolve o que cai dentro dela, como o WAHA de verdade.
        Http::fake(['*/api/main/chats/all/messages*' => fn ($request) => Http::response(array_values(array_filter(
            $all,
            fn (array $m): bool => $m['timestamp'] >= (int) $request['filter.timestamp.gte'] && $m['timestamp'] <= (int) $request['filter.timestamp.lte'],
        )), 200)]);

        $service($this->channel);

        $ids = Message::query()->pluck('wa_message_id')->sort()->values()->all();
        $this->assertSame(['A1', 'C1'], $ids);
    }

    public function test_abaixo_do_teto_nao_marca_como_limitada(): void
    {
        $service = app(ImportHistory::class);
        Http::fake(['*/api/main/chats/all/messages*' => Http::response([$this->in(self::A, 'A1', now()->subDays(2)), $this->out(self::A, 'A2', now()->subDays(2)->addMinute())], 200)]);

        $service($this->channel);

        $this->assertFalse($service->truncated);
    }

    public function test_a_janela_e_limitada_a_30_dias(): void
    {
        $this->channel->update(['backfill_days' => 100]);

        $this->import([]);

        Http::assertSent(fn ($request) => (int) $request['filter.timestamp.gte'] === now()->subDays(30)->getTimestamp());
    }
}
