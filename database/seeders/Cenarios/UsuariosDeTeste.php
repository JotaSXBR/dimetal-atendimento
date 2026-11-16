<?php

namespace Database\Seeders\Cenarios;

use App\Enums\UserRole;
use App\Models\Sector;
use App\Models\User;
use Database\Seeders\AmbienteDeTesteSeeder;
use Database\Seeders\SectorSeeder;
use Illuminate\Database\Seeder;

class UsuariosDeTeste extends Seeder
{
    public function run(): void
    {
        if (! Sector::query()->exists()) {
            $this->call(SectorSeeder::class);
        }

        $setores = Sector::query()->orderBy('menu_order')->get();

        self::usuario(AmbienteDeTesteSeeder::ADMIN_EMAIL, 'Dev Admin', UserRole::ADMIN)->sectors()->sync($setores->pluck('id'));
        self::usuario(AmbienteDeTesteSeeder::ATENDENTE_EMAIL, 'Atendente Teste', UserRole::ATENDENTE)->sectors()->sync($setores->take(2)->pluck('id'));
    }

    public static function admin(): User
    {
        return User::query()->where('email', AmbienteDeTesteSeeder::ADMIN_EMAIL)->firstOr(function (): User {
            (new self)->run();

            return User::query()->where('email', AmbienteDeTesteSeeder::ADMIN_EMAIL)->firstOrFail();
        });
    }

    private static function usuario(string $email, string $nome, UserRole $papel): User
    {
        return User::query()->updateOrCreate(['email' => $email], [
            'name' => $nome,
            'password' => AmbienteDeTesteSeeder::SENHA, // o cast `hashed` do User faz o hash
            'role' => $papel,
            'active' => true,
            'email_verified_at' => now(),
        ]);
    }
}
