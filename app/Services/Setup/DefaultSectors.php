<?php

namespace App\Services\Setup;

use App\Enums\SectorStatus;
use App\Models\Sector;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Os setores com que uma instalação nova começa. Roda uma vez só (marca em `settings`): depois o admin cria, renomeia e apaga
 * como quiser e nada é recriado. Instalação que já tem setores (o beta) só ganha a marca.
 */
final class DefaultSectors
{
    /** Na ordem do menu do chatbot. */
    public const NAMES = ['Vendas', 'Financeiro', 'Comercial', 'SAC', 'Diretoria'];

    public const MARK = 'sectors.defaults_loaded';

    public static function isLoaded(): bool
    {
        return Setting::get(self::MARK) === true;
    }

    /** @return bool `true` quando gravou algo (criou os setores ou só a marca); `false` se já estava instalado */
    public static function install(): bool
    {
        if (self::isLoaded()) {
            return false;
        }

        return DB::transaction(function (): bool {
            // Dois processos (agendador e seeder) podem chegar juntos: o segundo espera e vê a marca do primeiro.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', [self::MARK]);

            if (self::isLoaded()) {
                return false;
            }

            if (! Sector::withTrashed()->exists()) {
                foreach (self::NAMES as $index => $name) {
                    Sector::query()->create(['name' => $name, 'menu_order' => $index + 1, 'status' => SectorStatus::ATIVO]);
                }
            }

            Setting::put(self::MARK, true);

            return true;
        });
    }
}
