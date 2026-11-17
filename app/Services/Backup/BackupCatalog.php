<?php

namespace App\Services\Backup;

use App\Services\Update\InstalledVersion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;

/** O que há no bucket de backup: nomes, listagem e rotação. Só arquivos com o nome do padrão existem para o catálogo. */
class BackupCatalog
{
    // `dimetal-` é o prefixo antigo: só leitura, nunca mais gravado. O sufixo `-v<versão>` (semver, sem barra) só existe nos feitos
    // numa Release; os antigos e os de desenvolvimento não têm.
    private const PATTERN = '/^(?:dimetal|fluxie)-(\d{4}-\d{2}-\d{2})-(\d{4})-(diario|semanal|manual|antes)(?:-v(\d+\.\d+\.\d+(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?))?\.dump\z/';

    public function __construct(private readonly InstalledVersion $installed = new InstalledVersion) {}

    public function disk(): Filesystem
    {
        return Storage::disk(config()->string('backup.disk'));
    }

    /** `fluxie-AAAA-MM-DD-HHMM-<tipo>[-v<versão>].dump`, no fuso de exibição; a versão só numa Release. */
    public function name(BackupKind $kind, CarbonImmutable $at): string
    {
        $version = $this->installed->release();

        return 'fluxie-'.$at->setTimezone(config()->string('app.display_timezone'))->format('Y-m-d-Hi').'-'.$kind->value
            .($version === null ? '' : '-v'.$version).'.dump';
    }

    /** A versão em que o backup foi feito, se o nome a traz. */
    public static function versionIn(string $name): ?string
    {
        return preg_match(self::PATTERN, $name, $m) === 1 ? (($m[4] ?? '') ?: null) : null;
    }

    /** @return list<BackupFile> do mais novo ao mais velho */
    public function all(): array
    {
        $files = [];

        $disk = $this->disk();
        assert($disk instanceof FilesystemAdapter);

        // O ListObjectsV2 do S3 já traz o tamanho: uma requisição para a lista inteira, não uma por arquivo.
        foreach ($disk->getDriver()->listContents('', false) as $item) {
            if ($item instanceof FileAttributes && ($file = $this->parse($item->path(), $item->fileSize())) !== null) {
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

    private function parse(string $path, ?int $size): ?BackupFile
    {
        if (preg_match(self::PATTERN, $path, $m) !== 1) {
            return null;
        }

        $createdAt = CarbonImmutable::createFromFormat('Y-m-d Hi', $m[1].' '.$m[2], config()->string('app.display_timezone'));

        return $createdAt === null ? null : new BackupFile($path, BackupKind::from($m[3]), $createdAt, $size ?? $this->disk()->size($path), self::versionIn($path));
    }
}
