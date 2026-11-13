<?php

namespace App\Services\Waha;

use App\Settings\WahaSettings;
use Illuminate\Http\Request;

/** A assinatura HMAC SHA-512 que o WAHA põe em cada webhook. O controller e o limitador de requisições decidem a partir dela. */
final class WebhookSignature
{
    public static function valid(Request $request, ?string $secret = null): bool
    {
        $secret ??= WahaSettings::webhookSecret();

        if ($secret === '') {
            return false;
        }

        $signature = (string) $request->header('X-Webhook-Hmac', '');
        $algorithm = strtolower((string) $request->header('X-Webhook-Hmac-Algorithm', 'sha512'));

        if ($signature === '' || $algorithm !== 'sha512') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $request->getContent(), $secret), strtolower($signature));
    }
}
