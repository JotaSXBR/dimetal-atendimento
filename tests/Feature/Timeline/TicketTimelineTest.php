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

    public function test_reacao_vira_frase_com_o_emoji_ou_com_a_remocao(): void
    {
        $added = $this->event('message_reacted', $this->ana, ['message_id' => 1, 'emoji' => '👍']);
        $removed = $this->event('message_reacted', $this->ana, ['message_id' => 1, 'emoji' => null]);

        $this->assertSame('Ana Atendente reagiu a uma mensagem com 👍', $this->timeline([$added])->sentence($added));
        $this->assertSame('Ana Atendente reagiu a uma mensagem (reação removida)', $this->timeline([$removed])->sentence($removed));
    }

    public function test_editar_e_apagar_para_todos_viram_frase_com_o_autor(): void
    {
        $edited = $this->event('message_edited', $this->ana, ['message_id' => 1]);
        $revoked = $this->event('message_revoked_by_agent', $this->ana, ['message_id' => 1]);

        $this->assertSame('Ana Atendente editou uma mensagem', $this->timeline([$edited])->sentence($edited));
        $this->assertSame('Ana Atendente apagou uma mensagem para todos', $this->timeline([$revoked])->sentence($revoked));
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
        foreach (['opened', 'claimed', 'closed', 'transferred', 'reassigned', 'taken_over', 'merged', 'message_out', 'message_in', 'after_hours_reply', 'media_purged', 'sla_breached', 'bot_started', 'bot_abandoned', 'bot_handoff'] as $type) {
            $this->assertArrayHasKey($type, TicketEventText::TYPES);
        }
    }

    public function test_tipo_desconhecido_nao_despeja_o_json(): void
    {
        $event = $this->event('tipo-novo', null, ['n' => 1]);

        $this->assertSame('', $this->timeline([$event])->detail($event));
        $this->assertSame('', TicketEventText::detail($event));
        $this->assertSame('tipo-novo', $this->timeline([$event])->sentence($event));
    }

    public function test_changes_descarta_tipo_sem_rotulo(): void
    {
        $events = new Collection([$this->event('opened'), $this->event('tipo-novo', null, ['n' => 1]), $this->event('bot_started', null, ['workflow_version_id' => 2])]);

        $this->assertSame(['opened', 'bot_started'], TicketTimeline::changes($events)->pluck('type')->all());
    }

    public function test_bot_iniciou_e_equipe_assumiu_pelo_celular_viram_frase_sem_json(): void
    {
        $started = $this->event('bot_started', null, ['workflow_version_id' => 2]);
        $abandoned = $this->event('bot_abandoned');
        $timeline = $this->timeline([$started, $abandoned]);

        $this->assertSame('Bot iniciou o atendimento', $timeline->sentence($started));
        $this->assertSame('Equipe respondeu pelo celular; a triagem do bot acabou', $timeline->sentence($abandoned));
    }

    public function test_bot_encaminhou_diz_o_setor_e_o_motivo(): void
    {
        $sector = Sector::factory()->create(['name' => 'Comercial']);
        $flow = $this->event('bot_handoff', null, ['reason' => 'fluxo', 'sector_id' => $sector->id]);
        $leave = $this->event('bot_handoff', null, ['reason' => 'sair', 'sector_id' => $sector->id]);
        $errors = $this->event('bot_handoff', null, ['reason' => 'erros', 'sector_id' => $sector->id]);
        $timeout = $this->event('bot_handoff', null, ['reason' => 'timeout', 'sector_id' => $sector->id]);
        $noFlow = $this->event('bot_handoff', null, ['reason' => 'sem_workflow']);
        $unknown = $this->event('bot_handoff', null, ['reason' => 'motivo-novo', 'sector_id' => $sector->id]);
        $removed = $this->event('bot_handoff', null, ['reason' => 'fluxo', 'sector_id' => 999_999]);
        $bare = $this->event('bot_handoff');
        $timeline = $this->timeline([$flow, $leave, $errors, $timeout, $noFlow, $unknown, $removed, $bare]);

        $this->assertSame('Bot encaminhou para Comercial', $timeline->sentence($flow));
        $this->assertSame('Bot encaminhou para Comercial (cliente pediu atendente)', $timeline->sentence($leave));
        $this->assertSame('Bot encaminhou para Comercial (respostas inválidas)', $timeline->sentence($errors));
        $this->assertSame('Bot encaminhou para Comercial (sem resposta do cliente)', $timeline->sentence($timeout));
        $this->assertSame('Bot encaminhou para Fila geral (sem automação ativa)', $timeline->sentence($noFlow));
        $this->assertSame('Bot encaminhou para Comercial', $timeline->sentence($unknown));
        $this->assertSame('Bot encaminhou para setor removido', $timeline->sentence($removed));
        $this->assertSame('Bot encaminhou para Fila geral', $timeline->sentence($bare));
    }

    public function test_bot_encaminhou_com_motivo_que_nao_e_texto_nao_quebra(): void
    {
        $event = $this->event('bot_handoff', null, ['reason' => ['x'], 'sector_id' => 'abc']);

        $this->assertSame('Bot encaminhou para Fila geral', $this->timeline([$event])->sentence($event));
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
