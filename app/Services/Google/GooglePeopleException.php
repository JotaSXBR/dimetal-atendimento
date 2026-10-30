<?php

namespace App\Services\Google;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Falha numa chamada ao People API. `reason` é um código curto e estável (vai para `contacts.google_error`);
 * a mensagem nunca carrega token nem dado do contato.
 *
 * Códigos: unauthorized, forbidden, api_disabled, not_found, conflict, rate_limited, unavailable, bad_request, invalid_response.
 */
class GooglePeopleException extends RuntimeException
{
    public function __construct(public readonly string $reason, ?int $httpStatus = null)
    {
        parent::__construct('People API: '.$reason.($httpStatus === null ? '' : ' (HTTP '.$httpStatus.')'));
    }

    public static function fromResponse(Response $response): self
    {
        $status = $response->status();
        $error = $response->json('error');
        $google = is_array($error) && is_string($error['status'] ?? null) ? $error['status'] : '';

        $reason = match (true) {
            $status === 401 => 'unauthorized',
            $status === 403 => self::isServiceDisabled($error) ? 'api_disabled' : 'forbidden',
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            $status === 400 && $google === 'FAILED_PRECONDITION' => 'conflict',
            $status >= 500 => 'unavailable',
            default => 'bad_request',
        };

        return new self($reason, $status);
    }

    private static function isServiceDisabled(mixed $error): bool
    {
        foreach (is_array($error) && is_array($error['details'] ?? null) ? $error['details'] : [] as $detail) {
            if (is_array($detail) && ($detail['reason'] ?? null) === 'SERVICE_DISABLED') {
                return true;
            }
        }

        return false;
    }
}
