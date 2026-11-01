<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Settings\Company;
use App\Models\User;
use App\Settings\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompanyPageTest extends TestCase
{
    use RefreshDatabase;

    private function page(UserRole $role = UserRole::ADMIN): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => $role]))->test(Company::class);
    }

    public function test_padroes_quando_nada_foi_salvo(): void
    {
        config(['app.name' => 'Dimetal']);

        $this->assertSame('Dimetal', CompanySettings::name());
        $this->assertTrue(CompanySettings::signatureEnabled());
        $this->assertSame('*{{atendente}} @ {{empresa}}:*', CompanySettings::signatureTemplate());
        $this->assertSame('inicio', CompanySettings::signaturePosition());
        $this->assertSame(10, CompanySettings::slaMinutes());
    }

    public function test_admin_salva_e_a_configuracao_e_relida(): void
    {
        $this->page()
            ->set('name', 'Dimetal Distribuidora')
            ->set('signatureEnabled', false)
            ->set('signatureTemplate', '_{{atendente}}_ ({{setor}})')
            ->set('signaturePosition', 'fim')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Dimetal Distribuidora', CompanySettings::name());
        $this->assertFalse(CompanySettings::signatureEnabled());
        $this->assertSame('_{{atendente}}_ ({{setor}})', CompanySettings::signatureTemplate());
        $this->assertSame('fim', CompanySettings::signaturePosition());
    }

    public function test_salvar_empresa_nao_mexe_no_sla(): void
    {
        CompanySettings::save(['slaMinutes' => 25]);

        $this->page()->set('name', 'Outra Empresa')->call('save')->assertHasNoErrors();

        $this->assertSame(25, CompanySettings::slaMinutes());
        $this->assertSame('Outra Empresa', CompanySettings::name());
    }

    public function test_a_tela_abre_com_os_valores_salvos(): void
    {
        CompanySettings::save(['name' => 'Acme', 'signatureEnabled' => true, 'signatureTemplate' => 'x', 'signaturePosition' => 'fim']);

        $this->page()->assertSet('name', 'Acme')->assertSet('signaturePosition', 'fim');
    }

    public function test_a_previa_mostra_a_assinatura_formatada_com_o_nome_de_quem_edita(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'name' => 'Jessé']);

        Livewire::actingAs($admin)->test(Company::class)
            ->set('name', 'Dimetal Distribuidora')
            ->assertSeeHtml('<strong>Jessé @ Dimetal Distribuidora:</strong>')
            ->set('signaturePosition', 'fim')
            ->assertSeeHtml('Bom dia! Tudo bem?');
    }

    public function test_so_admin_entra(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->get('/configuracoes/empresa')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/configuracoes/empresa')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->get('/configuracoes/empresa')->assertOk();
    }

    /** @return array<string, array{string, mixed}> */
    public static function entradasInvalidas(): array
    {
        return [
            'nome vazio' => ['name', ''],
            'nome longo' => ['name', str_repeat('a', 121)],
            'modelo longo' => ['signatureTemplate', str_repeat('a', 301)],
            'posicao invalida' => ['signaturePosition', 'meio'],
        ];
    }

    #[DataProvider('entradasInvalidas')]
    public function test_validacao(string $field, mixed $value): void
    {
        $this->page()->set($field, $value)->call('save')->assertHasErrors([$field]);
    }

    public function test_o_menu_se_chama_atendimento(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/inbox')
            ->assertSee('Atendimento')
            ->assertDontSee('Fila do setor');
    }
}
