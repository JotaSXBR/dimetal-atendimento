<?php

namespace App\Services\Inbox;

use App\Models\Sector;
use App\Models\User;
use App\Settings\CompanySettings;

/**
 * A assinatura do atendente nas mensagens do painel (como no Chatwoot): `*Jessé @ Dimetal:*`, linha em branco e o texto.
 * Só vale com a assinatura ligada na empresa e por quem envia. A mensagem é gravada já assinada: o eco `fromMe` do WhatsApp
 * traz o mesmo texto e casa com a linha.
 */
final class Signature
{
    public static function apply(string $text, User $author, ?Sector $sector): string
    {
        if ($text === '' || ! CompanySettings::signatureEnabled() || ! $author->sign_messages) {
            return $text;
        }

        $signature = self::render(CompanySettings::signatureTemplate(), $author->name, $sector->name ?? '', CompanySettings::name());

        if ($signature === '') {
            return $text;
        }

        return self::place($text, $signature, CompanySettings::signaturePosition());
    }

    /** O texto com a assinatura no início ou no fim, separados por uma linha em branco. */
    public static function place(string $text, string $signature, string $position): string
    {
        return $position === 'fim' ? $text."\n\n".$signature : $signature."\n\n".$text;
    }

    public static function render(string $template, string $agent, string $sector, string $company): string
    {
        return trim(strtr($template, ['{{atendente}}' => $agent, '{{setor}}' => $sector, '{{empresa}}' => $company]));
    }
}
