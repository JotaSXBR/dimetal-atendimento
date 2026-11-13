<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurgeAuditLogsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function log(string $subject, CarbonInterface $at): void
    {
        DB::table('audit_logs')->insert(['action' => 'auth.login', 'subject' => $subject, 'created_at' => $at]);
    }

    public function test_apaga_so_o_que_passou_de_365_dias(): void
    {
        $this->log('velho', now()->subDays(366));
        $this->log('muito-velho', now()->subYears(3));
        $this->log('recente', now()->subDays(364));

        $this->artisan('app:purge-audit-logs')->expectsOutputToContain('Registros de auditoria apagados: 2')->assertSuccessful();

        $this->assertSame(['recente'], AuditLog::query()->pluck('subject')->all());
    }

    public function test_registro_no_limite_exato_fica(): void
    {
        $this->freezeTime();
        $this->log('limite', now()->subDays(365));

        $this->artisan('app:purge-audit-logs')->expectsOutputToContain('Registros de auditoria apagados: 0')->assertSuccessful();

        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_apaga_em_varios_lotes(): void
    {
        foreach (range(1, 5) as $i) {
            $this->log("velho-{$i}", now()->subDays(400));
        }
        $this->log('novo', now());

        $this->artisan('app:purge-audit-logs', ['--batch' => 2])->expectsOutputToContain('Registros de auditoria apagados: 5')->assertSuccessful();

        $this->assertSame(['novo'], AuditLog::query()->pluck('subject')->all());
    }

    public function test_lote_acima_do_teto_e_limitado(): void
    {
        $this->log('velho', now()->subDays(400));
        DB::enableQueryLog();

        $this->artisan('app:purge-audit-logs', ['--batch' => 999999])->assertSuccessful();

        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        $this->assertStringContainsString('limit 20000', $sql);
        $this->assertStringNotContainsString('limit 999999', $sql);
    }

    public function test_sem_nada_a_apagar_sai_com_sucesso(): void
    {
        $this->artisan('app:purge-audit-logs')->expectsOutputToContain('Registros de auditoria apagados: 0')->assertSuccessful();
    }
}
