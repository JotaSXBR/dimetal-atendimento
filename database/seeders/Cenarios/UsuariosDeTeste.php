<?php

namespace Database\Seeders\Cenarios;

use App\Enums\SectorStatus;
use App\Enums\UserRole;
use App\Models\Sector;
use App\Models\User;
use App\Services\Setup\DefaultSectors;
use Database\Seeders\AmbienteDeTesteSeeder;
use Database\Seeders\SoRodaEmAmbienteDeTeste;
use Illuminate\Database\Seeder;

class UsuariosDeTeste extends Seeder
{
    use SoRodaEmAmbienteDeTeste;

    public function run(): void
    {
        $this->exigirAmbienteDeTeste();

        // Não usa o SectorSeeder: com a marca já gravada ele não cria nada.
        if (! Sector::query()->where('status', SectorStatus::ATIVO)->exists()) {
            foreach (DefaultSectors::NAMES as $i => $nome) {
                $setor = Sector::withTrashed()->firstOrNew(['name' => $nome]);
                $setor->fill(['menu_order' => $i + 1, 'status' => SectorStatus::ATIVO])->forceFill(['deleted_at' => null])->save();
            }
        }

        $setores = Sector::query()->where('status', SectorStatus::ATIVO)->orderBy('menu_order')->get();

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
