<?php

namespace Tests\Feature\Update;

use App\Settings\UpdateSettings;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * O mundo externo da atualização num `Http::fake` só: painel do Coolify (Service `svc123`), GHCR e GitHub. O estado das variáveis
 * acompanha as gravações (`PATCH` de chave ausente dá 404, como no Coolify), então o que o pré-voo grava é o que o aplicar lê.
 * Cada teste muda `$this->world` antes de `fakeWorld()`; um valor pode ser `[status, corpo]` ou um closure `fn (Request): ?PromiseInterface`.
 */
trait FakesUpdateWorld
{
    private const PANEL = 'https://painel.example/api/v1/services';

    private const RELEASE_URL = 'https://github.com/fluxie-tech/fluxie-inbox/releases/download/v0.2.0/docker-compose.yml';

    private const ACCEPT = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.manifest.v1+json';

    /** Compose que o Coolify tem hoje (a edição do dono vale: tem uma variável que o da Release não tem). */
    private const CURRENT_COMPOSE = "services:\n  app:\n    image: ghcr.io/fluxie-tech/fluxie-inbox:\${FLUXIE_VERSION:-0.1.0}\n    environment:\n      MINHA_EDICAO: sim\n";

    /** @var array<string, mixed> */
    private array $world = [];

    /** @var array<string, ?string> */
    private array $envs = ['APP_URL' => 'https://x.example'];

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/update/{$name}"));
    }

    /** O asset da Release `0.2.0`: o da 0.1.0 capturado de verdade, com a versão trocada como o workflow da Release faz. */
    private function releaseCompose(): string
    {
        return str_replace(':-0.1.0}', ':-0.2.0}', $this->fixture('release-0.1.0-docker-compose.yml'));
    }

    private function prepareUpdateSettings(): void
    {
        config(['fluxie.resource_uuid' => 'svc123', 'fluxie.version' => '0.1.0']);
        UpdateSettings::setPanelUrl('https://painel.example');
        UpdateSettings::setToken('7|segredo-do-token');
        UpdateSettings::setLatest(['tag' => 'v0.2.0', 'published_at' => '2026-11-17T10:00:00Z', 'notes_url' => 'https://github.com/x', 'compose_url' => self::RELEASE_URL]);
    }

    private function fakeWorld(): void
    {
        Http::fake(function (Request $request): PromiseInterface {
            $url = $request->url();
            $key = match (true) {
                str_starts_with($url, 'https://ghcr.io/token') => 'ghcr_token',
                str_starts_with($url, 'https://ghcr.io/v2/') => 'manifest',
                $url === self::RELEASE_URL => 'release_compose',
                str_ends_with($url, '/releases/download/v0.1.0/docker-compose.yml') => 'installed_compose',
                str_contains($url, '/svc123/restart') => 'restart',
                str_contains($url, '/00000000-nao-existe-0000/restart') => 'probe',
                str_ends_with($url, '/svc123/envs') => 'envs_'.strtolower($request->method()),
                str_ends_with($url, '/svc123') => 'service_'.strtolower($request->method()),
                default => 'desconhecida',
            };
            $stub = $this->world[$key] ?? $this->defaultStub($key, $request);
            $stub = $stub instanceof \Closure ? $stub($request) : $stub;

            return is_array($stub) ? Http::response($stub[1], $stub[0]) : ($stub ?? Http::response('', 404));
        });
    }

    /** @return array{0: int, 1: mixed}|PromiseInterface|null */
    private function defaultStub(string $key, Request $request): array|PromiseInterface|null
    {
        return match ($key) {
            'ghcr_token' => [200, ['token' => 'anon']],
            'manifest' => $request->hasHeader('Authorization', 'Bearer anon') && $request->header('Accept') === [self::ACCEPT]
                ? [200, $this->fixture('ghcr-manifest-0.1.0.json')]
                : [404, '{"errors":[{"code":"MANIFEST_UNKNOWN"}]}'],
            'release_compose' => [200, $this->releaseCompose()],
            'installed_compose' => [200, self::CURRENT_COMPOSE],
            'service_get' => [200, ['uuid' => 'svc123', 'docker_compose_raw' => self::CURRENT_COMPOSE]],
            'service_patch' => [200, ['message' => 'ok']],
            'envs_get' => [200, array_map(fn (string $k, ?string $v): array => ['uuid' => $k, 'key' => $k, 'value' => $v, 'is_preview' => false], array_keys($this->envs), $this->envs)],
            'envs_patch' => array_key_exists((string) $request['key'], $this->envs) ? $this->writeEnv($request, 200) : [404, ['message' => 'Environment variable not found.']],
            'envs_post' => $this->writeEnv($request, 201),
            'restart' => [200, ['message' => 'Service restarting request queued.']],
            'probe' => [404, ['message' => 'Service not found.']],
            default => null,
        };
    }

    /** @return array{0: int, 1: mixed} */
    private function writeEnv(Request $request, int $status): array
    {
        $this->envs[(string) $request['key']] = (string) $request['value'];

        return [$status, ['uuid' => 'novo']];
    }

    /** Escritas no Coolify (o sonda do `deploy` num serviço que não existe não conta). */
    private function writes(): array
    {
        return Http::recorded()->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $r): bool => str_starts_with($r->url(), self::PANEL) && in_array($r->method(), ['PATCH', 'POST'], true) && ! str_contains($r->url(), '00000000-nao-existe-0000'))
            ->values()->all();
    }

    private function assertNoCoolifyWrites(): void
    {
        $this->assertSame([], array_map(fn (Request $r): string => $r->method().' '.$r->url(), $this->writes()));
    }
}
