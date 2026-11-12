<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\ManualBackupState;
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

    public function test_um_em_andamento_que_ficou_para_tras_expira_sozinho(): void
    {
        $state = new ManualBackupState;
        $state->markStarted();

        $this->travel(21)->minutes();

        $this->assertFalse($state->isRunning());
        $this->assertSame('idle', $state->get()['status']);
    }

    public function test_o_resultado_some_depois_de_10_minutos(): void
    {
        $state = new ManualBackupState;
        $state->markFinished('x.dump', null);

        $this->travel(11)->minutes();

        $this->assertSame('idle', $state->get()['status']);
    }
}
