<?php

namespace Tests;

use App\Models\Setting;
use App\Services\Setup\SetupState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Em paralelo, cada processo do paratest usa o próprio banco do Redis: o flushdb abaixo não apaga o dos outros.
        $token = getenv('TEST_TOKEN');

        if ($token !== false && $token !== '') {
            foreach (['REDIS_DB', 'REDIS_CACHE_DB'] as $key) {
                putenv("{$key}={$token}");
                $_ENV[$key] = $_SERVER[$key] = $token;
            }
        }

        parent::setUp();

        // No CI o cache é o Redis e sobrevive entre os testes: o bloqueio de um job único (`ShouldBeUnique*`) deixado por
        // um teste, que usa `Queue::fake()` e nunca processa o job, impediria o dispatch do mesmo id em outro teste.
        // Os bloqueios ficam na conexão de locks, que o `Cache::flush()` não alcança.
        Cache::flush();

        if (config('cache.default') === 'redis') {
            Redis::connection(config()->string('cache.stores.redis.lock_connection'))->flushdb();
        }

        // Instalação "já configurada": só os testes do assistente partem do banco novo (`startFreshInstall`).
        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            Setting::put(SetupState::CONCLUDED, true, 'bool');
        }
    }

    /** Banco novo: sem a marca de instalação concluída (o `concluded()` guarda `true` no cache para sempre). */
    protected function startFreshInstall(): void
    {
        Setting::query()->where('key', SetupState::CONCLUDED)->delete();
        Cache::forget(SetupState::CONCLUDED);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
