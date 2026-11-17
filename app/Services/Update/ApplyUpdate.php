<?php

namespace App\Services\Update;

use App\Enums\AuditAction;
use App\Enums\UpdateStatus;
use App\Models\SystemUpdate;
use App\Models\User;
use App\Services\Audit\Auditor;
use App\Services\Backup\BackupKind;
use App\Services\Backup\DumpFailed;
use App\Services\Backup\RunBackup;
use RuntimeException;
use Throwable;

/**
 * Conferências, backup novo e troca do compose e da versão no Coolify. Nada muda no Coolify antes de tudo passar; depois da primeira
 * escrita, qualquer falha regrava o compose e a versão guardados (menos o reinício sem resposta clara, que fica com o vigia).
 * Cada passo confere o estado no banco: um job repetido, ou fechado pelo vigia no meio, não refaz nada.
 */
final class ApplyUpdate
{
    private const VERSION_KEY = 'FLUXIE_VERSION';

    public function __construct(
        private readonly Preflight $preflight,
        private readonly Coolify $coolify,
        private readonly RunBackup $backup,
        private readonly InstalledVersion $installed,
    ) {}

    public function __invoke(SystemUpdate $update): void
    {
        if (! $this->at($update, UpdateStatus::Conferindo)) {
            return;
        }

        try {
            $compose = $this->checked($update);
            if (! $update->moveTo(UpdateStatus::Backup)) {
                return; // o vigia fechou no meio
            }

            $update->update(['backup_file' => $this->backup()]);
        } catch (RuntimeException $e) {
            $this->fail($update, $e->getMessage());

            return;
        }

        $this->at($update, UpdateStatus::Backup) && $this->apply($update, $compose);
    }

    /** Fecha como `falhou` (só vale para o que ainda não mexeu no Coolify, ou para a devolução que não deu). */
    public function fail(SystemUpdate $update, string $message): void
    {
        $this->close($update, UpdateStatus::Falhou, $message, AuditAction::UpdateFailed);
    }

    /** Fecha como `concluida` (quem chama já viu a versão nova rodando). */
    public function succeed(SystemUpdate $update): void
    {
        $this->close($update, UpdateStatus::Concluida, "Sistema atualizado para {$update->to_version}.", AuditAction::UpdateSucceeded);
    }

    /** @throws RuntimeException com os erros do pré-voo, em português */
    private function checked(SystemUpdate $update): string
    {
        $target = Version::parse($update->to_version) ?? throw new RuntimeException('A versão de destino não é válida.');
        $compose = $this->preflight->releaseCompose($target);
        $errors = $this->preflight->check($target, $compose);

        return $errors === [] ? $compose : throw new RuntimeException(implode(' ', $errors));
    }

    /** @throws RuntimeException */
    private function backup(): string
    {
        try {
            $file = ($this->backup)(BackupKind::Manual);
        } catch (DumpFailed $e) {
            throw new RuntimeException('O backup antes da atualização falhou: '.$e->getMessage());
        }

        return $file === null ? throw new RuntimeException('Já há um backup em andamento. Tente de novo quando ele terminar.') : $file->name;
    }

    private function apply(SystemUpdate $update, string $compose): void
    {
        try {
            $envs = $this->coolify->envs();
            $previous = $this->coolify->compose();
        } catch (CoolifyException $e) {
            $this->fail($update, $e->getMessage()); // nada foi gravado ainda

            return;
        }

        $current = $envs[self::VERSION_KEY] ?? null;
        $update->update(['previous_compose' => $previous, 'previous_version' => is_string($current) && $current !== '' ? $current : $this->installed->current()]);
        if (! $update->moveTo(UpdateStatus::Aplicando)) {
            return; // o vigia fechou no meio: nada foi gravado
        }

        try {
            $this->coolify->putCompose($compose);
            $this->coolify->setEnv(self::VERSION_KEY, $update->to_version);

            if (! $update->moveTo(UpdateStatus::Reiniciando)) {
                $this->restoreWrites($update); // o vigia devolveu enquanto gravava: desfaz o que o job gravou e não reinicia

                return;
            }

            $this->restart($update);
        } catch (Throwable $e) {
            // Qualquer outra falha depois da primeira escrita devolve; o que não é do Coolify é relançado para ser reportado.
            $this->revert($update, $e instanceof CoolifyException ? $e->getMessage() : 'Erro inesperado ao aplicar a atualização.');
            $e instanceof CoolifyException || throw $e;
        }
    }

    /**
     * Só a recusa definitiva (4xx) sobe para devolver. Sem conexão, timeout ou 5xx o reinício pode ter acontecido: fica em
     * `reiniciando` e o vigia decide pela versão que sobe (destino = concluída; origem depois de 10 min = devolve).
     *
     * @throws CoolifyException
     */
    private function restart(SystemUpdate $update): void
    {
        try {
            $this->coolify->restart(pull: true);
        } catch (CoolifyException $e) {
            if ($e->status !== 0 && $e->status < 500) {
                throw $e;
            }

            $update->update(['message' => "O Coolify não confirmou o reinício ({$e->getMessage()}). O vigia confere a versão que subir nos próximos minutos."]);
        }
    }

    /** Regrava o compose e a versão guardados sem mudar o estado (a linha já foi fechada por outro processo). */
    private function restoreWrites(SystemUpdate $update): void
    {
        try {
            $this->coolify->putCompose($update->previous_compose ?? throw new CoolifyException('O compose anterior não ficou guardado.'));
            $this->coolify->setEnv(self::VERSION_KEY, $update->previous_version ?? throw new CoolifyException('A versão anterior não ficou guardada.'));
        } catch (Throwable $e) {
            report($e);
            // O Coolify pode ficar com os valores novos: a linha deixa de dizer `revertida` e o vigia avisa de novo.
            $this->fail($update->refresh(), 'A atualização foi desfeita pelo vigia, mas não foi possível regravar o compose e a versão anteriores no Coolify. Siga a recuperação do docs/deploy.md.');
            $update->update(['notified_at' => null]);
        }
    }

    /** Devolve: regrava o compose e a versão guardados (nada é apagado no Coolify). */
    public function revert(SystemUpdate $update, string $reason): void
    {
        try {
            $this->coolify->putCompose($update->previous_compose ?? throw new CoolifyException('O compose anterior não ficou guardado.'));
            $this->coolify->setEnv(self::VERSION_KEY, $update->previous_version ?? throw new CoolifyException('A versão anterior não ficou guardada.'));
        } catch (Throwable $e) {
            $why = $e instanceof CoolifyException ? $e->getMessage() : 'erro inesperado';
            $this->fail($update, "{$reason} Não foi possível devolver o compose e a versão anteriores ({$why}). Siga a recuperação do docs/deploy.md.");

            return;
        }

        $this->close($update, UpdateStatus::Revertida, "A atualização foi desfeita: {$reason}", AuditAction::UpdateReverted);
    }

    private function close(SystemUpdate $update, UpdateStatus $status, string $message, AuditAction $action): void
    {
        if (! $update->moveTo($status, $message)) {
            return; // já fechada por outro processo: sem segunda auditoria
        }

        Auditor::record($action, $update->requested_by === null ? null : User::query()->find($update->requested_by), $update->to_version, ['message' => $message]);
    }

    private function at(SystemUpdate $update, UpdateStatus $status): bool
    {
        return $update->refresh()->status === $status;
    }
}
