<?php

namespace Tests\Feature\Setup;

use App\Enums\UserRole;
use App\Livewire\Setup\Install;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Setup\SetupState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class InstallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startFreshInstall();
        config(['setup.code' => 'abc123']);
        RateLimiter::clear('setup-code:127.0.0.1');
    }

    private function preencher(string $code = 'abc123')
    {
        return Livewire::test(Install::class)
            ->set('code', $code)
            ->set('name', 'Maria Admin')
            ->set('email', 'maria@exemplo.com')
            ->set('password', 'Senha-forte-123!')
            ->set('password_confirmation', 'Senha-forte-123!');
    }

    public function test_codigo_certo_cria_admin_loga_e_vai_para_primeiros_passos(): void
    {
        $this->preencher()->call('save')->assertRedirect('/primeiros-passos');

        $user = User::firstOrFail();
        $this->assertSame(UserRole::ADMIN, $user->role);
        $this->assertTrue($user->active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, AuditLog::where('action', 'user.created')->where('actor_label', 'Instalação')->count());
    }

    public function test_codigo_errado_nao_cria_nada(): void
    {
        $component = $this->preencher('errado')->call('save')->assertHasErrors('code');
        $this->assertSame(1, substr_count($component->html(), 'Código inválido.'));

        $this->assertSame(0, User::count());
    }

    public function test_sexta_tentativa_no_minuto_e_barrada_mesmo_com_codigo_certo(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->preencher('errado')->call('save');
        }

        $this->preencher()->call('save')->assertHasErrors('code');

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_instalacao_usa_campos_do_flux(): void
    {
        $html = $this->preencher('errado')->call('save')->html();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new \DOMXPath($dom);

        foreach (['code', 'name', 'email', 'password', 'password_confirmation'] as $field) {
            $inputs = $xpath->query("//*[@data-flux-input]//input[@name='{$field}'][@*[name()='wire:model' and .='{$field}']]");
            $this->assertSame(1, $inputs->length, "campo {$field} fora do flux:input");
        }

        $this->assertStringContainsString('Código inválido.', $html);
    }

    public function test_sem_codigo_configurado_mostra_o_comando_e_nao_tem_formulario(): void
    {
        config(['setup.code' => '']);

        Livewire::test(Install::class)
            ->assertSee('app:create-user')
            ->assertDontSee('wire:model="code"', false);
    }

    public function test_com_admin_vai_para_o_login(): void
    {
        User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::test(Install::class)->assertRedirect('/login');
    }

    public function test_concluida_responde_404(): void
    {
        SetupState::markConcluded();

        Livewire::test(Install::class)->assertNotFound();
    }

    public function test_admin_criado_entre_a_tela_e_o_salvar_e_recusado(): void
    {
        $componente = $this->preencher();
        User::factory()->create(['role' => UserRole::ADMIN]);

        $componente->call('save')->assertHasErrors('code');

        $this->assertSame(1, User::where('role', UserRole::ADMIN)->count());
    }

    public function test_atualizacao_livewire_da_tela_passa_pelo_middleware_sem_redirecionar(): void
    {
        $html = $this->get('/instalar')->assertOk()->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', $html, $m);
        $snapshot = html_entity_decode($m[1]);

        $this->withHeader('X-Livewire', '1')->postJson(route('default-livewire.update'), [
            '_token' => csrf_token(),
            'components' => [['snapshot' => $snapshot, 'updates' => ['name' => 'Maria'], 'calls' => []]],
        ])->assertOk();
    }
}
