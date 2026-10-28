<?php

namespace App\Settings;

/**
 * Conexão com o WAHA: URL e API key vêm do ambiente do app (config/services.php);
 * o segredo do webhook é derivado da APP_KEY e enviado ao WAHA ao iniciar a sessão,
 * então ninguém digita nem copia nada entre containers além da API key.
 */
final class WahaSettings
{
    public static function baseUrl(): string
    {
        return rtrim(self::config('services.waha.url'), '/');
    }

    public static function apiKey(): string
    {
        return self::config('services.waha.key');
    }

    public static function webhookSecret(): string
    {
        return hash_hmac('sha256', 'waha-webhook', self::config('app.key'));
    }

    public static function webhookUrl(): string
    {
        return self::config('services.waha.webhook_url') ?: url('/webhooks/waha');
    }

    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '' && self::apiKey() !== '';
    }

    private static function config(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }
}
