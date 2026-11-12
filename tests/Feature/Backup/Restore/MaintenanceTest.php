<?php

namespace Tests\Feature\Backup\Restore;

use App\Services\Backup\Restore\Maintenance;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Um ambiente que serve para a restauração: manutenção, sessão e cache fora do banco.
        config([
            'app.maintenance.driver' => 'cache',
            'app.maintenance.store' => 'array',
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);
    }

    public function test_ambiente_pronto_nao_tem_problema(): void
    {
        $this->assertNull((new Maintenance)->problem());
        $this->assertTrue((new Maintenance)->ready());
    }

    public function test_manutencao_por_arquivo_e_recusada(): void
    {
        config(['app.maintenance.driver' => 'file']);

        $this->assertStringContainsString('APP_MAINTENANCE_DRIVER=cache', (string) (new Maintenance)->problem());
        $this->assertFalse((new Maintenance)->ready());
    }

    public function test_manutencao_guardada_no_banco_e_recusada(): void
    {
        config(['app.maintenance.store' => 'database']);

        $this->assertStringContainsString('APP_MAINTENANCE_STORE=redis', (string) (new Maintenance)->problem());
    }

    public function test_sessao_no_banco_e_recusada(): void
    {
        config(['session.driver' => 'database']);

        $this->assertStringContainsString('SESSION_DRIVER=redis', (string) (new Maintenance)->problem());
    }

    public function test_cache_no_banco_e_recusado(): void
    {
        config(['cache.default' => 'database']);

        $this->assertStringContainsString('CACHE_STORE=redis', (string) (new Maintenance)->problem());
    }

    public function test_sistema_ja_em_manutencao_e_recusado(): void
    {
        $this->artisan('down')->assertSuccessful();

        $this->assertStringContainsString('já está em manutenção', (string) (new Maintenance)->problem());
    }
}
