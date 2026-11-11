<?php

namespace App\Enums;

/** Quem escreveu a nota interna. Só `EQUIPE` tem autor (`user_id`); a automação e, no futuro, a IA escrevem sem autor. */
enum NoteOrigin: string
{
    case EQUIPE = 'equipe';
    case BOT = 'bot';

    public function label(): string
    {
        return match ($this) {
            self::EQUIPE => 'Equipe',
            self::BOT => 'Bot',
        };
    }
}
