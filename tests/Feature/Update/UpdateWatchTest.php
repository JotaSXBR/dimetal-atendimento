<?php

namespace Tests\Feature\Update;

use App\Enums\AuditAction;
use App\Enums\UpdateStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\SystemUpdate;
use App\Models\User;
use App\Notifications\UpdateFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UpdateWatchTest extends TestCase
{
    use FakesUpdateWorld;
    use RefreshDatabase;

    private SystemUpdate $update;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->prepareUpdateSettings(); // versão rodando = 0.1.0
        $this->update = SystemUpdate::start(null, '0.1.0', '0.2.0') ?? throw new RuntimeException('trava');
    }

    private function at(UpdateStatus $status, int $minutesAgo): void
    {
        $this->update->update(['status' => $status, 'step_at' => now()->subMinutes($minutesAgo), 'previous_compose' => self::CURRENT_COMPOSE, 'previous_version' => '0.1.0']);
    }

    private function watch(): SystemUpdate
    {
        $this->fakeWorld();
        $this->artisan('app:update-watch')->assertSuccessful();

        return $this->update->refresh();
    }

    public function test_reiniciando_com_a_versao_destino_conclui_audita_e_avisa(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->at(UpdateStatus::Reiniciando, 1);
        config(['fluxie.version' => '0.2.0']);

        $update = $this->watch();

        $this->assertSame(UpdateStatus::Concluida, $update->status);
        $this->assertNotNull($update->finished_at);
        $this->assertSame('0.2.0', AuditLog::query()->where('action', AuditAction::UpdateSucceeded->value)->firstOrFail()->subject);
        Notification::assertSentToTimes($admin, UpdateFinished::class, 1);
        $this->assertNotNull($update->notified_at);
    }

    public function test_versao_destino_com_migracao_pendente_nao_conclui(): void
    {
        $this->at(UpdateStatus::Reiniciando, 1);
        config(['fluxie.version' => '0.2.0']);
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        $this->assertSame(UpdateStatus::Reiniciando, $this->watch()->status);
        $this->assertSame(0, AuditLog::query()->where('action', AuditAction::UpdateSucceeded->value)->count());
    }

    public function test_reiniciando_recente_com_a_versao_origem_segue_aberto(): void
    {
        $this->at(UpdateStatus::Reiniciando, 9);

        $this->assertSame(UpdateStatus::Reiniciando, $this->watch()->status);
        $this->assertSame([], $this->writes());
    }

    public function test_reiniciando_ha_mais_de_10_minutos_com_a_versao_origem_devolve(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->at(UpdateStatus::Reiniciando, 11);

        $update = $this->watch();

        $this->assertSame(UpdateStatus::Revertida, $update->status);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::UpdateReverted->value)->count());
        $bodies = array_map(fn (Request $r): array => $r->data(), $this->writes());
        $this->assertSame(self::CURRENT_COMPOSE, base64_decode($bodies[0]['docker_compose_raw']));
        $this->assertSame('0.1.0', $bodies[1]['value']);
        Notification::assertSentToTimes($admin, UpdateFinished::class, 1);
    }

    /** @return array<string, array{UpdateStatus, int, UpdateStatus}> */
    public static function parados(): array
    {
        return [
            'conferindo parado' => [UpdateStatus::Conferindo, 21, UpdateStatus::Falhou],
            'backup parado' => [UpdateStatus::Backup, 21, UpdateStatus::Falhou],
            'conferindo recente' => [UpdateStatus::Conferindo, 19, UpdateStatus::Conferindo],
            'aplicando parado' => [UpdateStatus::Aplicando, 6, UpdateStatus::Revertida],
            'aplicando recente' => [UpdateStatus::Aplicando, 4, UpdateStatus::Aplicando],
        ];
    }

    #[DataProvider('parados')]
    public function test_estados_parados_fecham_so_depois_do_prazo(UpdateStatus $from, int $minutes, UpdateStatus $expected): void
    {
        $this->at($from, $minutes);

        $this->assertSame($expected, $this->watch()->status);
    }

    public function test_conferindo_parado_nao_toca_no_coolify(): void
    {
        $this->at(UpdateStatus::Conferindo, 21);
        $this->watch();

        $this->assertSame([], $this->writes());
    }

    public function test_devolucao_que_falha_fica_falhou_com_o_link_da_recuperacao_e_avisa(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->at(UpdateStatus::Aplicando, 6);
        $this->world['service_patch'] = [500, ['message' => 'quebrou']];

        $update = $this->watch();

        $this->assertSame(UpdateStatus::Falhou, $update->status);
        $this->assertStringContainsString('docs/deploy.md', (string) $update->message);
        Notification::assertSentToTimes($admin, UpdateFinished::class, 1);
    }

    public function test_alerta_sai_uma_vez_so_em_duas_rodadas(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->at(UpdateStatus::Reiniciando, 1);
        config(['fluxie.version' => '0.2.0']);

        $this->watch();
        $this->watch();

        Notification::assertSentToTimes($admin, UpdateFinished::class, 1);
    }

    public function test_avisa_tambem_o_estado_final_fechado_por_outro_caminho(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->update->moveTo(UpdateStatus::Falhou, 'pré-voo falhou');

        $this->watch();

        Notification::assertSentTo($admin, UpdateFinished::class, fn (UpdateFinished $n): bool => $n->toArray($admin)['state'] === 'falhou');
    }

    public function test_envio_que_falha_nao_marca_e_tenta_de_novo_no_proximo_minuto(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->update->moveTo(UpdateStatus::Falhou, 'x');
        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('fila fora'));

        $this->assertNull($this->watch()->notified_at);

        Notification::fake();
        $this->assertNotNull($this->watch()->notified_at);
        Notification::assertSentToTimes($admin, UpdateFinished::class, 1);
    }

    public function test_o_vigia_nunca_reinicia_nem_apaga(): void
    {
        $this->at(UpdateStatus::Reiniciando, 11);
        $this->watch();

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/restart') || $r->method() === 'DELETE');
    }

    public function test_sem_atualizacao_aberta_nao_faz_nada(): void
    {
        $this->update->moveTo(UpdateStatus::Concluida);
        $this->update->update(['notified_at' => now()]);

        $this->watch();
        Http::assertNothingSent();
    }

    public function test_o_comando_roda_a_cada_minuto_sem_sobreposicao(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains($e->command, 'app:update-watch'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
