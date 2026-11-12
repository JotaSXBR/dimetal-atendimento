<?php

namespace App\Services\Backup\Restore;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * O banco novo ao lado do ativo: recebe o `pg_restore` e é conferido antes de qualquer troca. Nada aqui toca o banco em uso;
 * quem chama apaga o banco novo se algo falhar.
 */
class SideDatabase
{
    private const ERROR_LIMIT = 500;

    private const REQUIRED_TABLES = ['users', 'tickets', 'messages', 'settings', 'migrations'];

    public function __construct(private readonly AdminDatabase $admin) {}

    /** @throws RestoreRejected */
    public function restore(string $database, string $dumpPath): void
    {
        AdminDatabase::assertName($database);
        $settings = $this->admin->settings();
        $timeout = config()->integer('backup.restore.staging_timeout');

        try {
            $result = Process::env(['PGPASSWORD' => $settings['password'], 'PGSSLMODE' => $settings['sslmode'] ?: 'prefer'])
                ->timeout($timeout)
                ->run([
                    'pg_restore', '--no-owner', '--exit-on-error',
                    '-h', $settings['host'], '-p', $settings['port'], '-U', $settings['username'],
                    '-d', $database, $dumpPath,
                ]);
        } catch (ProcessTimedOutException) {
            throw new RestoreRejected(sprintf('O pg_restore passou de %d minutos e foi interrompido.', intdiv($timeout, 60)));
        }

        if ($result->failed()) {
            $error = trim($result->errorOutput());
            $error = $settings['password'] === '' ? $error : str_replace($settings['password'], '***', $error);

            throw new RestoreRejected(sprintf('O pg_restore falhou (código %d)%s', (int) $result->exitCode(), $error === '' ? '.' : ': '.Str::limit($error, self::ERROR_LIMIT)));
        }
    }

    /**
     * O banco restaurado tem o esquema esperado, ao menos um admin ativo (senão ninguém entraria) e só migrações que este código
     * conhece (backup de versão mais nova não roda aqui). Migração que falta é normal: o `migrate` roda depois da troca.
     *
     * @throws RestoreRejected
     */
    public function verify(string $database): void
    {
        AdminDatabase::assertName($database);
        $db = $this->admin->connectionTo($database, 'pgsql_side');

        $tables = array_column($this->admin->query($db, "select table_name from information_schema.tables where table_schema = 'public'"), 'table_name');
        $missing = array_values(array_diff(self::REQUIRED_TABLES, array_map(fn (mixed $name): string => is_scalar($name) ? (string) $name : '', $tables)));

        if ($missing !== []) {
            throw new RestoreRejected('O banco restaurado não tem as tabelas esperadas: '.implode(', ', $missing).'.');
        }

        $admins = $this->admin->query($db, "select count(*) as total from users where role = 'admin' and active = true")[0]['total'] ?? 0;

        if (! is_numeric($admins) || (int) $admins < 1) {
            throw new RestoreRejected('O backup não tem nenhum administrador ativo: depois de restaurar ninguém conseguiria entrar.');
        }

        $known = array_map(fn (string $file): string => basename($file, '.php'), glob(database_path('migrations/*.php')) ?: []);
        $stored = array_column($this->admin->query($db, 'select migration from migrations'), 'migration');
        $unknown = array_values(array_diff(array_map(fn (mixed $name): string => is_scalar($name) ? (string) $name : '', $stored), $known));

        if ($unknown !== []) {
            throw new RestoreRejected('O backup é de uma versão mais nova do sistema (migrações desconhecidas: '.implode(', ', array_slice($unknown, 0, 3)).').');
        }
    }
}
