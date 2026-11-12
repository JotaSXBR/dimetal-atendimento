<?php

namespace App\Services\Backup\Restore;

use App\Services\Backup\BackupCatalog;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * De onde vem o arquivo a restaurar: um backup da lista (`bucket:<nome>`) ou um arquivo enviado (`upload:<ulid>`, guardado no
 * bucket em `restauracao/`). Entrega uma cópia temporária local para o `pg_restore` e apaga tudo no fim.
 */
class RestoreSource
{
    private const UPLOAD_ID = '/^[0-9a-z]{26}$/';

    private const ORPHAN_AGE_HOURS = 24;

    public function __construct(private readonly BackupCatalog $catalog) {}

    /** Guarda no bucket um arquivo enviado (por stream) e devolve a origem `upload:<ulid>`. */
    public function storeUpload(string $localPath): string
    {
        $id = Str::lower((string) Str::ulid());
        $stream = fopen($localPath, 'rb');

        if ($stream === false || ! $this->catalog->disk()->writeStream($this->uploadKey($id), $stream)) {
            throw new InvalidDump('Não foi possível guardar o arquivo enviado.');
        }

        fclose($stream);

        return 'upload:'.$id;
    }

    /**
     * @return array{path: string, name: string} o caminho da cópia temporária e o nome para mostrar
     *
     * @throws InvalidDump
     */
    public function fetch(string $source): array
    {
        [$key, $name] = $this->resolve($source);
        $stream = $this->catalog->disk()->readStream($key);

        if ($stream === null) {
            throw new InvalidDump('Não foi possível ler o arquivo no bucket.');
        }

        $directory = config()->string('backup.tmp_path');
        File::ensureDirectoryExists($directory);
        $this->sweepOrphans($directory);
        $path = $directory.'/restore-'.Str::lower((string) Str::ulid()).'.dump';
        $target = fopen($path, 'wb');

        if ($target === false) {
            throw new InvalidDump('Não foi possível preparar o arquivo temporário.');
        }

        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);

        return ['path' => $path, 'name' => $name];
    }

    /** Apaga só a cópia temporária local (o arquivo enviado continua no bucket para a restauração). */
    public function discardTemporary(string $path): void
    {
        File::delete($path);
    }

    /** Apaga a cópia temporária e, se for um envio, o arquivo no bucket. Nunca falha: é limpeza. */
    public function discard(string $source, ?string $path = null): void
    {
        $path === null || $this->discardTemporary($path);

        if (str_starts_with($source, 'upload:') && preg_match(self::UPLOAD_ID, substr($source, 7)) === 1) {
            $this->catalog->disk()->delete($this->uploadKey(substr($source, 7)));
        }
    }

    /**
     * @return array{0: string, 1: string} a chave no bucket e o nome para mostrar
     *
     * @throws InvalidDump
     */
    private function resolve(string $source): array
    {
        if (str_starts_with($source, 'bucket:')) {
            $file = $this->catalog->find(substr($source, 7));

            return $file === null ? throw new InvalidDump('Esse backup não existe mais no bucket.') : [$file->name, $file->name];
        }

        if (str_starts_with($source, 'upload:') && preg_match(self::UPLOAD_ID, substr($source, 7)) === 1) {
            $key = $this->uploadKey(substr($source, 7));

            return $this->catalog->disk()->exists($key) ? [$key, 'arquivo enviado'] : throw new InvalidDump('O arquivo enviado não está mais disponível. Envie de novo.');
        }

        throw new InvalidDump('Origem do arquivo inválida.');
    }

    private function uploadKey(string $id): string
    {
        return 'restauracao/'.$id.'.dump';
    }

    private function sweepOrphans(string $directory): void
    {
        $limit = now()->subHours(self::ORPHAN_AGE_HOURS)->getTimestamp();

        foreach (glob($directory.'/restore-*.dump') ?: [] as $orphan) {
            if ((filemtime($orphan) ?: $limit) < $limit) {
                File::delete($orphan);
            }
        }
    }
}
