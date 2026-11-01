<?php

namespace App\Services\Waha;

use App\Models\Channel;
use App\Services\Alerts\AlertDispatcher;

/**
 * O número do canal vem do WAHA (`me.id` na sessão e no webhook `session.status`), nunca de digitação. Um único ponto o
 * grava, para o poll da tela Canais e o webhook não divergirem. Número vazio não apaga o gravado; troca real guarda o
 * anterior e avisa.
 */
class ChannelPhone
{
    public function __construct(private readonly AlertDispatcher $alerts) {}

    /** Só os dígitos do telefone, ou `null` se o id não for de telefone (vazio ou `@lid`). */
    public static function digits(?string $meId): ?string
    {
        if ($meId === null || str_ends_with($meId, '@lid')) {
            return null;
        }

        // `5516…:12@s.whatsapp.net` traz o dispositivo depois dos dois pontos.
        $user = explode(':', explode('@', $meId)[0])[0];
        $digits = (string) preg_replace('/\D/', '', $user);

        return $digits === '' ? null : $digits;
    }

    public function sync(Channel $channel, ?string $meId): void
    {
        $digits = self::digits($meId);

        if ($digits === null) {
            return;
        }

        $current = $channel->phone === null ? null : (string) preg_replace('/\D/', '', $channel->phone);

        if ($current === $digits) {
            // Mesmo número gravado de outro jeito (formatado): só normaliza, sem avisar.
            if ($channel->phone !== $digits) {
                $channel->update(['phone' => $digits]);
            }

            return;
        }

        if ($current === null || $current === '') {
            $channel->update(['phone' => $digits]);

            return;
        }

        $channel->update(['phone' => $digits, 'previous_phone' => $current, 'phone_changed_at' => now()]);
        $this->alerts->numberChanged($channel);
    }
}
