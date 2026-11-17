<?php

namespace Tests\Feature\Setup;

use App\Enums\ChannelStatus;
use App\Enums\UserRole;
use App\Livewire\Setup\FirstSteps;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Bot\DefaultWorkflow;
use App\Services\Setup\ApplySegment;
use App\Services\Setup\SetupState;
use App\Settings\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FirstStepsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startFreshInstall();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    public function test_atendente_nao_abre(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/primeiros-passos')->assertForbidden();
    }

    public function test_concluida_responde_404(): void
    {
        SetupState::markConcluded();

        $this->actingAs($this->admin())->get('/primeiros-passos')->assertNotFound();
    }

    public function test_empresa_grava_nome_e_avanca(): void
    {
        Livewire::actingAs($this->admin())->test(FirstSteps::class)
            ->set('name', 'Dimetal')
            ->call('saveCompany')
            ->assertHasNoErrors();

        $this->assertSame('Dimetal', CompanySettings::name());
        $this->assertSame('segmento', SetupState::step());
    }

    public function test_volta_no_passo_em_que_parou(): void
    {
        SetupState::setStep('canal');
        Channel::factory()->create(['name' => 'Canal do assistente', 'waha_session' => 'principal']);

        Livewire::actingAs($this->admin())->test(FirstSteps::class)->assertSee('Canal do assistente');
    }

    public function test_distribuidora_no_assistente_avanca_para_o_canal(): void
    {
        $admin = $this->admin();
        SetupState::setStep('segmento');

        Livewire::actingAs($admin)->test(FirstSteps::class)->call('chooseSegment', ApplySegment::DISTRIBUIDORA);

        $this->assertSame('canal', SetupState::step());
        $this->assertSame(ApplySegment::DISTRIBUIDORA, SetupState::segment());
    }

    public function test_criar_canal_na_distribuidora_ativa_o_atendimento_padrao(): void
    {
        $admin = $this->admin();
        app(ApplySegment::class)(ApplySegment::DISTRIBUIDORA, $admin);

        Livewire::actingAs($admin)->test(FirstSteps::class)->call('createChannel')->assertHasNoErrors();

        $channel = Channel::where('waha_session', 'principal')->firstOrFail();
        $this->assertSame(Workflow::where('name', DefaultWorkflow::NAME)->value('id'), $channel->workflow_id);
    }

    public function test_canal_conectado_conclui_e_vai_para_a_fila(): void
    {
        $admin = $this->admin();
        app(ApplySegment::class)(ApplySegment::EM_BRANCO, $admin);
        $component = Livewire::actingAs($admin)->test(FirstSteps::class)->call('createChannel');

        $channel = Channel::where('waha_session', 'principal')->firstOrFail();
        $channel->update(['status' => ChannelStatus::WORKING]);
        $component->call('onChannelStatus', ['channel_id' => $channel->id, 'status' => 'WORKING'])->assertRedirect(route('inbox'));

        $this->assertTrue(SetupState::concluded());
    }

    public function test_pular_conclui_sem_canal(): void
    {
        Livewire::actingAs($this->admin())->test(FirstSteps::class)->call('skip')->assertRedirect(route('inbox'));

        $this->assertTrue(SetupState::concluded());
        $this->assertSame(0, Channel::count());
    }
}
