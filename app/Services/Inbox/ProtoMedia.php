<?php

namespace App\Services\Inbox;

/** O que o proto cru do GOWS (`_data.Message`) diz sobre uma mídia quando o evento normalizado do WAHA não diz. */
final class ProtoMedia
{
    /**
     * O tamanho do arquivo (`fileLength`, número ou texto, em qualquer tipo de mídia). A mensagem citada (`contextInfo`) tem o
     * tamanho dela, não o desta.
     */
    public static function fileLength(mixed $node, int $depth = 0): ?int
    {
        if (! is_array($node) || $depth > 4) {
            return null;
        }

        foreach (['fileLength', 'FileLength'] as $key) {
            $length = $node[$key] ?? null;

            if (is_numeric($length) && (int) $length > 0) {
                return (int) $length;
            }
        }

        foreach ($node as $key => $child) {
            $found = in_array($key, ['contextInfo', 'ContextInfo'], true) ? null : self::fileLength($child, $depth + 1);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
