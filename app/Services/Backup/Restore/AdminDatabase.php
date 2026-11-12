<?php

namespace App\Services\Backup\Restore;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Operações de banco inteiro (criar, apagar, trocar por renomeação, listar os guardados) pela conexão administrativa, que
 * conecta no banco `postgres` com o mesmo usuário do app (precisa poder criar bancos). Todo nome de banco é validado por
 * regex e citado; nada que vem da tela vira identificador sem passar por aqui.
 */
class AdminDatabase
{
    private const NAME = '/^[a-z][a-z0-9_]{0,62}$/';

    private const RENAME_ATTEMPTS = 5;

    /** @var array<string, Connection> */
    private array $connections = [];

    /** A conexão ao banco `postgres`, montada da conexão ativa (já com `DB_URL` resolvido). */
    public function connection(): Connection
    {
        return $this->connectionTo('postgres', 'pgsql_admin');
    }

    /** Uma conexão ao banco `$database`, com as credenciais da conexão ativa, registrada com o apelido `$alias`. */
    public function connectionTo(string $database, string $alias): Connection
    {
        if (isset($this->connections[$alias])) {
            return $this->connections[$alias];
        }

        $config = DB::connection()->getConfig();
        $config = is_array($config) ? $config : [];
        $config['database'] = $database;
        $config['url'] = null;
        config(['database.connections.'.$alias => $config]);
        DB::purge($alias);

        return $this->connections[$alias] = DB::connection($alias);
    }

    /** Fecha e esquece uma conexão aberta por {@see self::connectionTo()} (sessão aberta num banco impede renomeá-lo). */
    public function forget(string $alias): void
    {
        DB::purge($alias);
        unset($this->connections[$alias]);
    }

    /**
     * As credenciais da conexão ativa para os processos `pg_*` (a senha vai só no ambiente do processo).
     *
     * @return array{host: string, port: string, username: string, password: string, sslmode: string}
     */
    public function settings(): array
    {
        $raw = DB::connection()->getConfig();
        $raw = is_array($raw) ? $raw : [];
        $value = fn (string $key): string => is_scalar($raw[$key] ?? null) ? (string) $raw[$key] : '';

        return ['host' => $value('host'), 'port' => $value('port'), 'username' => $value('username'), 'password' => $value('password'), 'sslmode' => $value('sslmode')];
    }

    /** O nome do banco que o app está usando agora. */
    public function active(): string
    {
        return DB::connection()->getDatabaseName();
    }

    /** O usuário pode criar e renomear bancos (superusuário ou `CREATEDB`). */
    public function canCreate(): bool
    {
        $row = $this->first('select (rolcreatedb or rolsuper) as ok from pg_roles where rolname = current_user');

        return $row !== null && (bool) $row['ok'];
    }

    public function exists(string $name): bool
    {
        return $this->first('select 1 as found from pg_database where datname = ?', [$name]) !== null;
    }

    /** Banco novo e vazio, com a mesma codificação e localidade do ativo. */
    public function create(string $name): void
    {
        self::assertName($name);
        $admin = $this->connection();
        $source = $this->first('select pg_encoding_to_char(encoding) as enc, datcollate, datctype from pg_database where datname = ?', [$this->active()]) ?? [];
        $pdo = $admin->getPdo();
        $options = ' TEMPLATE template0 ENCODING '.$pdo->quote(is_string($source['enc'] ?? null) ? $source['enc'] : 'UTF8');

        if (is_string($source['datcollate'] ?? null) && is_string($source['datctype'] ?? null)) {
            $options .= ' LC_COLLATE '.$pdo->quote($source['datcollate']).' LC_CTYPE '.$pdo->quote($source['datctype']);
        }

        $admin->statement('CREATE DATABASE "'.$name.'"'.$options);
    }

    /** Só apaga banco `<qualquer>_antes_|_restaurando_AAAAMMDDHHMM` deste sistema, nunca o ativo. */
    public function drop(string $name): void
    {
        self::assertName($name);

        if ($name === $this->active()
            || ! str_starts_with($name, $this->active().'_')
            || preg_match('/_(antes|restaurando)_\d{12}$/', $name) !== 1) {
            throw new InvalidArgumentException('Só é permitido apagar bancos guardados ou em restauração deste sistema.');
        }

        $this->connection()->statement('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
    }

    /**
     * Troca por renomeação: `$active` vira `$archive` e `$incoming` assume o nome `$active`. Derruba as conexões ao ativo e
     * bloqueia novas durante a troca. Se algo falhar, desfaz o que já fez e lança {@see SwapFailed}.
     *
     * @throws SwapFailed
     */
    public function swap(string $active, string $incoming, string $archive): void
    {
        foreach ([$active, $incoming, $archive] as $name) {
            self::assertName($name);
        }

        $admin = $this->connection();
        $renamedActive = false;
        $renamedIncoming = false;

        try {
            $admin->statement('ALTER DATABASE "'.$active.'" WITH ALLOW_CONNECTIONS false');
            $this->rename($active, $archive, terminate: true);
            $renamedActive = true;
            // O banco novo também é derrubado de sessões abertas (por exemplo a de uma verificação): o Postgres não renomeia banco em uso.
            $this->rename($incoming, $active, terminate: true);
            $renamedIncoming = true;
            $admin->statement('ALTER DATABASE "'.$active.'" WITH ALLOW_CONNECTIONS true');
        } catch (Throwable $e) {
            // A mensagem da tela é genérica; a causa real (erro do Postgres) fica no log.
            report($e);
            $this->undo($active, $incoming, $archive, $renamedActive, $renamedIncoming);

            throw new SwapFailed('Não foi possível trocar o banco; o banco anterior continua ativo.', 0, $e);
        }
    }

    /**
     * Os bancos guardados do banco ativo, do mais novo ao mais velho.
     *
     * @return list<StoredDatabase>
     */
    public function stored(): array
    {
        return $this->named('antes');
    }

    /**
     * Os bancos de restauração (`<db>_restaurando_...`): só existem enquanto uma restauração roda; os que sobram são órfãos de um
     * processo morto no meio.
     *
     * @return list<StoredDatabase>
     */
    public function staging(): array
    {
        return $this->named('restaurando');
    }

    /** @return list<StoredDatabase> */
    private function named(string $kind): array
    {
        $active = $this->active();
        $zone = config()->string('app.display_timezone');
        $found = [];

        foreach ($this->rows('select datname, pg_database_size(datname) as bytes from pg_database where datname like ?', [str_replace('_', '\\_', $active).'\\_'.$kind.'\\_%']) as $row) {
            $database = StoredDatabase::fromRow($row, $active, $kind, $zone);

            if ($database !== null) {
                $found[] = $database;
            }
        }

        usort($found, fn (StoredDatabase $a, StoredDatabase $b): int => $b->createdAt <=> $a->createdAt);

        return $found;
    }

    /** @throws InvalidArgumentException */
    public static function assertName(string $name): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException('Nome de banco inválido.');
        }
    }

    public static function archiveName(string $active, CarbonImmutable $at): string
    {
        return $active.'_antes_'.$at->setTimezone(config()->string('app.display_timezone'))->format('YmdHi');
    }

    public static function stagingName(string $active, CarbonImmutable $at): string
    {
        return $active.'_restaurando_'.$at->setTimezone(config()->string('app.display_timezone'))->format('YmdHi');
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $bindings = []): array
    {
        return $this->query($this->connection(), $sql, $bindings);
    }

    /**
     * Linhas como arrays tipados (a API do Laravel devolve `mixed`).
     *
     * @param  array<int, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function query(Connection $db, string $sql, array $bindings = []): array
    {
        $rows = [];

        foreach ($db->select($sql, $bindings) as $row) {
            $values = [];

            foreach (get_object_vars((object) $row) as $column => $value) {
                $values[(string) $column] = $value;
            }

            $rows[] = $values;
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $bindings
     * @return array<string, mixed>|null
     */
    private function first(string $sql, array $bindings = []): ?array
    {
        return $this->rows($sql, $bindings)[0] ?? null;
    }

    /** Renomeia, repetindo se o Postgres ainda achar uma sessão no banco (por exemplo uma tarefa do autovacuum). */
    private function rename(string $from, string $to, bool $terminate = false): void
    {
        for ($attempt = 1; ; $attempt++) {
            if ($terminate) {
                $this->connection()->select('select pg_terminate_backend(pid) from pg_stat_activity where datname = ? and pid <> pg_backend_pid()', [$from]);
            }

            try {
                $this->connection()->statement('ALTER DATABASE "'.$from.'" RENAME TO "'.$to.'"');

                return;
            } catch (QueryException $e) {
                if ($attempt >= self::RENAME_ATTEMPTS || ! str_contains($e->getMessage(), 'being accessed by other users')) {
                    throw $e;
                }

                usleep(500_000);
            }
        }
    }

    /** Desfaz a troca pela ordem inversa; cada passo é tentado mesmo se o anterior falhar. */
    private function undo(string $active, string $incoming, string $archive, bool $renamedActive, bool $renamedIncoming): void
    {
        $admin = $this->connection();
        $steps = [];

        if ($renamedIncoming) {
            $steps[] = 'ALTER DATABASE "'.$active.'" RENAME TO "'.$incoming.'"';
        }

        if ($renamedActive) {
            $steps[] = 'ALTER DATABASE "'.$archive.'" RENAME TO "'.$active.'"';
        }

        $steps[] = 'ALTER DATABASE "'.$active.'" WITH ALLOW_CONNECTIONS true';

        foreach ($steps as $sql) {
            try {
                $admin->statement($sql);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
