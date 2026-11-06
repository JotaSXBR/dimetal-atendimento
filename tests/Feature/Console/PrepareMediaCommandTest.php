<?php

namespace Tests\Feature\Console;

use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\AwsS3V3Adapter as LaravelS3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Tests\TestCase;

class PrepareMediaCommandTest extends TestCase
{
    /** Um disco S3 cujo cliente responde o que a fila do `MockHandler` mandar. */
    private function s3Disk(MockHandler $mock): void
    {
        $client = new S3Client(['region' => 'us-east-1', 'version' => 'latest', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => $mock]);
        $adapter = new AwsS3V3Adapter($client, 'dimetal-beta-media');
        Storage::set('rustfs', new LaravelS3Adapter(new Filesystem($adapter), $adapter, ['bucket' => 'dimetal-beta-media'], $client));
        config(['media.disk' => 'rustfs']);
    }

    public function test_cria_o_bucket_quando_ele_nao_existe(): void
    {
        $mock = new MockHandler([
            new S3Exception('Not Found', new Command('HeadBucket'), ['response' => new Response(404), 'code' => 'NotFound']),
            new Result([]),
        ]);
        $this->s3Disk($mock);

        $this->artisan('app:prepare-media')->expectsOutputToContain('dimetal-beta-media')->assertSuccessful();

        $this->assertCount(0, $mock, 'as duas chamadas (existe? e criar) foram feitas');
        $this->assertSame('CreateBucket', $mock->getLastCommand()?->getName());
    }

    public function test_nao_mexe_no_bucket_que_ja_existe(): void
    {
        $mock = new MockHandler([new Result([])]);
        $this->s3Disk($mock);

        $this->artisan('app:prepare-media')->assertSuccessful();

        $this->assertSame('HeadBucket', $mock->getLastCommand()?->getName());
    }

    public function test_disco_local_nao_tem_nada_a_preparar(): void
    {
        Storage::fake('media');
        config(['media.disk' => 'media']);

        $this->artisan('app:prepare-media')->expectsOutputToContain('nada a preparar')->assertSuccessful();
    }
}
