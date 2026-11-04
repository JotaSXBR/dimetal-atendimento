<?php

namespace App\Services\Waha;

/** Leitura dos ids de chat que o WAHA devolve, nos dois formatos que o app recebe (webhook e listagem). */
final class WahaChat
{
    /** Grupo, status, lista de transmissão e newsletter nunca viram ticket. Aceita também um id serializado (`false_{chat}_{id}`). */
    public static function isIgnored(string $chat): bool
    {
        return str_contains($chat, '@g.us') || str_contains($chat, '@broadcast') || str_contains($chat, '@newsletter');
    }

    /**
     * O cliente de uma mensagem enviada por nós. O webhook o traz em `to`; a listagem do WAHA (`chats/all/messages`) traz
     * `to: null` e o chat em `_data.Info.Chat` (com `@s.whatsapp.net`, que o resto do app escreve `@c.us`).
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function sentTo(array $data): ?string
    {
        $to = $data['to'] ?? null;

        if (is_string($to)) {
            return $to;
        }

        $chat = data_get($data, '_data.Info.Chat');

        return is_string($chat) ? str_replace('@s.whatsapp.net', '@c.us', $chat) : null;
    }
}
