<?php

namespace Tests\Feature\Audit;

use App\Enums\UserRole;
use App\Livewire\Audit\Index as TicketsAudit;
use App\Livewire\Audit\SystemLog;
use App\Models\User;
use App\Support\Navigation;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class SystemLogPageTest extends TestCase
{
    use RefreshDatabase;

    private function page(UserRole $role = UserRole::ADMIN): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => $role]))->test(SystemLog::class);
    }

    /** @param  array<string, mixed>|null  $detail */
    private function log(string $action, ?string $subject = null, ?CarbonInterface $at = null, ?string $actor = 'Ana Souza <ana@dimetal.test>', ?array $detail = null, ?int $userId = null, ?string $ip = '10.0.0.9'): void
    {
        DB::table('audit_logs')->insert([
            'action' => $action, 'subject' => $subject, 'actor_label' => $actor, 'actor_user_id' => $userId, 'ip' => $ip,
            'detail' => $detail === null ? null : json_encode($detail), 'created_at' => $at ?? now(),
        ]);
    }

    public function test_so_admin_entra(): void
    {
        $this->get('/auditoria/sistema')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->get('/auditoria/sistema')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/auditoria/sistema')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->get('/auditoria/sistema')->assertOk();
    }

    public function test_lista_do_mais_novo_ao_mais_velho_com_quem_o_que_o_ip_e_a_hora_de_sao_paulo(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
        $this->log('auth.logout', null, now()->subHour());
        $this->log('backup.manual_requested', null, now()->subMinutes(5), 'Beto <beto@dimetal.test>', null, null, '10.1.2.3');

        $this->page()->assertSeeInOrder(['Beto <beto@dimetal.test> pediu um backup manual', 'Ana Souza <ana@dimetal.test> saiu do sistema'])
            ->assertSee('10.1.2.3')
            ->assertSee('30/10 08:55');
    }

    public function test_periodo_hoje_comeca_a_meia_noite_de_sao_paulo(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
        $this->log('auth.login', 'Ontem a noite', now()->setTime(2, 30));
        $this->log('auth.login', 'Hoje de madrugada', now()->setTime(4, 0));

        // 02:30 UTC é 23:30 de ontem em Brasília; 04:00 UTC é 01:00 de hoje.
        $this->page()->set('period', 'hoje')->assertSee('30/10 01:00')->assertDontSee('29/10 23:30');
    }

    public function test_filtro_de_grupo_e_de_usuario(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Souza']);
        $this->log('auth.logout', null, now(), 'Ana Souza <ana@dimetal.test>', null, $ana->id);
        $this->log('backup.downloaded', 'arquivo-x.dump', now(), 'Beto <beto@dimetal.test>');

        $this->page()->set('group', 'Backup')->assertSee('baixou o backup arquivo-x.dump')->assertDontSee('saiu do sistema');
        $this->page()->set('userId', $ana->id)->assertSee('saiu do sistema')->assertDontSee('arquivo-x.dump');
    }

    public function test_pagina_de_25_em_25(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));

        foreach (range(1, 30) as $n) {
            $this->log('auth.login', null, now()->subMinutes($n));
        }

        // O 1º é 11:59 UTC (08:59 em Brasília) e o 26º (11:34 UTC, 08:34) já cai na página 2.
        $this->page()->assertSee('30/10 08:59')->assertSee('30/10 08:35')->assertDontSee('30/10 08:34')->assertDontSee('30/10 08:30');
    }

    public function test_nunca_mostra_o_detalhe_bruto_nem_segredo(): void
    {
        $this->log('restore.succeeded', 'dump.dump', now(), 'Ana Souza <ana@dimetal.test>', ['mode' => 'restore', 'message' => 'Concluída.', 'password' => 'segredo-xyz']);

        $html = $this->page()->assertSee('Restauração concluída')->html();

        $this->assertStringNotContainsString('segredo-xyz', $html);
        // As chaves do `detail` (JSON) não viram texto na linha.
        $this->assertStringNotContainsString('&quot;mode&quot;', $html);
        $this->assertStringNotContainsString('"mode"', $html);
    }

    public function test_ator_apagado_aparece_pelo_rotulo_gravado(): void
    {
        $this->log('user.deactivated', 'Beto', now(), 'Fulano Apagado <fulano@dimetal.test>');

        $this->page()->assertSee('Fulano Apagado <fulano@dimetal.test> desativou o usuário Beto');
    }

    public function test_as_duas_paginas_de_auditoria_tem_as_abas(): void
    {
        $this->page()->assertSeeHtml('href="'.route('auditoria').'"')->assertSeeHtml('aria-current="page"');

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(TicketsAudit::class)
            ->assertSeeHtml('href="'.route('auditoria.sistema').'"')->assertSeeHtml('aria-current="page"');
    }

    public function test_o_item_auditoria_do_menu_segue_ativo_na_pagina_nova(): void
    {
        $item = collect(Navigation::items())->first(fn ($item): bool => $item->route === 'auditoria');

        $this->assertNotNull($item);
        $this->assertSame('auditoria*', $item->match);
    }
}
