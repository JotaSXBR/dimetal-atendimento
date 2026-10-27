<?php

namespace App\Enums;

enum UserRole: string
{
    case ADMIN = 'admin';
    case SUPERVISOR = 'supervisor';
    case ATENDENTE = 'atendente';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrador',
            self::SUPERVISOR => 'Supervisor',
            self::ATENDENTE => 'Atendente',
        };
    }
}
