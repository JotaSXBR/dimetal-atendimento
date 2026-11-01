<?php

namespace Tests\Feature\Bot;

use App\Models\BotSession;
use App\Models\Channel;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_versao_publicada_nao_pode_ser_alterada(): void
    {
        $version = WorkflowVersion::factory()->create();

        $this->expectException(LogicException::class);

        $version->update(['graph' => ['nodes' => [['id' => 'n1']], 'edges' => []]]);
    }

    public function test_numero_da_versao_e_unico_por_workflow_e_latest_devolve_a_maior(): void
    {
        $workflow = Workflow::factory()->create();
        WorkflowVersion::factory()->create(['workflow_id' => $workflow->id, 'number' => 1]);
        $last = WorkflowVersion::factory()->create(['workflow_id' => $workflow->id, 'number' => 2]);

        $this->assertSame($last->id, $workflow->latestVersion()?->id);

        $this->expectException(QueryException::class);
        WorkflowVersion::factory()->create(['workflow_id' => $workflow->id, 'number' => 2]);
    }

    public function test_so_uma_sessao_de_bot_por_ticket(): void
    {
        $ticket = Ticket::factory()->create();
        BotSession::factory()->create(['ticket_id' => $ticket->id]);

        $this->assertSame('ativa', BotSession::query()->active()->firstOrFail()->state);
        $this->assertSame([], BotSession::query()->firstOrFail()->vars);

        $this->expectException(QueryException::class);
        BotSession::factory()->create(['ticket_id' => $ticket->id]);
    }

    public function test_canal_aponta_para_o_workflow_ativo(): void
    {
        $workflow = Workflow::factory()->create();
        $channel = Channel::factory()->create(['workflow_id' => $workflow->id]);

        $this->assertSame($workflow->id, $channel->workflow?->id);

        $workflow->forceDelete();
        $this->assertNull($channel->fresh()?->workflow_id);
    }

    public function test_etiqueta_tem_nome_unico_e_liga_ao_ticket(): void
    {
        $tag = Tag::factory()->create(['name' => 'VIP']);
        $ticket = Ticket::factory()->create();
        $ticket->tags()->attach($tag);

        $this->assertSame(['VIP'], $ticket->tags()->pluck('name')->all());
        $this->assertNull($ticket->botSession);

        $this->expectException(QueryException::class);
        Tag::factory()->create(['name' => 'VIP']);
    }

    public function test_workflow_guarda_quem_editou(): void
    {
        $user = User::factory()->create();
        $workflow = Workflow::factory()->create(['updated_by' => $user->id]);

        $this->assertSame($user->id, $workflow->fresh()?->updated_by);
    }
}
