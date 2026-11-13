<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_grava_ator_rotulo_ip_assunto_e_detalhe(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Souza', 'email' => 'ana@dimetal.test']);
        request()->server->set('REMOTE_ADDR', '10.9.8.7');

        Auditor::record(AuditAction::UserCreated, $ana, 'Beto', ['role' => 'atendente']);

        $log = AuditLog::query()->sole();
        $this->assertSame('user.created', $log->action);
        $this->assertSame($ana->id, $log->actor_user_id);
        $this->assertSame('Ana Souza <ana@dimetal.test>', $log->actor_label);
        $this->assertSame('Beto', $log->subject);
        $this->assertSame('10.9.8.7', $log->ip);
        $this->assertSame(['role' => 'atendente'], $log->detail);
        $this->assertNotNull($log->created_at);
    }

    public function test_sem_ator_e_com_rotulo_explicito(): void
    {
        Auditor::record(AuditAction::RestoreSucceeded, null, 'backup.dump', ['mode' => 'restore'], 'Admin <admin@dimetal.test>');

        $log = AuditLog::query()->sole();
        $this->assertNull($log->actor_user_id);
        $this->assertSame('Admin <admin@dimetal.test>', $log->actor_label);
    }

    public function test_assunto_e_rotulo_gigantes_sao_truncados_em_255(): void
    {
        Auditor::record(AuditAction::LoginFailed, null, str_repeat('a', 400), [], str_repeat('b', 400));

        $log = AuditLog::query()->sole();
        $this->assertSame(255, mb_strlen((string) $log->subject));
        $this->assertSame(255, mb_strlen((string) $log->actor_label));
    }

    public function test_falha_ao_gravar_nao_lanca_e_vira_aviso_no_log(): void
    {
        Log::spy();

        // Um NAN não vira JSON: o `save()` lança por dentro, e o serviço não pode derrubar quem o chamou.
        Auditor::record(AuditAction::Login, null, 'x', ['quebra' => NAN]);

        $this->assertSame(0, AuditLog::query()->count());
        Log::shouldHaveReceived('warning')->once();
    }
}
