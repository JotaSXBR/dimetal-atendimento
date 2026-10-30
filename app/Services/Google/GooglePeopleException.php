<?php

namespace App\Services\Google;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Falha numa chamada ao People API. `reason` é um código curto e estável (vai para `contacts.google_error`);
 * a mensagem nunca carrega token nem dado do contato.
 *
 * Códigos: unauthorized, insufficient_scope, forbidden, api_disabled, not_found, conflict, rate_limited, unavailable, bad_request, invalid_response.
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
            $status === 403 => self::forbiddenReason($error),
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            $status === 400 && $google === 'FAILED_PRECONDITION' => 'conflict',
            $status >= 500 => 'unavailable',
            default => 'bad_request',
        };

        return new self($reason, $status);
    }

    /** 403 tem causas bem diferentes: API desligada no projeto, escopo faltando (só reautorizar resolve) ou recusa só deste contato. */
    private static function forbiddenReason(mixed $error): string
    {
        $reasons = [];

        foreach (['details', 'errors'] as $list) {
            foreach (is_array($error) && is_array($error[$list] ?? null) ? $error[$list] : [] as $item) {
                $reasons[] = is_array($item) ? ($item['reason'] ?? null) : null;
            }
        }

        return match (true) {
            in_array('SERVICE_DISABLED', $reasons, true) => 'api_disabled',
            in_array('ACCESS_TOKEN_SCOPE_INSUFFICIENT', $reasons, true), in_array('insufficientPermissions', $reasons, true) => 'insufficient_scope',
            default => 'forbidden',
        };
    }
}
