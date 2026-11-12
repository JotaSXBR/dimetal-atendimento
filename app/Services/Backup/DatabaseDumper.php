<?php

namespace App\Services\Backup;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Gera o dump do banco em um arquivo temporário (`pg_dump -Fc`, cliente 18) e o confere com `pg_restore --list`.
 * A senha vai só no ambiente do processo (`PGPASSWORD`), nunca na linha de comando nem em mensagem de erro.
 */
class DatabaseDumper
{
    private const TIMEOUT_SECONDS = 1800;

    private const ERROR_LIMIT = 500;

    /**
     * @return string o caminho do arquivo temporário (quem chama o apaga)
     *
     * @throws DumpFailed
     */
    public function dump(): string
    {
        $config = $this->connection();
        $password = $config['password'];
        $env = ['PGPASSWORD' => $password, 'PGSSLMODE' => $config['sslmode'] ?: 'prefer'];

        $directory = storage_path('app/tmp');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/backup-'.Str::lower((string) Str::ulid()).'.dump';

        $result = Process::env($env)->timeout(self::TIMEOUT_SECONDS)->run([
            'pg_dump', '-Fc', '--compress='.config()->string('backup.compress'),
            '-h', $config['host'], '-p', $config['port'],
            '-U', $config['username'], '-d', $config['database'],
            '-f', $path,
        ]);

        try {
            $this->assertDumped($result, $path, $password);
            $this->assertReadable($path, $password);
        } catch (DumpFailed $e) {
            File::delete($path);

            throw $e;
        }

        return $path;
    }

    /**
     * A conexão ativa já com `DB_URL` resolvido (ler `config()` direto perderia o host de quem usa URL).
     *
     * @return array{host: string, port: string, database: string, username: string, password: string, sslmode: string}
     */
    private function connection(): array
    {
        $raw = DB::connection()->getConfig();
        $raw = is_array($raw) ? $raw : [];
        $value = fn (string $key): string => is_scalar($raw[$key] ?? null) ? (string) $raw[$key] : '';

        return [
            'host' => $value('host'), 'port' => $value('port'), 'database' => $value('database'),
            'username' => $value('username'), 'password' => $value('password'), 'sslmode' => $value('sslmode'),
        ];
    }

    private function assertDumped(ProcessResult $result, string $path, string $password): void
    {
        if ($result->failed()) {
            throw new DumpFailed($this->message('pg_dump falhou', $result, $password));
        }

        if (! is_file($path) || filesize($path) === 0) {
            throw new DumpFailed('pg_dump terminou sem gerar o arquivo.');
        }
    }

    private function assertReadable(string $path, string $password): void
    {
        $result = Process::timeout(self::TIMEOUT_SECONDS)->run(['pg_restore', '--list', $path]);

        if ($result->failed()) {
            throw new DumpFailed($this->message('O dump gerado não passou na conferência do pg_restore', $result, $password));
        }
    }

    private function message(string $what, ProcessResult $result, string $password): string
    {
        $error = trim($result->errorOutput());
        $error = $password === '' ? $error : str_replace($password, '***', $error);

        return sprintf('%s (código %d)%s', $what, (int) $result->exitCode(), $error === '' ? '.' : ': '.Str::limit($error, self::ERROR_LIMIT));
    }
}
