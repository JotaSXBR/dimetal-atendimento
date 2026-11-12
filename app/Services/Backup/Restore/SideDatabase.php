<?php

namespace App\Services\Backup\Restore;

use Illuminate\Database\Connection;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
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

            // O stderr do pg_restore pode trazer linhas das tabelas (CONTEXT: COPY ...) e a página de andamento é pública: o detalhe
            // fica só no log, a mensagem mostrada leva o código.
            Log::warning('pg_restore falhou na restauração do banco', ['exit' => (int) $result->exitCode(), 'stderr' => Str::limit($error, self::ERROR_LIMIT)]);

            throw new RestoreRejected(sprintf('O pg_restore falhou (código %d). O detalhe está no log do app.', (int) $result->exitCode()));
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

        try {
            $this->check($database);
        } finally {
            // Sessão aberta no banco novo impediria a troca por renomeação.
            $this->admin->forget('pgsql_side');
        }
    }

    /**
     * A TOC de um dump só mostra rótulos; o catálogo do banco restaurado mostra o que de fato foi criado. Função, gatilho, regra, visão,
     * evento, linguagem ou extensão que o sistema não usa nunca ficam em um banco que vai assumir o lugar do ativo (o app roda como o
     * dono do banco e executaria esses objetos). Não cobre expressões de DEFAULT/CHECK nem o que o `pg_restore` executa durante a
     * restauração: por isso o envio de arquivo é só para origem confiável (ver o spec e o roteiro).
     *
     * @throws RestoreRejected
     */
    private function assertOnlyOurObjects(Connection $db): void
    {
        $row = $this->admin->query($db, <<<'SQL'
            select (
                (select count(*) from pg_proc p join pg_namespace n on n.oid = p.pronamespace
                  where n.nspname not in ('pg_catalog', 'information_schema')
                    and not exists (select 1 from pg_depend d where d.objid = p.oid and d.deptype = 'e'))
              + (select count(*) from pg_trigger where not tgisinternal)
              + (select count(*) from pg_rewrite r join pg_class c on c.oid = r.ev_class where c.relnamespace = 'public'::regnamespace and c.relkind = 'r')
              + (select count(*) from pg_class c where c.relnamespace = 'public'::regnamespace and c.relkind in ('v', 'm', 'f'))
              + (select count(*) from pg_event_trigger)
              + (select count(*) from pg_language where lanispl and lanname <> 'plpgsql')
              + (select count(*) from pg_extension where extname not in ('plpgsql', 'pg_trgm'))
            ) as total
            SQL)[0]['total'] ?? 0;
        $found = is_numeric($row) ? (int) $row : 0;

        if ($found > 0) {
            throw new RestoreRejected('O banco restaurado contém objetos que este sistema não usa (funções, gatilhos, visões ou extensões).');
        }
    }

    /** @throws RestoreRejected */
    private function check(string $database): void
    {
        $db = $this->admin->connectionTo($database, 'pgsql_side');

        $tables = array_column($this->admin->query($db, "select table_name from information_schema.tables where table_schema = 'public'"), 'table_name');
        $missing = array_values(array_diff(self::REQUIRED_TABLES, array_map(fn (mixed $name): string => is_scalar($name) ? (string) $name : '', $tables)));

        if ($missing !== []) {
            throw new RestoreRejected('O banco restaurado não tem as tabelas esperadas: '.implode(', ', $missing).'.');
        }

        $this->assertOnlyOurObjects($db);

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
