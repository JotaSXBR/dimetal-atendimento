<?php

namespace Tests\Feature\Domain;

use App\Enums\SectorStatus;
use App\Models\Sector;
use App\Models\Setting;
use App\Services\Setup\DefaultSectors;
use Database\Seeders\SectorSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultSectorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_instalacao_nova_cria_os_cinco_na_ordem_do_menu_e_ativos(): void
    {
        $this->assertTrue(DefaultSectors::install());

        $sectors = Sector::query()->orderBy('menu_order')->get();
        $this->assertSame(['Vendas', 'Financeiro', 'Comercial', 'SAC', 'Diretoria'], $sectors->pluck('name')->all());
        $this->assertSame([1, 2, 3, 4, 5], $sectors->pluck('menu_order')->all());
        $this->assertSame([SectorStatus::ATIVO], $sectors->pluck('status')->unique()->values()->all());
        $this->assertTrue((bool) Setting::get('sectors.defaults_loaded'));
    }

    public function test_segunda_chamada_nao_recria_o_que_foi_apagado(): void
    {
        DefaultSectors::install();
        Sector::query()->where('name', 'SAC')->firstOrFail()->delete();

        $this->assertFalse(DefaultSectors::install());

        $this->assertSame(4, Sector::query()->count());
        $this->assertSame(0, Sector::query()->where('name', 'SAC')->count());
    }

    public function test_banco_com_setores_so_grava_a_marca_e_nao_duplica(): void
    {
        Sector::factory()->create(['name' => 'Vendas', 'menu_order' => 1]);

        $this->assertTrue(DefaultSectors::install());

        $this->assertSame(1, Sector::query()->count());
        $this->assertTrue((bool) Setting::get('sectors.defaults_loaded'));
    }

    public function test_setor_so_apagado_conta_como_ja_instalado(): void
    {
        Sector::factory()->create()->delete();

        DefaultSectors::install();

        $this->assertSame(0, Sector::query()->count());
    }

    public function test_seeder_usa_o_mesmo_caminho_e_continua_idempotente(): void
    {
        $this->seed(SectorSeeder::class);
        $this->seed(SectorSeeder::class);

        $this->assertSame(5, Sector::query()->count());
    }

    public function test_agendamento_so_roda_enquanto_a_marca_nao_existe(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e): bool => $e->description === 'install-default-sectors');

        $this->assertNotNull($event, 'O passo agendado install-default-sectors não existe.');
        $this->assertTrue($event->filtersPass($this->app));

        DefaultSectors::install();

        $this->assertFalse($event->filtersPass($this->app));
    }
}
