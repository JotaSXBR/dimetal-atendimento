<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/** O que há no bucket de backup: nomes, listagem e rotação. Só arquivos com o nome do padrão existem para o catálogo. */
class BackupCatalog
{
    private const PATTERN = '/^dimetal-(\d{4}-\d{2}-\d{2})-(\d{4})-(diario|semanal|manual|antes)\.dump$/';

    public function disk(): Filesystem
    {
        return Storage::disk(config()->string('backup.disk'));
    }

    /** `dimetal-AAAA-MM-DD-HHMM-<tipo>.dump`, no fuso de exibição. */
    public function name(BackupKind $kind, CarbonImmutable $at): string
    {
        return 'dimetal-'.$at->setTimezone(config()->string('app.display_timezone'))->format('Y-m-d-Hi').'-'.$kind->value.'.dump';
    }

    /** @return list<BackupFile> do mais novo ao mais velho */
    public function all(): array
    {
        $files = [];

        foreach ($this->disk()->files() as $path) {
            $file = $this->parse($path);

            if ($file !== null) {
                $files[] = $file;
            }
        }

        usort($files, fn (BackupFile $a, BackupFile $b): int => [$b->createdAt, $b->name] <=> [$a->createdAt, $a->name]);

        return $files;
    }

    /** O arquivo só se o nome é um da lista (nunca devolve caminho montado a partir de texto de fora). */
    public function find(string $name): ?BackupFile
    {
        foreach ($this->all() as $file) {
            if ($file->name === $name) {
                return $file;
            }
        }

        return null;
    }

    public function totalBytes(): int
    {
        return array_sum(array_map(fn (BackupFile $file): int => $file->size, $this->all()));
    }

    /** Apaga o que passa da retenção de cada tipo (`config('backup.keep')`) e devolve quantos apagou. */
    public function prune(): int
    {
        $deleted = 0;
        $seen = [];

        foreach ($this->all() as $file) {
            $seen[$file->kind->value] = ($seen[$file->kind->value] ?? 0) + 1;

            if ($seen[$file->kind->value] > $this->keep($file->kind)) {
                $this->disk()->delete($file->name);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function keep(BackupKind $kind): int
    {
        $keep = config('backup.keep');

        return is_array($keep) && is_int($keep[$kind->value] ?? null) ? $keep[$kind->value] : 0;
    }

    private function parse(string $path): ?BackupFile
    {
        if (preg_match(self::PATTERN, $path, $m) !== 1) {
            return null;
        }

        $createdAt = CarbonImmutable::createFromFormat('Y-m-d Hi', $m[1].' '.$m[2], config()->string('app.display_timezone'));

        return $createdAt === null ? null : new BackupFile($path, BackupKind::from($m[3]), $createdAt, $this->disk()->size($path));
    }
}
