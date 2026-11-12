<?php

namespace App\Services\Backup\Restore;

use Carbon\CarbonImmutable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Lê o cabeçalho de um dump e confere que ele só tem o que este sistema gera. O `pg_restore` executa o SQL do arquivo como
 * superusuário, então um dump com função, gatilho ou outra extensão rodaria código no servidor de banco: a lista de permissão
 * recusa qualquer entrada fora dos tipos conhecidos (vale para o arquivo da lista e para o enviado).
 */
class DumpInspector
{
    private const LIST_TIMEOUT_SECONDS = 120;

    /** Entradas aceitas (tipo seguido do esquema `public`) e as duas únicas que não são do esquema `public`. */
    private const ALLOWED = '/^\d+; \d+ \d+ (?:TABLE DATA|TABLE|SEQUENCE SET|SEQUENCE OWNED BY|SEQUENCE|DEFAULT|FK CONSTRAINT|CONSTRAINT|INDEX) public \S.*$|^\d+; \d+ \d+ EXTENSION - pg_trgm\s*$|^\d+; \d+ \d+ COMMENT - EXTENSION pg_trgm\s*$/';

    private const NOT_OURS = 'Este arquivo não é um backup deste sistema (contém objetos que a restauração não aceita).';

    /** @throws InvalidDump */
    public function inspect(string $path, string $name): DumpInfo
    {
        $size = is_file($path) ? (int) filesize($path) : 0;

        if ($size === 0) {
            throw new InvalidDump('O arquivo está vazio.');
        }

        $toc = $this->list($path);
        self::assertSafe($toc);

        $created = $this->header($toc, 'Archive created at');
        $at = $created === null ? null : $this->parseDate($created);

        if ($at === null || $this->header($toc, 'dbname') === null) {
            throw new InvalidDump('Não foi possível ler o cabeçalho do backup.');
        }

        return new DumpInfo(
            name: $name,
            createdAt: $at,
            database: (string) $this->header($toc, 'dbname'),
            serverVersion: (string) $this->header($toc, 'Dumped from database version'),
            dumpVersion: (string) $this->header($toc, 'Dumped by pg_dump version'),
            compression: (string) $this->header($toc, 'Compression'),
            entries: (int) $this->header($toc, 'TOC Entries'),
            size: $size,
        );
    }

    /** @throws InvalidDump na primeira entrada fora da lista de permissão */
    public static function assertSafe(string $toc): void
    {
        foreach (preg_split('/\R/', $toc) ?: [] as $line) {
            $line = rtrim($line);

            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }

            if (preg_match(self::ALLOWED, $line) !== 1) {
                throw new InvalidDump(self::NOT_OURS);
            }
        }
    }

    /** @throws InvalidDump */
    private function list(string $path): string
    {
        try {
            $result = Process::timeout(self::LIST_TIMEOUT_SECONDS)->run(['pg_restore', '--list', $path]);
        } catch (ProcessTimedOutException) {
            throw new InvalidDump('A leitura do arquivo passou de 2 minutos e foi interrompida.');
        }

        if ($result->failed()) {
            throw new InvalidDump('Este arquivo não parece um backup do PostgreSQL (formato custom, `pg_dump -Fc`).');
        }

        return $result->output();
    }

    private function header(string $toc, string $key): ?string
    {
        return preg_match('/^;\s+'.preg_quote($key, '/').':?\s+(.+?)\s*$/m', $toc, $m) === 1 ? $m[1] : null;
    }

    private function parseDate(string $text): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($text, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }
}
