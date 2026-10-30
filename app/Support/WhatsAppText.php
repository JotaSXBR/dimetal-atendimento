<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Texto do WhatsApp para HTML: `*negrito*`, `_itálico_`, `~tachado~` e ```monoespaçado```. O texto é escapado antes de qualquer
 * marcação, então o que o cliente digita nunca vira HTML.
 *
 * shortcut: só o que cabe numa linha e sem listas nem citações; subir para um parser completo se isso importar.
 */
final class WhatsAppText
{
    private const INLINE = [
        '*' => 'strong',
        '_' => 'em',
        '~' => 's',
    ];

    public static function html(string $text): HtmlString
    {
        $escaped = e($text);
        // Se o PCRE falhar (limite de backtracking), a mensagem aparece sem formatação em vez de sumir.
        $parts = preg_split('/(```.+?```)/s', $escaped, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$escaped];
        $html = '';

        foreach ($parts as $part) {
            $html .= str_starts_with($part, '```') && str_ends_with($part, '```') && strlen($part) > 6
                ? '<code>'.substr($part, 3, -3).'</code>'
                : self::inline($part);
        }

        return new HtmlString($html);
    }

    private static function inline(string $text): string
    {
        foreach (self::INLINE as $mark => $tag) {
            $quoted = preg_quote($mark, '/');
            $text = preg_replace('/(?<![\w'.$quoted.'])'.$quoted.'(?!\s)([^'.$quoted."\n]+?)(?<!\s)".$quoted.'(?![\w'.$quoted.'])/u', "<{$tag}>$1</{$tag}>", $text) ?? $text;
        }

        return $text;
    }
}
