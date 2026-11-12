<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\BackupStale;
use App\Settings\BackupStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckBackupHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->travelTo('2026-11-13 12:00:00');
    }

    public function test_avisa_quando_passa_de_26h(): void
    {
        $this->travelTo('2026-11-12 08:00:00');
        BackupStatus::recordSuccess();
        $this->travelTo('2026-11-13 12:00:00'); // 28 h depois

        $this->artisan('app:check-backup-health')->assertSuccessful();

        Notification::assertSentTo($this->admin, BackupStale::class);
        $this->assertNotNull(BackupStatus::staleAlertedAt());
    }

    public function test_nao_avisa_com_backup_recente(): void
    {
        $this->travelTo('2026-11-13 02:15:00');
        BackupStatus::recordSuccess();
        $this->travelTo('2026-11-13 12:00:00');

        $this->artisan('app:check-backup-health')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_primeiro_deploy_sem_backup_da_uma_carencia_de_26h(): void
    {
        $this->artisan('app:check-backup-health')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo('2026-11-14 10:00:00'); // 22 h depois da primeira checagem: o diário das 02:15 ainda não rodou
        $this->artisan('app:check-backup-health')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo('2026-11-14 15:00:00'); // 27 h depois e nunca rodou
        $this->artisan('app:check-backup-health')->assertSuccessful();
        Notification::assertSentTo($this->admin, BackupStale::class);
    }

    public function test_nao_repete_o_aviso_em_24h(): void
    {
        $this->artisan('app:check-backup-health')->assertSuccessful(); // marca a primeira checagem
        $this->travelTo('2026-11-14 15:00:00'); // 27 h: primeiro aviso
        $this->artisan('app:check-backup-health')->assertSuccessful();
        $this->travelTo('2026-11-15 05:00:00'); // 14 h depois do aviso
        $this->artisan('app:check-backup-health')->assertSuccessful();

        Notification::assertSentToTimes($this->admin, BackupStale::class, 1);

        $this->travelTo('2026-11-15 16:00:00'); // 25 h depois do primeiro aviso
        $this->artisan('app:check-backup-health')->assertSuccessful();

        Notification::assertSentToTimes($this->admin, BackupStale::class, 2);
    }
}
