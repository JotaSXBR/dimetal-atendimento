<?php

namespace Tests\Feature\Alertas;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AlertSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_vai_para_o_login_e_usuario_ve_a_aba(): void
    {
        $this->get(route('alerts.edit'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get(route('alerts.edit'))->assertOk()->assertSee('Alertas')->assertSee('Prévia dos sons');
    }

    public function test_abre_com_os_padroes_e_salva_as_escolhas(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.alerts')
            ->assertSet('volume', 70)
            ->assertSet('soundSla', true)
            ->set('soundSla', false)
            ->set('repeat', false)
            ->set('volume', 30)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('alert-settings-updated');

        $s = $user->fresh()->alertSettings();
        $this->assertFalse($s->soundSla);
        $this->assertFalse($s->repeat);
        $this->assertSame(30, $s->volume);
        $this->assertTrue($s->soundMessage);
    }

    public function test_volume_fora_da_faixa_e_recusado(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::settings.alerts')->set('volume', 500)->call('save')->assertHasErrors(['volume']);
    }

    public function test_silenciar_grava_o_prazo_e_so_aceita_15_min_ou_1_h(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.alerts')->call('mute', 7);
        $this->assertFalse($user->fresh()->alertSettings()->criticalMuted());

        Livewire::test('pages::settings.alerts')->call('mute', 60);
        $this->assertTrue($user->fresh()->alertSettings()->criticalMuted());
    }

    public function test_so_edita_as_proprias_preferencias(): void
    {
        $eu = User::factory()->create();
        $outro = User::factory()->create();
        $this->actingAs($eu);

        Livewire::test('pages::settings.alerts')->set('volume', 10)->call('save');

        $this->assertSame(70, $outro->fresh()->alertSettings()->volume);
    }
}
