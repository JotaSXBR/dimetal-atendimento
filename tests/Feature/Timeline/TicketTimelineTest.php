<?php

namespace Tests\Feature\Timeline;

use App\Models\Channel;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Support\TicketEventText;
use App\Support\TicketTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TicketTimelineTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ticket = Ticket::factory()->for(Channel::factory()->create())->create();
        $this->ana = User::factory()->create(['name' => 'Ana Atendente']);
    }

    /** @param  array<string, mixed>|null  $payload */
    private function event(string $type, ?User $actor = null, ?array $payload = null): TicketEvent
    {
        return TicketEvent::create(['ticket_id' => $this->ticket->id, 'type' => $type, 'actor_user_id' => $actor?->id, 'payload' => $payload]);
    }

    /** @param  list<TicketEvent>  $events */
    private function timeline(array $events): TicketTimeline
    {
        return new TicketTimeline(new Collection($events));
    }

    public function test_transferencia_vira_frase_com_os_nomes_e_a_nota(): void
    {
        $from = Sector::factory()->create(['name' => 'Comercial']);
        $to = Sector::factory()->create(['name' => 'Financeiro']);
        $event = $this->event('transferred', $this->ana, ['from_sector' => $from->id, 'to_sector' => $to->id, 'note' => 'cliente pediu boleto']);

        $this->assertSame('Ana Atendente transferiu de Comercial para Financeiro · cliente pediu boleto', $this->timeline([$event])->sentence($event));
    }

    public function test_sem_setor_de_origem_vira_fila_geral(): void
    {
        $to = Sector::factory()->create(['name' => 'Financeiro']);
        $event = $this->event('transferred', $this->ana, ['to_sector' => $to->id]);

        $this->assertSame('de Fila geral para Financeiro', $this->timeline([$event])->detail($event));
    }

    public function test_reatribuicao_e_tomada_mostram_quem_recebeu(): void
    {
        $beto = User::factory()->create(['name' => 'Beto Souza']);
        $reassigned = $this->event('reassigned', $this->ana, ['from_user' => $this->ana->id, 'to_user' => $beto->id]);
        $taken = $this->event('taken_over', $beto, ['from_user' => $this->ana->id, 'to_user' => $beto->id]);
        $timeline = $this->timeline([$reassigned, $taken]);

        $this->assertSame('Ana Atendente reatribuiu para Beto Souza', $timeline->sentence($reassigned));
        $this->assertSame('Beto Souza tomou o ticket para Beto Souza', $timeline->sentence($taken));
    }

    public function test_setor_ou_usuario_apagado_vira_removido_sem_erro(): void
    {
        $transfer = $this->event('transferred', $this->ana, ['from_sector' => 999_999, 'to_sector' => 999_998]);
        $reassign = $this->event('reassigned', $this->ana, ['to_user' => 999_997]);
        $timeline = $this->timeline([$transfer, $reassign]);

        $this->assertSame('de setor removido para setor removido', $timeline->detail($transfer));
        $this->assertSame('para usuário removido', $timeline->detail($reassign));
    }

    public function test_eventos_do_sistema_leem_como_rotulo(): void
    {
        $opened = $this->event('opened');
        $inactive = $this->event('closed', null, ['reason' => 'inatividade']);
        $sla = $this->event('sla_breached', null, ['waiting_since' => '2026-10-30T13:00:00Z', 'due_at' => '2026-10-30T13:10:00Z']);
        $timeline = $this->timeline([$opened, $inactive, $sla]);

        $this->assertSame('Ticket aberto', $timeline->sentence($opened));
        $this->assertSame('Encerrado por inatividade', $timeline->sentence($inactive));
        $this->assertSame('SLA estourado (esperando desde 30/10 10:00, limite 30/10 10:10)', $timeline->sentence($sla));
    }

    public function test_encerramento_por_atendente_mostra_motivo_e_resumo(): void
    {
        $event = $this->event('closed', $this->ana, ['reason' => 'manual', 'outcome' => 'resolvido', 'summary' => 'Orcamento aprovado']);

        $this->assertSame('Ana Atendente encerrou: Resolvido · Orcamento aprovado', $this->timeline([$event])->sentence($event));
    }

    public function test_mensagens_nao_mostram_json_e_a_recebida_tem_rotulo(): void
    {
        $in = $this->event('message_in', null, ['message_id' => 55]);
        $out = $this->event('message_out', $this->ana, ['message_id' => 56]);
        $timeline = $this->timeline([$in, $out]);

        $this->assertSame('', $timeline->detail($in));
        $this->assertSame('', $timeline->detail($out));
        $this->assertSame('Mensagem recebida', TicketEventText::label('message_in'));
    }

    public function test_todo_tipo_gravado_pelo_app_tem_rotulo(): void
    {
        foreach (['opened', 'claimed', 'closed', 'transferred', 'reassigned', 'taken_over', 'merged', 'message_out', 'message_in', 'after_hours_reply', 'media_purged', 'sla_breached'] as $type) {
            $this->assertArrayHasKey($type, TicketEventText::TYPES);
        }
    }

    public function test_tipo_desconhecido_mostra_o_conteudo_como_veio(): void
    {
        $event = $this->event('tipo-novo', null, ['n' => 1]);

        $this->assertSame('{"n":1}', $this->timeline([$event])->detail($event));
    }

    public function test_changes_descarta_so_os_eventos_de_conversa(): void
    {
        $events = new Collection([
            $this->event('opened'), $this->event('message_in'), $this->event('message_out'), $this->event('after_hours_reply'),
            $this->event('media_purged'), $this->event('claimed', $this->ana),
        ]);

        $this->assertSame(['opened', 'claimed'], TicketTimeline::changes($events)->pluck('type')->all());
    }

    public function test_os_nomes_vem_de_uma_consulta_por_tipo(): void
    {
        $sector = Sector::factory()->create();
        $events = [];
        foreach (range(1, 5) as $unused) {
            $events[] = $this->event('transferred', $this->ana, ['to_sector' => $sector->id, 'to_user' => $this->ana->id]);
        }

        $events = TicketEvent::query()->with('actor')->get()->all();

        $queries = 0;
        \DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $timeline = $this->timeline($events);
        foreach ($events as $event) {
            $timeline->sentence($event);
        }

        $this->assertSame(2, $queries, 'um select de setores e um de usuários, não um por evento');
    }
}
