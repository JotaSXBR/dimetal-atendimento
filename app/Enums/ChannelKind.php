<?php

namespace App\Enums;

/** O tipo do canal. Só WhatsApp existe; o card da Fila lê o ícone daqui para outro canal entrar sem refazer o card. */
enum ChannelKind: string
{
    case WHATSAPP = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::WHATSAPP => 'WhatsApp',
        };
    }

    /** Conteúdo SVG (viewBox 24, traço) do ícone mostrado sobre o avatar. */
    public function icon(): string
    {
        return match ($this) {
            self::WHATSAPP => '<path d="M3 21l1.65-4.9A8.5 8.5 0 1 1 8 19.4z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 .8a4 4 0 0 1-1.8-1.8l.8-1-1-2z"/>',
        };
    }
}
