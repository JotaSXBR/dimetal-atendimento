<?php

namespace App\Support;

/**
 * Telefone do WhatsApp para leitura humana e de volta para o id do chat.
 *
 * shortcut: sem biblioteca de telefone; só o Brasil (DDI 55) é formatado, os demais países saem como `+dígitos`.
 * Subir para libphonenumber quando entrarem clientes de outros países em volume.
 */
final class PhoneFormat
{
    public const HIDDEN = 'Número oculto pelo WhatsApp';

    public static function display(?string $waId, ?string $lid = null): string
    {
        $digits = $waId === null ? '' : preg_replace('/\D/', '', explode('@', $waId)[0]);

        if ($digits === null || $digits === '') {
            return self::HIDDEN;
        }

        if (str_starts_with($digits, '55') && strlen($digits) === 13) {
            return sprintf('(%s) %s-%s', substr($digits, 2, 2), substr($digits, 4, 5), substr($digits, 9));
        }

        if (str_starts_with($digits, '55') && strlen($digits) === 12) {
            return sprintf('(%s) %s-%s', substr($digits, 2, 2), substr($digits, 4, 4), substr($digits, 8));
        }

        return '+'.$digits;
    }

    /**
     * Número digitado pela pessoa vira o id do chat. Sem `+`, 10 ou 11 dígitos são tratados como nacionais (DDD + número).
     */
    public static function toWaId(string $input): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $input);
        $international = str_starts_with(trim($input), '+');

        if (! $international && in_array(strlen($digits), [10, 11], true)) {
            $digits = '55'.$digits;
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits.'@c.us' : null;
    }
}
