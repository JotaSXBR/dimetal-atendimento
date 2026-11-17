<?php

namespace App\Services\Update;

use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\RestoreProgress;
use App\Settings\UpdateSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * As conferências antes de atualizar. Cada método devolve erros em português (lista vazia = ok) e nunca lança por falha do Coolify,
 * do GHCR ou do GitHub. Nada que muda o comportamento do Service é gravado aqui: só a variável `FLUXIE_VERSION` com a versão que já roda.
 */
final class Preflight
{
    private const VERSION_KEY = 'FLUXIE_VERSION';

    /** Sem este `Accept` o GHCR responde 404 até para uma tag que existe. */
    private const MANIFEST_ACCEPT = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json, application/vnd.oci.image.manifest.v1+json';

    public function __construct(
        private readonly Coolify $coolify,
        private readonly InstalledVersion $installed,
        private readonly RestoreProgress $restore,
        private readonly ManualBackupState $backup,
    ) {}

    /**
     * Conexão com o Coolify: compose legível (`read` e `read:sensitive`), variáveis legíveis, `deploy` e gravação de `FLUXIE_VERSION`
     * (`write`). Para no primeiro erro, porque o seguinte depende dele. A prova de `deploy` vem antes da escrita: sem a permissão, nada é gravado.
     *
     * @return list<string>
     */
    public function connection(): array
    {
        try {
            $this->readCompose();
            $envs = $this->coolify->envs();

            if (! $this->coolify->canDeploy()) {
                return ['O token não tem a permissão deploy no Coolify.'];
            }

            $this->coolify->setEnv(self::VERSION_KEY, $this->pinnedVersion($envs));
        } catch (CoolifyException $e) {
            return [$e->getMessage()];
        }

        return [];
    }

    /**
     * @param  string  $releaseCompose  o `docker-compose.yml` da Release de destino (ver {@see self::releaseCompose()})
     * @return list<string>
     */
    public function check(Version $target, string $releaseCompose): array
    {
        $errors = $this->connection();

        return $errors !== [] ? $errors : array_values(array_filter([
            $this->imageError($target),
            ...$this->composeErrors($target, $releaseCompose),
            ...$this->busyErrors(),
        ]));
    }

    /**
     * Baixa o compose da Release de destino, pelo asset que a última consulta guardou (só vale se for a do destino).
     *
     * @throws RuntimeException com a mensagem em português
     */
    public function releaseCompose(Version $target): string
    {
        $latest = UpdateSettings::latest();
        $latestVersion = $latest === null ? null : Version::parse($latest['tag']);

        if ($latest === null || $latestVersion === null || (string) $latestVersion !== (string) $target) {
            throw new RuntimeException('Consulte as versões de novo antes de atualizar.');
        }

        $body = $this->download($latest['compose_url']);

        return $body !== null ? $body : throw new RuntimeException('Não foi possível baixar o docker-compose.yml da Release '.$target.'.');
    }

    /** O compose do Coolify difere do da Release da versão que roda: as edições serão substituídas. Sem como comparar, falso. */
    public function composeEdited(): bool
    {
        $release = $this->installed->release();

        if ($release === null) {
            return false;
        }

        $repo = config()->string('fluxie.releases_repo');
        $original = $this->download("https://github.com/{$repo}/releases/download/v{$release}/docker-compose.yml");

        try {
            return $original !== null && trim($original) !== trim($this->coolify->compose());
        } catch (CoolifyException) {
            return false;
        }
    }

    /** @throws CoolifyException */
    private function readCompose(): void
    {
        try {
            $this->coolify->compose();
        } catch (CoolifyException $e) {
            // 200 sem o campo: o token lê o Service mas não o compose.
            throw $e->status === 200 ? new CoolifyException('O token não tem a permissão read:sensitive no Coolify (o compose não veio).', 200) : $e;
        }
    }

    /**
     * O valor que fica gravado: o que já prende a versão, ou (ausente ou vazio, o normal) a versão que roda.
     *
     * @param  array<string, ?string>  $envs
     */
    private function pinnedVersion(array $envs): string
    {
        $value = $envs[self::VERSION_KEY] ?? null;

        return is_string($value) && $value !== '' ? $value : $this->installed->current();
    }

    private function imageError(Version $target): ?string
    {
        $path = Str::after(config()->string('fluxie.image'), 'ghcr.io/');
        $missing = "A imagem {$target} não está publicada no GHCR.";

        try {
            $token = Http::timeout(10)->get('https://ghcr.io/token', ['scope' => "repository:{$path}:pull"])->json('token');

            if (! is_string($token) || $token === '') {
                return 'Não foi possível consultar o GHCR agora.';
            }

            $manifest = Http::withToken($token)->withHeaders(['Accept' => self::MANIFEST_ACCEPT])->timeout(10)->get("https://ghcr.io/v2/{$path}/manifests/{$target}");
        } catch (ConnectionException) {
            return 'Sem conexão com o GHCR.';
        }

        return match (true) {
            $manifest->ok() => null,
            $manifest->status() === 404 => $missing,
            default => 'Não foi possível consultar o GHCR agora (HTTP '.$manifest->status().').',
        };
    }

    /** @return list<string> */
    private function composeErrors(Version $target, string $compose): array
    {
        try {
            $parsed = Yaml::parse($compose);
        } catch (ParseException) {
            return ['O docker-compose.yml da Release não é um YAML válido.'];
        }

        $app = is_array($parsed) && is_array($parsed['services'] ?? null) ? ($parsed['services']['app'] ?? null) : null;

        if (! is_array($app)) {
            return ['O docker-compose.yml da Release não tem o serviço app.'];
        }

        $expected = config()->string('fluxie.image').':${'.self::VERSION_KEY.':-'.$target.'}';

        return ($app['image'] ?? null) === $expected ? [] : ["A imagem do serviço app no docker-compose.yml da Release não é {$expected}."];
    }

    /**
     * Restauração ou backup manual em curso (a atualização reconfere antes de gravar no Coolify).
     *
     * @return list<string>
     */
    public function busyErrors(): array
    {
        return array_values(array_filter([
            $this->restore->isRunning() ? 'Há uma restauração de backup em andamento.' : null,
            $this->backup->isRunning() ? 'Há um backup em andamento.' : null,
        ]));
    }

    private function download(string $url): ?string
    {
        try {
            $response = Http::withUserAgent('Fluxie-Inbox')->timeout(15)->get($url);
        } catch (ConnectionException) {
            return null;
        }

        return $this->body($response);
    }

    private function body(Response $response): ?string
    {
        return $response->ok() && trim($response->body()) !== '' ? $response->body() : null;
    }
}
