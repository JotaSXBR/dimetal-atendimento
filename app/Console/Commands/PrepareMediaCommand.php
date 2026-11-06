<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;

/** Garante que o bucket do disco de mídia existe (o RustFS não o cria sozinho). Idempotente: roda a cada deploy. */
class PrepareMediaCommand extends Command
{
    protected $signature = 'app:prepare-media';

    protected $description = 'Cria o bucket do disco de mídia S3 se ele ainda não existir (no disco local não faz nada)';

    public function handle(): int
    {
        $disk = Message::mediaDisk();

        if (! $disk instanceof AwsS3V3Adapter) {
            $this->info('Disco de mídia local: nada a preparar.');

            return self::SUCCESS;
        }

        $client = $disk->getClient();
        $bucket = $disk->getConfig()['bucket'] ?? null;

        if (! is_string($bucket) || $bucket === '') {
            $this->error('O disco de mídia S3 não tem bucket configurado (AWS_BUCKET).');

            return self::FAILURE;
        }

        if ($client->doesBucketExistV2($bucket)) {
            $this->info("Bucket {$bucket} já existe.");

            return self::SUCCESS;
        }

        $client->createBucket(['Bucket' => $bucket]);
        $this->info("Bucket {$bucket} criado.");

        return self::SUCCESS;
    }
}
