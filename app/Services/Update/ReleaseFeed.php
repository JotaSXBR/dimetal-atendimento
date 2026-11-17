<?php

namespace App\Services\Update;

use App\Notifications\NewVersionAvailable;
use App\Services\Alerts\AlertRecipients;
use App\Settings\UpdateSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/** Consulta as Releases do GitHub (sem autenticação, sem dado da instalação), guarda a mais nova e avisa os admins uma vez por versão. */
final class ReleaseFeed
{
    private const COMPOSE_ASSET = 'docker-compose.yml';

    public function __construct(private readonly InstalledVersion $installed, private readonly AlertRecipients $recipients) {}

    /** Nunca lança: falha vira `lastCheck.ok = false` com uma mensagem curta. */
    public function refresh(): void
    {
        try {
            $latest = $this->fetch();
        } catch (ConnectionException) {
            UpdateSettings::recordCheck(false, 'Sem conexão com o GitHub.');

            return;
        } catch (Throwable $e) {
            UpdateSettings::recordCheck(false, $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível consultar as versões.');

            return;
        }

        UpdateSettings::recordCheck(true);

        if ($latest !== null) {
            UpdateSettings::setLatest($latest['info']);
            $this->notify($latest['version']);
        }
    }

    /** @return array{version: Version, info: array{tag: string, published_at: string, notes_url: string, compose_url: string}}|null */
    private function fetch(): ?array
    {
        $repo = config()->string('fluxie.releases_repo');
        $prerelease = (bool) config('fluxie.update_prerelease');
        $url = "https://api.github.com/repos/{$repo}/releases".($prerelease ? '?per_page=30' : '/latest');

        $response = Http::withUserAgent('Fluxie-Inbox')->acceptJson()->timeout(10)->get($url);
        $body = $response->json();

        if (! $response->ok() || ! is_array($body)) {
            throw new RuntimeException($response->ok() ? 'Resposta inválida do GitHub.' : 'O GitHub respondeu com erro ('.$response->status().').');
        }

        return $this->highest($prerelease ? $body : [$body]);
    }

    /**
     * @param  array<mixed>  $releases
     * @return array{version: Version, info: array{tag: string, published_at: string, notes_url: string, compose_url: string}}|null
     */
    private function highest(array $releases): ?array
    {
        $best = null;

        foreach ($releases as $release) {
            $candidate = is_array($release) ? $this->candidate($release) : null;

            if ($candidate !== null && ($best === null || $candidate['version']->greaterThan($best['version']))) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  array<mixed>  $release
     * @return array{version: Version, info: array{tag: string, published_at: string, notes_url: string, compose_url: string}}|null
     */
    private function candidate(array $release): ?array
    {
        $tag = $release['tag_name'] ?? null;
        $version = is_string($tag) ? Version::parse($tag) : null;
        $compose = $this->composeUrl($release['assets'] ?? null);

        if ($version === null || $compose === null || ($release['draft'] ?? false) === true) {
            return null;
        }

        return ['version' => $version, 'info' => [
            'tag' => (string) $tag,
            'published_at' => is_string($release['published_at'] ?? null) ? $release['published_at'] : '',
            'notes_url' => is_string($release['html_url'] ?? null) ? $release['html_url'] : '',
            'compose_url' => $compose,
        ]];
    }

    private function composeUrl(mixed $assets): ?string
    {
        foreach (is_array($assets) ? $assets : [] as $asset) {
            if (is_array($asset) && ($asset['name'] ?? null) === self::COMPOSE_ASSET && is_string($asset['browser_download_url'] ?? null)) {
                return $asset['browser_download_url'];
            }
        }

        return null;
    }

    private function notify(Version $latest): void
    {
        $installed = $this->installed->release();

        if ($installed === null || ! $latest->greaterThan($installed) || UpdateSettings::notifiedVersion() === (string) $latest) {
            return;
        }

        try {
            Notification::send($this->recipients->admins(), new NewVersionAvailable((string) $latest));
        } catch (Throwable $e) {
            report($e); // sem marcar como avisada: a próxima consulta tenta de novo

            return;
        }

        UpdateSettings::markNotified((string) $latest);
    }
}
