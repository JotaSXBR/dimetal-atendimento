<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No CI o cache é o Redis e sobrevive entre os testes: o bloqueio de um job único (`ShouldBeUnique*`) deixado por
        // um teste, que usa `Queue::fake()` e nunca processa o job, impediria o dispatch do mesmo id em outro teste.
        // Os bloqueios ficam na conexão de locks, que o `Cache::flush()` não alcança.
        Cache::flush();

        if (config('cache.default') === 'redis') {
            Redis::connection(config()->string('cache.stores.redis.lock_connection'))->flushdb();
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
