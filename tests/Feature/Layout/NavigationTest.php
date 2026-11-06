<?php

namespace Tests\Feature\Layout;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Navigation;
use App\Support\NavItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

    /** @return list<string> */
    private function primaryLabels(UserRole $role): array
    {
        return array_map(fn (NavItem $item): string => $item->label, $this->nav()->primary($this->user($role)));
    }

    public function test_primary_por_papel(): void
    {
        $this->assertSame(['Fila', 'Contatos', 'Histórico'], $this->primaryLabels(UserRole::ATENDENTE));
        $this->assertSame(['Fila', 'Contatos', 'Histórico', 'Relatórios'], $this->primaryLabels(UserRole::SUPERVISOR));
        $this->assertSame(['Fila', 'Contatos', 'Histórico', 'Relatórios', 'Auditoria'], $this->primaryLabels(UserRole::ADMIN));
    }

    public function test_settings_groups_por_papel(): void
    {
        $groups = fn (UserRole $role): array => array_map(
            fn (array $items): array => array_map(fn (NavItem $item): string => $item->label, $items),
            $this->nav()->settingsGroups($this->user($role)),
        );

        $this->assertSame([], $groups(UserRole::ATENDENTE));
        $this->assertSame(['Atendimento' => ['Respostas rápidas', 'Etiquetas', 'Automação'], 'Equipe' => ['Usuários']], $groups(UserRole::SUPERVISOR));
        $this->assertSame([
            'Atendimento' => ['Setores', 'Horário e SLA', 'Respostas rápidas', 'Etiquetas', 'Automação'],
            'Equipe' => ['Usuários'],
            'Conexões' => ['Canais', 'Google Contatos'],
            'Empresa' => ['Empresa e assinatura', 'Anexos e retenção'],
        ], $groups(UserRole::ADMIN));
    }

    public function test_settings_entry_e_o_primeiro_item_visivel(): void
    {
        $this->assertSame('configuracoes.setores', $this->nav()->settingsEntry($this->user(UserRole::ADMIN))?->route);
        $this->assertSame('configuracoes.respostas', $this->nav()->settingsEntry($this->user(UserRole::SUPERVISOR))?->route);
        $this->assertNull($this->nav()->settingsEntry($this->user(UserRole::ATENDENTE)));
    }

    private function activeOn(string $url, UserRole $role = UserRole::ADMIN): bool
    {
        $request = Request::create($url);
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);
        app()->instance('request', $request);

        return $this->nav()->settingsActive($this->user($role));
    }

    public function test_settings_active_cobre_automacao_editar_e_o_retorno_do_google(): void
    {
        $this->assertTrue($this->activeOn('/configuracoes/setores'));
        $this->assertTrue($this->activeOn('/automacao/1'));
        $this->assertTrue($this->activeOn('/configuracoes/google/callback'));
        $this->assertFalse($this->activeOn('/inbox'));
        $this->assertFalse($this->activeOn('/configuracoes/setores', UserRole::ATENDENTE));
    }
}
