<?php

namespace Tests\Feature\Recovery;

use App\Enums\ClosedReason;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\ImportHistory;
use App\Services\Inbox\IngestMode;
use App\Services\Inbox\RecoverMessages;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImportedTicketTest extends TestCase
{
    use RefreshDatabase;

    private const A = '5516997524950@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        User::factory()->create();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main', 'backfill_days' => 10]);
    }

    /** @return array<string, mixed> */
    private function in(string $id, \DateTimeInterface $at): array
    {
        return ['id' => 'false_'.self::A.'_'.$id, 'from' => self::A, 'fromMe' => false, 'body' => 'm '.$id, 'timestamp' => $at->getTimestamp(), 'hasMedia' => false];
    }

    /** @param  list<array<string, mixed>>  $messages */
    private function import(array $messages): void
    {
        Http::fake(['*/api/main/chats/all/messages*' => Http::response($messages, 200)]);
        app(ImportHistory::class)($this->channel);
    }

    /** Fim de papo há mais de 48 h, para a importação não abrir ticket de espera. */
    private function base(): CarbonImmutable
    {
        return now()->toImmutable()->subDays(6);
    }

    public function test_pausa_maior_que_6h_abre_outro_ticket(): void
    {
        $t = $this->base();

        $this->import([$this->in('1', $t), $this->in('2', $t->addHour()), $this->in('3', $t->addHours(8))]);

        $tickets = Ticket::query()->orderBy('opened_at')->get();
        $this->assertCount(2, $tickets);
        $this->assertSame(ClosedReason::IMPORTADO, $tickets[0]->closed_reason);
        $this->assertSame($t->addHour()->getTimestamp(), $tickets[0]->closed_at?->getTimestamp());
        $this->assertSame($t->addHours(8)->getTimestamp(), $tickets[1]->opened_at->getTimestamp());
        $this->assertSame(2, Message::query()->where('ticket_id', $tickets[0]->id)->count());
    }

    public function test_pausa_de_exatamente_6h_continua_no_mesmo_ticket(): void
    {
        $t = $this->base();

        $this->import([$this->in('1', $t), $this->in('2', $t->addHours(6))]);

        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_mensagem_fora_de_ordem_funde_dois_tickets(): void
    {
        $t = $this->base();
        $this->import([$this->in('1', $t), $this->in('3', $t->addHours(8))]);
        $this->assertSame(2, Ticket::query()->count());

        app(RecoverMessages::class)->ingest($this->channel, $this->in('2', $t->addHours(4)), IngestMode::IMPORTED);

        $ticket = Ticket::query()->sole();
        $this->assertSame($t->getTimestamp(), $ticket->opened_at->getTimestamp());
        $this->assertSame($t->addHours(8)->getTimestamp(), $ticket->closed_at?->getTimestamp());
        $this->assertSame(3, Message::query()->where('ticket_id', $ticket->id)->count());
    }

    public function test_reimportar_nao_cria_tickets(): void
    {
        $t = $this->base();
        $messages = [$this->in('1', $t), $this->in('3', $t->addHours(8))];
        $this->import($messages);

        $this->import($messages);

        $this->assertSame(2, Ticket::query()->count());
        $this->assertSame(2, Message::query()->count());
    }
}
