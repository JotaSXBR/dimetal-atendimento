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

    public static function display(?string $waId): string
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

        if (! $international) {
            // Zero de tronco ("011 98765-4321") não faz parte do número.
            $digits = ltrim($digits, '0');
        }

        if (! $international && in_array(strlen($digits), [10, 11], true)) {
            $digits = '55'.$digits;
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits.'@c.us' : null;
    }

    /**
     * Ids de chat do mesmo celular brasileiro, com e sem o nono dígito: o WhatsApp ainda registra muitas linhas antigas
     * sem o 9, e quem digita o número completo não deve criar um segundo contato.
     *
     * @return list<string>
     */
    public static function variants(string $waId): array
    {
        $digits = explode('@', $waId)[0];
        $variants = [$waId];

        if (preg_match('/^55(\d{2})9(\d{8})$/', $digits, $m) === 1) {
            $variants[] = '55'.$m[1].$m[2].'@c.us';
        } elseif (preg_match('/^55(\d{2})([6-9]\d{7})$/', $digits, $m) === 1) {
            $variants[] = '55'.$m[1].'9'.$m[2].'@c.us';
        }

        return $variants;
    }
}
