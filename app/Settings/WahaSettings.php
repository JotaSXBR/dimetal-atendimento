<?php

namespace App\Settings;

use App\Models\Setting;

/**
 * Configuração de conexão com o WAHA, guardada no banco.
 *
 * Os valores precisam bater com as variáveis do container WAHA (ver
 * docs/superpowers/specs/waha-config.md).
 */
final class WahaSettings
{
    public static function baseUrl(): string
    {
        return (string) Setting::get('waha.base_url', '');
    }

    public static function sessionName(): string
    {
        return (string) Setting::get('waha.session_name', '');
    }

    public static function apiKey(): string
    {
        return (string) Setting::get('waha.api_key', '');
    }

    public static function webhookSecret(): string
    {
        return (string) Setting::get('waha.webhook_secret', '');
    }

    public static function isConfigured(): bool
    {
        return filled(self::baseUrl())
            && filled(self::sessionName())
            && filled(self::apiKey())
            && filled(self::webhookSecret());
    }

    public static function save(string $baseUrl, string $sessionName, string $apiKey, string $webhookSecret): void
    {
        Setting::put('waha.base_url', rtrim($baseUrl, '/'));
        Setting::put('waha.session_name', $sessionName);
        Setting::put('waha.api_key', $apiKey, 'secret');
        Setting::put('waha.webhook_secret', $webhookSecret, 'secret');
    }
}
