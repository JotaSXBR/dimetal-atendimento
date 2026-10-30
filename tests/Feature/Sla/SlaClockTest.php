<?php

namespace Tests\Feature\Sla;

use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\BusinessHour;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Services\Inbox\SlaClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SlaClockTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            BusinessHour::create(['weekday' => $weekday, 'opens_at' => '08:00', 'closes_at' => '12:00']);
            BusinessHour::create(['weekday' => $weekday, 'opens_at' => '13:00', 'closes_at' => '18:00']);
        }

        BusinessHour::create(['weekday' => 6, 'opens_at' => '08:00', 'closes_at' => '12:00']);
    }

    /** Um horário de São Paulo (sexta 30/10/2026, terça 27/10/2026) como o instante em UTC: o Eloquent grava no fuso do objeto. */
    private function sp(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, 'America/Sao_Paulo')->utc();
    }

    private function at(string $localTime): void
    {
        $this->travelTo($this->sp($localTime));
    }

    private function ticket(array $override = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::AGUARDANDO,
        ], $override));
    }

    private function tick(): array
    {
        return app(SlaClock::class)();
    }

    private function localTime(?CarbonImmutable $value): ?string
    {
        return $value?->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s');
    }

    private function breaches(Ticket $ticket): int
    {
        return $ticket->events()->where('type', 'sla_breached')->count();
    }

    public function test_adota_o_ticket_que_ja_esperava_antes_da_implantacao(): void
    {
        $this->at('2026-10-30 10:00');
        $waiting = $this->ticket(['last_inbound_at' => $this->sp('2026-10-30 09:50'), 'last_outbound_at' => null]);
        $answeredBefore = $this->ticket(['last_inbound_at' => $this->sp('2026-10-30 09:40'), 'last_outbound_at' => $this->sp('2026-10-30 09:45')]);
        $neverWrote = $this->ticket(['last_inbound_at' => null]);

        $result = $this->tick();

        $this->assertSame(1, $result['adopted']);
        $this->assertSame('2026-10-30 09:50:00', $this->localTime($waiting->fresh()?->waiting_since));
        $this->assertNull($answeredBefore->fresh()?->waiting_since, 'respondido: ninguém espera');
        $this->assertNull($neverWrote->fresh()?->waiting_since);
    }

    public function test_calcula_aviso_em_75_por_cento_e_vencimento_em_100(): void
    {
        $this->at('2026-10-30 10:00');
        $ticket = $this->ticket(['status' => TicketStatus::EM_ATENDIMENTO, 'waiting_since' => $this->sp('2026-10-30 10:00')]);

        $this->assertSame(1, $this->tick()['scheduled']);

        $fresh = $ticket->fresh();
        $this->assertSame('2026-10-30 10:07:30', $this->localTime($fresh?->sla_warn_at), '75% de 10 min');
        $this->assertSame('2026-10-30 10:10:00', $this->localTime($fresh->sla_due_at));
    }

    public function test_mensagem_das_22h_so_vence_depois_da_abertura_seguinte(): void
    {
        $this->at('2026-10-27 22:01');
        $ticket = $this->ticket(['waiting_since' => $this->sp('2026-10-27 22:00')]);

        $this->tick();

        $fresh = $ticket->fresh();
        $this->assertSame('2026-10-28 08:07:30', $this->localTime($fresh?->sla_warn_at));
        $this->assertSame('2026-10-28 08:10:00', $this->localTime($fresh->sla_due_at));
        $this->assertNull($fresh->sla_warned_at, 'fechado: nada foi marcado');
        $this->assertSame(0, $this->breaches($fresh));
    }

    public function test_aviso_e_estouro_gravam_a_marca_e_so_o_estouro_vira_evento(): void
    {
        $this->at('2026-10-30 10:00');
        $ticket = $this->ticket(['waiting_since' => $this->sp('2026-10-30 10:00')]);
        $this->tick();

        $this->at('2026-10-30 10:08');
        $this->assertSame(1, $this->tick()['warned']);
        $afterWarning = $ticket->fresh();
        $this->assertNotNull($afterWarning?->sla_warned_at);
        $this->assertNull($afterWarning->sla_breached_at);
        $this->assertSame(0, $this->breaches($afterWarning));

        $this->at('2026-10-30 10:11');
        $this->assertSame(1, $this->tick()['breached']);
        $afterBreach = $ticket->fresh();
        $this->assertNotNull($afterBreach?->sla_breached_at);
        $this->assertSame(1, $this->breaches($afterBreach));

        $event = $afterBreach->events()->where('type', 'sla_breached')->sole();
        $this->assertSame('2026-10-30T13:00:00+00:00', $event->payload['waiting_since'] ?? null);
        $this->assertSame('2026-10-30T13:10:00+00:00', $event->payload['due_at'] ?? null);
    }

    public function test_duas_rodadas_gravam_um_unico_sla_breached(): void
    {
        $this->at('2026-10-30 10:00');
        $ticket = $this->ticket(['waiting_since' => $this->sp('2026-10-30 10:00')]);
        $this->tick();
        $this->at('2026-10-30 10:11');

        $this->tick();
        $second = $this->tick();

        $this->assertSame(0, $second['breached']);
        $this->assertSame(1, $this->breaches($ticket));
    }

    public function test_encerrado_e_ignorado_mesmo_com_marca_antiga(): void
    {
        $this->at('2026-10-30 10:30');
        $closed = $this->ticket([
            'status' => TicketStatus::ENCERRADO,
            'closed_at' => $this->sp('2026-10-30 10:05'),
            'waiting_since' => $this->sp('2026-10-30 10:00'),
            'sla_warn_at' => $this->sp('2026-10-30 10:07:30'),
            'sla_due_at' => $this->sp('2026-10-30 10:10'),
        ]);

        $result = $this->tick();

        $this->assertSame(['adopted' => 0, 'scheduled' => 0, 'warned' => 0, 'breached' => 0], $result);
        $this->assertNull($closed->fresh()?->sla_breached_at);
        $this->assertSame(0, $this->breaches($closed));
    }

    public function test_a_rodada_e_limitada_a_200_por_passo_e_o_resto_sai_na_seguinte(): void
    {
        $this->at('2026-10-30 10:00');
        Ticket::factory()->count(250)->create([
            'channel_id' => $this->channel->id,
            'status' => TicketStatus::AGUARDANDO,
            'last_inbound_at' => $this->sp('2026-10-30 09:55'),
            'last_outbound_at' => null,
        ]);

        $this->assertSame(200, $this->tick()['adopted']);
        $this->assertSame(200, Ticket::query()->whereNotNull('waiting_since')->count());

        $this->assertSame(50, $this->tick()['adopted']);
        $this->assertSame(250, Ticket::query()->whereNotNull('waiting_since')->count());
    }

    public function test_dispara_ticket_updated_so_quando_muda_o_estado(): void
    {
        $this->at('2026-10-30 10:00');
        $this->ticket(['waiting_since' => $this->sp('2026-10-30 10:00')]);
        $this->tick();

        Event::fake([TicketUpdated::class]);
        $this->at('2026-10-30 10:05');
        $this->tick();
        Event::assertNotDispatched(TicketUpdated::class);

        $this->at('2026-10-30 10:08');
        $this->tick();
        Event::assertDispatchedTimes(TicketUpdated::class, 1);

        $this->at('2026-10-30 10:11');
        $this->tick();
        Event::assertDispatchedTimes(TicketUpdated::class, 2);

        $this->tick();
        Event::assertDispatchedTimes(TicketUpdated::class, 2);
    }

    public function test_ticket_respondido_apos_o_aviso_nao_estoura(): void
    {
        $this->at('2026-10-30 10:00');
        $ticket = $this->ticket(['waiting_since' => $this->sp('2026-10-30 10:00')]);
        $this->tick();
        $this->at('2026-10-30 10:08');
        $this->tick();

        $ticket->recordOutbound($this->sp('2026-10-30 10:09'));
        $this->at('2026-10-30 10:30');
        $result = $this->tick();

        $this->assertSame(0, $result['breached']);
        $this->assertSame(0, $this->breaches($ticket));
    }
}
