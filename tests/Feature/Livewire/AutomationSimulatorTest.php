<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Automation\Editor;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Bot\WorkflowPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AutomationSimulatorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workflow $workflow;

    private Sector $vendas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendas = Sector::factory()->create(['name' => 'Vendas', 'menu_order' => 1]);
        $this->user = User::factory()->create(['role' => UserRole::SUPERVISOR, 'name' => 'Sílvia Supervisora']);
        $this->workflow = app(WorkflowPublisher::class)->create('Triagem', $this->user, template: true);
    }

    /** @return array<string, mixed> Início → Mensagem → Menu(Vendas) → Transferir */
    private function graph(string $greeting = 'Oi {{nome}}'): array
    {
        $nodes = [['i', 'inicio', []], ['m', 'mensagem', ['texto' => $greeting]], ['mn', 'menu', ['texto' => 'Escolha', 'opcoes' => ['Vendas']]], ['t', 'transferir', ['setor_id' => $this->vendas->id]]];
        $edges = [['i', 'out', 'm'], ['m', 'out', 'mn'], ['mn', 'opt:1', 't']];

        return [
            'nodes' => array_map(fn (array $n): array => ['id' => $n[0], 'type' => $n[1], 'x' => 0, 'y' => 0, 'data' => $n[2]], $nodes),
            'edges' => array_map(fn (array $e): array => ['from' => $e[0], 'port' => $e[1], 'to' => $e[2]], $edges),
        ];
    }

    private function editor(): Testable
    {
        return Livewire::actingAs($this->user)->test(Editor::class, ['workflow' => $this->workflow]);
    }

    public function test_testar_roda_o_rascunho_sem_gravar_o_rascunho_e_sem_nenhuma_escrita_de_negocio(): void
    {
        $draftBefore = $this->workflow->refresh()->draft;

        $component = $this->editor()->call('simStart', $this->graph('Olá, teste!'), 'real');
        $sim = $component->get('sim');

        $this->assertSame([], $component->get('simErrors'));
        $this->assertSame('Olá, teste!', $sim['log'][0]['text']);
        $this->assertSame('menu', $sim['session']['waiting']);
        $this->assertSame($draftBefore, $this->workflow->refresh()->draft, 'o rascunho do banco não muda');
        $this->assertSame([0, 0, 0], [Message::count(), Ticket::count(), TicketNote::count()]);
    }

    public function test_o_contato_de_teste_tem_o_nome_de_quem_testa(): void
    {
        $sim = $this->editor()->call('simStart', $this->graph(), 'real')->get('sim');

        $this->assertSame('Oi Sílvia Supervisora', $sim['log'][0]['text']);
    }

    public function test_rascunho_invalido_mostra_os_erros_do_validar_em_vez_de_simular(): void
    {
        $invalid = ['nodes' => [['id' => 'i', 'type' => 'inicio', 'x' => 0, 'y' => 0, 'data' => []]], 'edges' => []];
        $expected = app(WorkflowPublisher::class)->checkGraph($invalid);

        $component = $this->editor()->call('simStart', $invalid, 'real');

        $this->assertNotSame([], $expected);
        $this->assertSame($expected, $component->get('simErrors'));
        $this->assertSame([], $component->get('sim'), 'nada foi simulado');
    }

    public function test_responder_silencio_e_reiniciar(): void
    {
        $component = $this->editor()->call('simStart', $this->graph(), 'real');

        $this->assertSame('fila', $component->call('simReply', $this->graph(), '1')->get('sim')['outcome']);

        $component->call('simStart', $this->graph(), 'real');
        $this->assertSame('fila', $component->call('simSilence', $this->graph())->get('sim')['outcome']);

        $this->assertSame([], $component->call('simReset')->get('sim'));
    }

    public function test_resposta_vazia_ou_gigante_e_recusada(): void
    {
        $component = $this->editor()->call('simStart', $this->graph(), 'real');
        $before = $component->get('sim');

        $component->call('simReply', $this->graph(), '   ')->assertHasErrors('simText');
        $this->assertSame($before, $component->get('sim'));

        $component->call('simReply', $this->graph(), str_repeat('a', 1001))->assertHasErrors('simText');
        $this->assertSame($before, $component->get('sim'));
    }

    public function test_estado_do_teste_nao_e_editavel_pelo_navegador(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->editor()->set('sim', ['outcome' => 'fila']);
    }

    public function test_atendente_nao_testa(): void
    {
        $component = Livewire::actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->test(Editor::class, ['workflow' => $this->workflow]);

        $component->assertForbidden();
    }

    public function test_seletor_de_relogio_chega_ao_motor(): void
    {
        $nodes = [['i', 'inicio', []], ['c', 'condicao', ['kind' => 'horario']], ['a', 'mensagem', ['texto' => 'Aberto']], ['f', 'mensagem', ['texto' => 'Fechado']], ['t', 'transferir', []]];
        $edges = [['i', 'out', 'c'], ['c', 'true', 'a'], ['c', 'false', 'f'], ['a', 'out', 't'], ['f', 'out', 't']];
        $graph = [
            'nodes' => array_map(fn (array $n): array => ['id' => $n[0], 'type' => $n[1], 'x' => 0, 'y' => 0, 'data' => $n[2]], $nodes),
            'edges' => array_map(fn (array $e): array => ['from' => $e[0], 'port' => $e[1], 'to' => $e[2]], $edges),
        ];

        $closed = $this->editor()->call('simStart', $graph, 'fechado')->get('sim');
        $unknown = $this->editor()->call('simStart', $graph, 'qualquer-coisa')->get('sim');

        $this->assertSame('Fechado', $closed['log'][0]['text']);
        $this->assertSame('real', $unknown['clock'], 'valor fora da lista vale o horário real');
    }

    public function test_painel_testar_aparece_no_editor_com_relogio_silencio_e_reiniciar(): void
    {
        $this->editor()
            ->assertSee('Testar o fluxo')
            ->assertSee('Simular silêncio')
            ->assertSee('Reiniciar')
            ->assertSee('Agora: horário real')
            ->assertSee('Dentro do expediente')
            ->assertSee('Fora do expediente')
            ->assertSee('Nada é enviado nem gravado');
    }

    public function test_grafo_com_bloco_de_tipo_desconhecido_e_recusado_como_no_validar(): void
    {
        $graph = $this->graph();
        $graph['nodes'][] = ['id' => 'x', 'type' => 'nao_existe', 'x' => 0, 'y' => 0, 'data' => []];

        $component = $this->editor()->call('simStart', $graph, 'real');

        $this->assertSame([], $component->get('sim'));
        $component->assertHasErrors('graph');
    }
}
