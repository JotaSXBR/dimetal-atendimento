<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\ManualBackupState;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ManualBackupStateTest extends TestCase
{
    public function test_estado_do_backup_manual_ciclo_completo(): void
    {
        $state = new ManualBackupState;
        $this->assertSame(['status' => 'idle', 'file' => null, 'error' => null], $state->get());

        $state->markStarted();
        $this->assertSame('running', $state->get()['status']);
        $this->assertTrue($state->isRunning());

        $state->markFinished('dimetal-2026-11-12-1000-manual.dump', null);
        $this->assertSame(['status' => 'done', 'file' => 'dimetal-2026-11-12-1000-manual.dump', 'error' => null], $state->get());
        $this->assertFalse($state->isRunning());

        $state->markStarted();
        $state->markFinished(null, 'pg_dump falhou (código 1).');
        $this->assertSame(['status' => 'failed', 'file' => null, 'error' => 'pg_dump falhou (código 1).'], $state->get());
    }

    public function test_o_em_andamento_expira_em_20_minutos_e_o_resultado_em_10(): void
    {
        // O TTL é conferido na gravação: expirar de verdade com `travel()` só funciona no cache em memória, não no Redis do CI.
        Cache::spy();
        $state = new ManualBackupState;

        $state->markStarted();
        $state->markFinished('x.dump', null);

        Cache::shouldHaveReceived('put')->withArgs(fn (string $key, array $value, \DateTimeInterface $until): bool => $key === 'backup:manual'
            && $value['status'] === 'running'
            && $until >= now()->addMinutes(19) && $until <= now()->addMinutes(21))->once();
        Cache::shouldHaveReceived('put')->withArgs(fn (string $key, array $value, \DateTimeInterface $until): bool => $key === 'backup:manual'
            && $value['status'] === 'done'
            && $until >= now()->addMinutes(9) && $until <= now()->addMinutes(11))->once();
    }

    public function test_um_estado_que_expirou_no_cache_volta_a_idle(): void
    {
        $state = new ManualBackupState;
        $state->markStarted();

        Cache::forget('backup:manual');

        $this->assertFalse($state->isRunning());
        $this->assertSame('idle', $state->get()['status']);
    }
}
