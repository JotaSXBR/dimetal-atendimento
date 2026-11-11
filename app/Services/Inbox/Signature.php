<?php

namespace App\Services\Inbox;

use App\Models\Sector;
use App\Models\User;
use App\Settings\CompanySettings;

/**
 * A assinatura do atendente nas mensagens do painel (como no Chatwoot): `*Jessé @ Dimetal:*` e o texto na linha de baixo.
 * Vale sempre que a empresa liga a assinatura (não há escolha por atendente). A mensagem é gravada já assinada: o eco `fromMe` do WhatsApp
 * traz o mesmo texto e casa com a linha.
 */
final class Signature
{
    public static function apply(string $text, User $author, ?Sector $sector): string
    {
        if ($text === '' || ! CompanySettings::signatureEnabled()) {
            return $text;
        }

        $signature = self::render(CompanySettings::signatureTemplate(), $author->name, $sector->name ?? '', CompanySettings::name());

        if ($signature === '') {
            return $text;
        }

        return self::place($text, $signature, CompanySettings::signaturePosition());
    }

    /** O texto com a assinatura no início ou no fim, na linha de cima ou de baixo, sem linha em branco no meio. */
    public static function place(string $text, string $signature, string $position): string
    {
        return $position === 'fim' ? $text."\n".$signature : $signature."\n".$text;
    }

    public static function render(string $template, string $agent, string $sector, string $company): string
    {
        // O WhatsApp não tem escape: `*`, `_`, `~` ou crase dentro de um nome fechariam o negrito do modelo no lugar errado.
        $plain = fn (string $value): string => str_replace(['*', '_', '~', '`'], '', $value);

        return trim(strtr($template, ['{{atendente}}' => $plain($agent), '{{setor}}' => $plain($sector), '{{empresa}}' => $plain($company)]));
    }
}
