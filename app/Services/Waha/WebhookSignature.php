<?php

namespace App\Services\Waha;

use App\Settings\WahaSettings;
use Illuminate\Http\Request;

/** A assinatura HMAC SHA-512 que o WAHA põe em cada webhook. O controller e o limitador de requisições decidem a partir dela. */
final class WebhookSignature
{
    private const CACHED = 'waha.webhook_signature_valid';

    /**
     * O limitador e o controller recebem a mesma requisição: o resultado fica guardado nela e o HMAC (proporcional ao corpo) roda
     * uma vez só. Com `$secret` explícito (teste) nada é guardado.
     */
    public static function valid(Request $request, ?string $secret = null): bool
    {
        if ($secret === null && $request->attributes->has(self::CACHED)) {
            return (bool) $request->attributes->get(self::CACHED);
        }

        $valid = self::check($request, $secret ?? WahaSettings::webhookSecret());

        if ($secret === null) {
            $request->attributes->set(self::CACHED, $valid);
        }

        return $valid;
    }

    private static function check(Request $request, string $secret): bool
    {
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
