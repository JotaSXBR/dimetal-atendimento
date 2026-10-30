<?php

namespace Tests\Feature\Alertas;

use App\Models\User;
use App\Support\AlertSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AlertSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_nulo_e_lixo_caem_nos_padroes(): void
    {
        $padrao = AlertSettings::fromArray(null);

        $this->assertSame(70, $padrao->volume);
        $this->assertTrue($padrao->soundMessage && $padrao->soundQueue && $padrao->soundAssigned && $padrao->soundSla && $padrao->repeat);
        $this->assertFalse($padrao->desktopNotifications);
        $this->assertSame(100, AlertSettings::fromArray(['volume' => 500])->volume);
        $this->assertSame(0, AlertSettings::fromArray(['volume' => -3])->volume);
        $this->assertNull(AlertSettings::fromArray(['mute_critical_until' => 'xx'])->muteCriticalUntil);
        $this->assertSame(70, AlertSettings::fromArray(['volume' => 'abc'])->volume);
    }

    public function test_cada_som_e_desligavel_pelo_formato_do_spec(): void
    {
        $s = AlertSettings::fromArray(['sound' => ['sla' => false], 'repeat' => false]);

        $this->assertFalse($s->soundSla);
        $this->assertFalse($s->repeat);
        $this->assertTrue($s->soundMessage);
        $this->assertFalse($s->toArray()['sound']['sla']);
    }

    public function test_silencio_vale_ate_o_instante(): void
    {
        Carbon::setTestNow('2026-10-30 12:00:00');
        $s = AlertSettings::fromArray(['mute_critical_until' => '2026-10-30 12:15:00']);

        $this->assertTrue($s->criticalMuted());

        Carbon::setTestNow('2026-10-30 12:15:01');
        $this->assertFalse($s->criticalMuted());
    }

    public function test_usuario_novo_tem_padroes(): void
    {
        $this->assertSame(70, User::factory()->create()->alertSettings()->volume);
    }
}
