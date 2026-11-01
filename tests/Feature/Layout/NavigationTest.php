<?php

namespace Tests\Feature\Layout;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Navigation;
use App\Support\NavItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    private function nav(): Navigation
    {
        return app(Navigation::class);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** @return array<string, array<string, list<string>>> */
    private function labels(UserRole $role): array
    {
        $out = [];
        foreach ($this->nav()->tree($this->user($role)) as $section => $groups) {
            foreach ($groups as $group => $items) {
                $out[$section][$group] = array_map(fn (NavItem $item): string => $item->label, $items);
            }
        }

        return $out;
    }

    public function test_atendente_ve_so_a_secao_atendimento(): void
    {
        $this->assertSame(['Atendimento' => ['' => ['Fila', 'Contatos', 'Histórico']]], $this->labels(UserRole::ATENDENTE));
    }

    public function test_supervisor_ve_relatorios_e_dois_itens_de_configuracao(): void
    {
        $this->assertSame([
            'Atendimento' => ['' => ['Fila', 'Contatos', 'Histórico']],
            'Acompanhamento' => ['' => ['Relatórios']],
            'Configuração' => ['Atendimento' => ['Respostas rápidas', 'Etiquetas', 'Automação'], 'Equipe' => ['Usuários']],
        ], $this->labels(UserRole::SUPERVISOR));
    }

    public function test_admin_ve_tudo_na_ordem_do_design(): void
    {
        $this->assertSame([
            'Atendimento' => ['' => ['Fila', 'Contatos', 'Histórico']],
            'Acompanhamento' => ['' => ['Relatórios', 'Auditoria']],
            'Configuração' => [
                'Atendimento' => ['Setores', 'Horário e SLA', 'Respostas rápidas', 'Etiquetas', 'Automação'],
                'Equipe' => ['Usuários'],
                'Conexões' => ['Canais', 'Google Contatos'],
                'Empresa' => ['Empresa e assinatura', 'Anexos e retenção'],
            ],
        ], $this->labels(UserRole::ADMIN));
    }

    public function test_irmaos_so_dentro_do_grupo_e_ocultos_com_menos_de_dois(): void
    {
        $names = fn (array $items): array => array_map(fn (NavItem $item): string => $item->label, $items);

        $this->assertSame(['Setores', 'Horário e SLA', 'Respostas rápidas', 'Etiquetas', 'Automação'], $names($this->nav()->siblings($this->user(UserRole::ADMIN), 'configuracoes.setores')));
        $this->assertSame(['Respostas rápidas', 'Etiquetas', 'Automação'], $names($this->nav()->siblings($this->user(UserRole::SUPERVISOR), 'configuracoes.respostas')));
        $this->assertSame([], $this->nav()->siblings($this->user(UserRole::SUPERVISOR), 'configuracoes.usuarios'));
        $this->assertSame([], $this->nav()->siblings($this->user(UserRole::ADMIN), 'configuracoes.usuarios'));
        $this->assertSame([], $this->nav()->siblings($this->user(UserRole::ADMIN), 'rota.que.nao.existe'));
    }

    public function test_toda_rota_da_lista_existe_e_o_portao_confere_com_o_middleware(): void
    {
        foreach (Navigation::items() as $item) {
            $route = Route::getRoutes()->getByName($item->route);
            $this->assertNotNull($route, $item->route);

            $gates = array_filter($route->gatherMiddleware(), fn ($m): bool => is_string($m) && str_starts_with($m, 'can:'));
            $this->assertSame($item->gate === null ? [] : ['can:'.$item->gate], array_values($gates), $item->route);
        }
    }

    public function test_find_devolve_o_item_pela_rota(): void
    {
        $this->assertSame('Horário e SLA', $this->nav()->find('configuracoes.horario')?->label);
        $this->assertNull($this->nav()->find('nao.existe'));
    }
}
