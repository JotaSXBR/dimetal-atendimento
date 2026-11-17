<?php

namespace App\Services\Update;

use App\Settings\UpdateSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Cliente mínimo da API v1 do Coolify para atualizar o Service desta instalação. Nada é apagado: não há DELETE aqui. */
final class Coolify
{
    /** UUID que nunca existe: a resposta (404 ou 403) prova a permissão `deploy` sem mexer em nada. */
    private const MISSING_UUID = '00000000-nao-existe-0000';

    public function compose(): string
    {
        $compose = $this->send('GET', '')->json('docker_compose_raw');

        return is_string($compose) && $compose !== '' ? $compose : throw new CoolifyException('A resposta do Coolify não trouxe o compose do serviço.', 200);
    }

    /** @return array<string, ?string> Só as variáveis reais (`is_preview` falso); chave presente com valor vazio ou nulo continua presente. */
    public function envs(): array
    {
        $envs = [];

        foreach ((array) $this->send('GET', '/envs')->json() as $env) {
            if (is_array($env) && is_string($env['key'] ?? null) && ($env['is_preview'] ?? false) === false) {
                $envs[$env['key']] = is_string($env['value'] ?? null) ? $env['value'] : null;
            }
        }

        return $envs;
    }

    public function setEnv(string $key, string $value): void
    {
        $body = ['key' => $key, 'value' => $value, 'is_preview' => false];
        $response = $this->request('PATCH', '/envs', $body);

        if ($response->status() === 404) {
            $response = $this->request('POST', '/envs', $body);
        }

        $this->ensureOk($response);
    }

    public function putCompose(string $raw): void
    {
        $this->send('PATCH', '', ['docker_compose_raw' => base64_encode($raw)]);
    }

    public function restart(bool $pull): void
    {
        $this->send('POST', '/restart'.($pull ? '?latest=true' : ''));
    }

    /** 404 no restart de um serviço inexistente = tem `deploy`; 403 = não tem; o resto é falha de verdade. */
    public function canDeploy(): bool
    {
        $response = $this->request('POST', '/restart', uuid: self::MISSING_UUID);

        return match ($response->status()) {
            404 => true,
            403 => false,
            default => throw CoolifyException::fromResponse($response->status(), $response->json()),
        };
    }

    /** @param  array<string, mixed>  $body */
    private function send(string $method, string $path, array $body = []): Response
    {
        return $this->ensureOk($this->request($method, $path, $body));
    }

    private function ensureOk(Response $response): Response
    {
        return $response->successful() ? $response : throw CoolifyException::fromResponse($response->status(), $response->json());
    }

    /** @param  array<string, mixed>  $body */
    private function request(string $method, string $path, array $body = [], ?string $uuid = null): Response
    {
        $panel = UpdateSettings::panelUrl();
        $token = UpdateSettings::token();
        $uuid ??= config('fluxie.resource_uuid');

        match (true) {
            $panel === null => throw new CoolifyException('Falta a URL do painel do Coolify.'),
            $token === null => throw new CoolifyException('Falta o token do Coolify.'),
            ! is_string($uuid) || $uuid === '' => throw new CoolifyException('Falta o identificador do serviço no Coolify (COOLIFY_RESOURCE_UUID).'),
            default => null,
        };

        $url = rtrim($panel, '/')."/api/v1/services/{$uuid}{$path}";

        try {
            return Http::withToken($token)->acceptJson()->asJson()->connectTimeout(5)->timeout(30)
                ->send($method, $url, $body === [] ? [] : ['json' => $body]);
        } catch (ConnectionException) {
            // Sem `previous`: a mensagem do cURL pode trazer o cabeçalho com o token.
            throw new CoolifyException('Sem conexão com o painel do Coolify.');
        }
    }
}
