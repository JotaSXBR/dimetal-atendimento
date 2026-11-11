<?php

namespace Tests\Feature\Bot;

use App\Enums\UserRole;
use App\Models\Sector;
use App\Models\User;
use App\Services\Bot\Simulation\BotSimulator;
use App\Services\Bot\Simulation\SimulationState;
use App\Services\Bot\WorkflowPublisher;
use App\Services\Bot\WorkflowTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Os modelos por segmento, percorridos pelo simulador (o motor de verdade, sem gravar nem enviar nada). */
class SegmentTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private const CNPJ = '11.222.333/0001-81';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Vendas', 'Financeiro', 'SAC'] as $i => $name) {
            Sector::factory()->create(['name' => $name, 'menu_order' => $i + 1]);
        }
    }

    /** @return list<string> as chaves dos modelos da galeria "Por segmento" */
    private function segments(): array
    {
        return array_values(array_map(fn (array $t): string => $t['key'], array_filter(WorkflowTemplates::all(), fn (array $t): bool => $t['group'] === WorkflowTemplates::SEGMENT_GROUP)));
    }

    /** @param  list<string>  $replies */
    private function walk(string $key, string $clock, array $replies): SimulationState
    {
        $graph = WorkflowTemplates::graph($key);
        $sim = app(BotSimulator::class);
        $state = $sim->start($graph, 'Maria', $clock);

        foreach ($replies as $reply) {
            $state = $sim->reply($graph, $state, $reply);
        }

        return $state;
    }

    /** @return list<string> */
    private function texts(SimulationState $state, string $kind): array
    {
        return array_values(array_map(fn (array $l): string => $l['text'], array_filter($state->log, fn (array $l): bool => $l['kind'] === $kind)));
    }

    public function test_a_galeria_tem_o_segmento_distribuidora(): void
    {
        $this->assertContains('distribuidora', $this->segments());
    }

    public function test_todo_segmento_e_valido_com_e_sem_os_setores(): void
    {
        $publisher = app(WorkflowPublisher::class);

        foreach ($this->segments() as $key) {
            $this->assertSame([], $publisher->checkGraph(WorkflowTemplates::graph($key)), "{$key} com setores");
        }

        Sector::query()->delete();

        foreach ($this->segments() as $key) {
            $resolved = WorkflowTemplates::resolve($key);
            $this->assertNotSame([], $resolved['missing'], "{$key} deveria acusar setores faltando");
            $this->assertSame([], $publisher->checkGraph($resolved['graph']), "{$key} sem setores");
        }
    }

    public function test_todo_segmento_tem_no_maximo_cinco_opcoes_e_uma_descricao_honesta(): void
    {
        foreach (WorkflowTemplates::all() as $template) {
            if ($template['group'] !== WorkflowTemplates::SEGMENT_GROUP) {
                continue;
            }

            $menus = array_filter(WorkflowTemplates::graph($template['key'])['nodes'], fn (array $n): bool => $n['type'] === 'menu');
            $this->assertNotSame([], $menus);

            foreach ($menus as $menu) {
                $this->assertLessThanOrEqual(5, count($menu['data']['opcoes']));
            }

            $this->assertStringContainsString('não', $template['description'], 'a descrição diz o que o modelo não faz');
        }
    }

    public function test_fora_do_horario_vai_a_fila_geral_sem_perguntar(): void
    {
        foreach ($this->segments() as $key) {
            $state = $this->walk($key, 'fechado', []);

            $this->assertSame(SimulationState::IN_QUEUE, $state->outcome, $key);
            $this->assertCount(1, $this->texts($state, 'bot'), "{$key}: só o aviso do horário");
            $this->assertSame(['entraria na fila de fila geral'], $this->texts($state, 'efeito'), $key);
        }
    }

    public function test_o_rascunho_do_modelo_nao_grava_posicao_nem_o_nome_do_setor(): void
    {
        $user = User::factory()->create(['role' => UserRole::SUPERVISOR]);

        foreach ($this->segments() as $key) {
            $draft = app(WorkflowPublisher::class)->create('Teste', $user, $key)->draft;

            foreach ($draft['nodes'] as $node) {
                $this->assertArrayNotHasKey('x', $node, "{$key}: o editor organiza ao abrir");
                $this->assertArrayNotHasKey('setor', $node['data'], "{$key}: o nome do setor não é gravado");
            }
        }
    }

    /** @return array<string, array{list<string>, string, list<string>}> */
    public static function distribuidoraRoutes(): array
    {
        return [
            'pedido ou orçamento' => [['1', self::CNPJ, 'Cimento 10 sacos'], 'Vendas', ['Pedido', self::CNPJ, 'Cimento 10 sacos']],
            '2ª via de boleto' => [['2', self::CNPJ, '12345'], 'Financeiro', ['boleto', self::CNPJ, '12345']],
            'entrega' => [['3', self::CNPJ, '12345'], 'SAC', ['entrega', self::CNPJ, '12345']],
            'troca ou devolução' => [['4', self::CNPJ, '12345', 'Produto avariado'], 'SAC', ['Troca', self::CNPJ, '12345', 'Produto avariado']],
            'falar com meu vendedor' => [['5', self::CNPJ], 'Vendas', ['vendedor', self::CNPJ]],
        ];
    }

    /**
     * @param  list<string>  $replies
     * @param  list<string>  $noteParts
     */
    #[DataProvider('distribuidoraRoutes')]
    public function test_distribuidora_cada_opcao_termina_na_fila_do_setor_com_a_nota(array $replies, string $sector, array $noteParts): void
    {
        $state = $this->walk('distribuidora', 'aberto', $replies);

        $this->assertSame(SimulationState::IN_QUEUE, $state->outcome);

        $effects = $this->texts($state, 'efeito');
        $this->assertContains("entraria na fila de {$sector}", $effects);

        $notes = array_values(array_filter($effects, fn (string $e): bool => str_starts_with($e, 'registraria a nota: ')));
        $this->assertCount(1, $notes);

        foreach ($noteParts as $part) {
            $this->assertStringContainsString($part, $notes[0]);
        }
    }

    public function test_distribuidora_tres_cnpj_errados_vao_a_fila(): void
    {
        $state = $this->walk('distribuidora', 'aberto', ['1', 'abc', 'abc', 'abc']);

        $this->assertSame(SimulationState::IN_QUEUE, $state->outcome);
        $this->assertSame([], array_filter($this->texts($state, 'efeito'), fn (string $e): bool => str_starts_with($e, 'registraria a nota')));
    }

    public function test_distribuidora_sair_no_meio_e_silencio_vao_a_fila(): void
    {
        $this->assertSame(SimulationState::IN_QUEUE, $this->walk('distribuidora', 'aberto', ['2', '#sair'])->outcome);

        $graph = WorkflowTemplates::graph('distribuidora');
        $sim = app(BotSimulator::class);
        $state = $sim->silence($graph, $sim->reply($graph, $sim->start($graph, 'Maria', 'aberto'), '3'));

        $this->assertSame(SimulationState::IN_QUEUE, $state->outcome);
    }
}
