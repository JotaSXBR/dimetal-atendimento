<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança que o servidor web não envia. HSTS, `X-Frame-Options` e `nosniff` já vêm do nginx da imagem.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(self), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        $header = match (config('security.csp_mode')) {
            'enforce' => 'Content-Security-Policy',
            'report-only' => 'Content-Security-Policy-Report-Only',
            default => null,
        };

        if ($header !== null) {
            $response->headers->set($header, $this->policy($request));
        }

        return $response;
    }

    private function policy(Request $request): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            // Flux e Livewire usam estilos inline (atributo `style`); o risco de XSS por estilo é bem menor que o de script.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            'connect-src '.implode(' ', ["'self'", ...$this->websocketOrigins($request)]),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }

    /**
     * O websocket do Reverb: o mesmo domínio do app (sai por `/app` no proxy) ou o endereço de desenvolvimento.
     *
     * @return list<string>
     */
    private function websocketOrigins(Request $request): array
    {
        $public = config('broadcasting.connections.reverb.public_url');
        $url = is_string($public) && $public !== '' ? $public : $request->getSchemeAndHttpHost();

        $parts = parse_url($url);
        $host = $parts['host'] ?? $request->getHost();
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $scheme = ($parts['scheme'] ?? $request->getScheme()) === 'https' ? 'wss' : 'ws';

        return ["{$scheme}://{$host}{$port}"];
    }
}
