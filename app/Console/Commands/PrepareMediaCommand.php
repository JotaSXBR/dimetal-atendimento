<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * Garante que os buckets dos discos de mídia e de backup existem (o RustFS não os cria sozinho). Idempotente: roda a cada
 * deploy. O nome "media" ficou porque o pós-deploy do Coolify já chama este comando.
 */
class PrepareMediaCommand extends Command
{
    protected $signature = 'app:prepare-media';

    protected $description = 'Cria o bucket dos discos de mídia e de backup S3 se ainda não existirem (em disco local não faz nada)';

    public function handle(): int
    {
        $media = $this->prepare(Message::mediaDisk(), 'mídia', 'AWS_BUCKET');
        $backups = $this->prepare(Storage::disk(config()->string('backup.disk')), 'backup', 'BACKUP_BUCKET');

        return $media && $backups ? self::SUCCESS : self::FAILURE;
    }

    /** `false` só quando o disco é S3 e não tem bucket configurado. */
    private function prepare(Filesystem $disk, string $label, string $variable): bool
    {
        if (! $disk instanceof AwsS3V3Adapter) {
            $this->info("Disco de {$label} local: nada a preparar.");

            return true;
        }

        $bucket = $disk->getConfig()['bucket'] ?? null;

        if (! is_string($bucket) || $bucket === '') {
            $this->error("O disco de {$label} S3 não tem bucket configurado ({$variable}).");

            return false;
        }

        $client = $disk->getClient();

        if ($client->doesBucketExistV2($bucket)) {
            $this->info("Bucket {$bucket} já existe.");

            return true;
        }

        $client->createBucket(['Bucket' => $bucket]);
        $this->info("Bucket {$bucket} criado.");

        return true;
    }
}
