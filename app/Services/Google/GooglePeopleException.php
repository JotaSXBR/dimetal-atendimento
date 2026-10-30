<?php

namespace App\Services\Google;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Falha numa chamada ao People API. `reason` é um código curto e estável (vai para `contacts.google_error`);
 * a mensagem nunca carrega token nem dado do contato. `detail` repete o que o Google respondeu (status, mensagem e os campos
 * recusados) e pode citar o valor recusado: vai só para o log, nunca para a tela nem para o banco.
 *
 * Códigos: unauthorized, insufficient_scope, forbidden, api_disabled, not_found, conflict, rate_limited, unavailable, bad_request, invalid_response.
 */
class GooglePeopleException extends RuntimeException
{
    /** O Google pode responder com um texto enorme; o log guarda só o começo. */
    private const DETAIL_LIMIT = 500;

    public function __construct(public readonly string $reason, public readonly ?int $httpStatus = null, public readonly ?string $detail = null)
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

        return new self($reason, $status, self::detailOf($error));
    }

    /** `STATUS: mensagem [campos: caminho (descrição); ...]`, ou `null` quando o Google não explicou. */
    private static function detailOf(mixed $error): ?string
    {
        if (! is_array($error)) {
            return null;
        }

        $head = implode(': ', array_filter([$error['status'] ?? null, $error['message'] ?? null], is_string(...)));
        $fields = self::violatedFields($error);
        $text = $head.($fields === [] ? '' : ' [campos: '.implode('; ', $fields).']');

        return $text === '' ? null : mb_substr($text, 0, self::DETAIL_LIMIT);
    }

    /**
     * @param  array<mixed>  $error
     * @return list<string>
     */
    private static function violatedFields(array $error): array
    {
        $fields = [];

        foreach (is_array($error['details'] ?? null) ? $error['details'] : [] as $item) {
            foreach (is_array($item) && is_array($item['fieldViolations'] ?? null) ? $item['fieldViolations'] : [] as $violation) {
                if (is_array($violation) && is_string($violation['field'] ?? null)) {
                    $fields[] = $violation['field'].(is_string($violation['description'] ?? null) ? ' ('.$violation['description'].')' : '');
                }
            }
        }

        return $fields;
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
