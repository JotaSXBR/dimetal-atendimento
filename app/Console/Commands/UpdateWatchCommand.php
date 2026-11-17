<?php

namespace App\Console\Commands;

use App\Enums\UpdateStatus;
use App\Models\SystemUpdate;
use App\Models\User;
use App\Notifications\UpdateFinished;
use App\Services\Alerts\AlertRecipients;
use App\Services\Update\ApplyUpdate;
use App\Services\Update\InstalledVersion;
use App\Services\Update\Version;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Vigia da atualização (a cada minuto, no scheduler que sobe junto com a versão nova): fecha, devolve ou dá por falha a que
 * ficou aberta, e avisa os admins uma vez de todo estado final. Nunca reinicia nem apaga nada no Coolify.
 */
class UpdateWatchCommand extends Command
{
    protected $signature = 'app:update-watch';

    protected $description = 'Fecha, devolve ou avisa sobre a atualização do sistema em andamento';

    public function handle(ApplyUpdate $apply, InstalledVersion $installed, Migrator $migrator, AlertRecipients $recipients): int
    {
        $update = SystemUpdate::open();

        if ($update !== null) {
            $this->watch($update, $apply, $installed->current(), $this->hasPendingMigrations($migrator));
        }

        $this->notifyFinished($recipients);

        return self::SUCCESS;
    }

    private function watch(SystemUpdate $update, ApplyUpdate $apply, string $running, bool $pending): void
    {
        $minutes = $update->step_at === null ? PHP_INT_MAX : (int) $update->step_at->diffInMinutes(now(), true);

        match ($update->status) {
            UpdateStatus::Conferindo, UpdateStatus::Backup => $minutes > 20 && $apply->fail($update, 'A atualização parou no meio e foi encerrada. Nada mudou no Coolify.'),
            UpdateStatus::Aplicando => $minutes > 5 && $apply->revert($update, 'A gravação no Coolify não terminou a tempo.'),
            UpdateStatus::Reiniciando => $this->watchRestart($update, $apply, $running, $pending, $minutes),
            default => null,
        };
    }

    private function watchRestart(SystemUpdate $update, ApplyUpdate $apply, string $running, bool $pending, int $minutes): void
    {
        if ($this->same($running, $update->to_version) && ! $pending) {
            $apply->succeed($update); // o `migrate` roda só no container `app`: com migração pendente não é concluída
        } elseif ($minutes > 10 && $this->same($running, $update->from_version)) {
            $apply->revert($update, 'O sistema não subiu na versão nova.');
        } elseif ($minutes > 60) {
            // Versão desconhecida, ou destino com migração pendente: o vigia não mexe no Coolify; destrava para o dono recuperar.
            $apply->fail($update, 'A atualização não foi confirmada em 60 minutos. O vigia não alterou o Coolify; siga a recuperação do docs/deploy.md.');
        }
    }

    private function same(string $a, string $b): bool
    {
        return (string) (Version::parse($a) ?? $a) === (string) (Version::parse($b) ?? $b);
    }

    private function hasPendingMigrations(Migrator $migrator): bool
    {
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]));

        return array_diff($files, $migrator->getRepository()->getRan()) !== [];
    }

    private function notifyFinished(AlertRecipients $recipients): void
    {
        $finished = SystemUpdate::query()->whereNull('notified_at')->whereNotIn('status', array_column(UpdateStatus::openCases(), 'value'))->get();

        foreach ($finished as $update) {
            try {
                // O banco grava antes do broadcast: se ele falhar, quem já tem a linha no sino não recebe outra na próxima rodada.
                // A chave inclui o estado: `revertida` que vira `falhou` (devolução do job que não deu) avisa de novo.
                $admins = $recipients->admins()->reject(fn (User $admin): bool => $admin->notifications()->where('type', UpdateFinished::class)
                    ->whereRaw("(data::jsonb ->> 'update_id') = ? and (data::jsonb ->> 'state') = ?", [(string) $update->id, $update->status->value])->exists());
                Notification::send($admins, new UpdateFinished($update->status->value, $update->to_version, $update->id));
            } catch (Throwable $e) {
                report($e); // sem marcar: o próximo minuto tenta de novo

                continue;
            }

            $update->update(['notified_at' => now()]);
        }
    }
}
