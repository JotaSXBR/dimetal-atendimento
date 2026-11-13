<?php

namespace Tests\Feature\Backup;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'dimetal-2026-11-12-0215-diario.dump';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        Storage::disk('backups')->put(self::NAME, 'conteudo-do-dump');
        Storage::disk('backups')->put('lixo.txt', 'segredo');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    public function test_admin_baixa_o_arquivo_com_o_conteudo_e_o_nome(): void
    {
        $response = $this->actingAs($this->admin())->get(route('configuracoes.backups.baixar', self::NAME));

        $response->assertOk()->assertDownload(self::NAME);
        $this->assertSame('conteudo-do-dump', $response->streamedContent());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_nome_forjado_da_404(): void
    {
        $admin = $this->actingAs($this->admin());

        foreach (['..', '.env', 'lixo.txt', 'dimetal-2026-01-01-0000-diario.dump', 'dimetal-2026-11-12-0215-diario.dump.bak'] as $name) {
            $admin->get('/configuracoes/backups/'.$name)->assertNotFound();
        }

        $admin->get('/configuracoes/backups/..%2F.env')->assertNotFound();
        $admin->get('/configuracoes/backups/'.rawurlencode('../../.env'))->assertNotFound();
    }

    public function test_supervisor_e_visitante_nao_baixam(): void
    {
        $this->get('/configuracoes/backups/'.self::NAME)->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))
            ->get('/configuracoes/backups/'.self::NAME)->assertForbidden();
    }

    public function test_download_grava_a_auditoria_e_nome_forjado_nao_grava(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('configuracoes.backups.baixar', self::NAME))->assertOk();
        $this->actingAs($admin)->get('/configuracoes/backups/lixo.txt')->assertNotFound();

        $log = AuditLog::query()->sole();
        $this->assertSame('backup.downloaded', $log->action);
        $this->assertSame(self::NAME, $log->subject);
        $this->assertSame($admin->id, $log->actor_user_id);
    }

    public function test_download_vai_para_o_log_com_usuario_e_arquivo(): void
    {
        Log::spy();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('configuracoes.backups.baixar', self::NAME))->assertOk();

        Log::shouldHaveReceived('notice')->once()->withArgs(
            fn (string $message, array $context): bool => $context === ['user_id' => $admin->id, 'file' => self::NAME],
        );
    }
}
