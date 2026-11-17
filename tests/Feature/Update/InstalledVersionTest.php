<?php

namespace Tests\Feature\Update;

use App\Services\Update\InstalledVersion;
use Tests\TestCase;

class InstalledVersionTest extends TestCase
{
    public function test_versao_do_env_vence_o_build(): void
    {
        config(['fluxie.version' => '0.3.0', 'fluxie.build' => '0.1.0']);

        $this->assertSame('0.3.0', (new InstalledVersion)->current());
        $this->assertSame('0.3.0', (string) (new InstalledVersion)->release());
    }

    public function test_sem_env_cai_no_build(): void
    {
        config(['fluxie.version' => '', 'fluxie.build' => '0.1.0']);

        $this->assertSame('0.1.0', (new InstalledVersion)->current());
    }

    public function test_sha_nao_e_release(): void
    {
        config(['fluxie.version' => null, 'fluxie.build' => 'sha-abc123']);

        $this->assertSame('sha-abc123', (new InstalledVersion)->current());
        $this->assertNull((new InstalledVersion)->release());
    }
}
