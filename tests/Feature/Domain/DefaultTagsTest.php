<?php

namespace Tests\Feature\Domain;

use App\Enums\TagColor;
use App\Enums\TagScope;
use App\Models\Tag;
use App\Services\Setup\DefaultTags;
use Database\Seeders\TagSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_instalacao_nova_cria_as_quinze_com_escopo_e_cor(): void
    {
        $this->assertTrue(DefaultTags::install());

        $this->assertSame(15, Tag::query()->count());
        $this->assertSame(7, Tag::query()->where('scope', TagScope::CONTATO)->count());
        $this->assertSame(8, Tag::query()->where('scope', TagScope::CONVERSA)->count());
        $vip = Tag::query()->where('name', 'VIP')->firstOrFail();
        $this->assertSame(TagScope::CONTATO, $vip->scope);
        $this->assertSame(TagColor::FUCSIA, $vip->color);
        $this->assertTrue(DefaultTags::isLoaded());
    }

    /** "Enxuto até no nome" (decisão do dono): o card da Fila não pode se poluir; 12 é o teto que ele aprovou na escolha dos nomes. */
    public function test_nomes_unicos_entre_escopos_e_enxutos(): void
    {
        $names = array_column(DefaultTags::ALL, 0);

        $this->assertSame($names, array_values(array_unique($names)));
        foreach ($names as $name) {
            $this->assertLessThanOrEqual(12, mb_strlen($name), $name);
        }
    }

    public function test_segunda_chamada_nao_recria_o_que_foi_apagado(): void
    {
        DefaultTags::install();
        Tag::query()->where('name', 'Urgente')->firstOrFail()->delete();

        $this->assertFalse(DefaultTags::install());

        $this->assertSame(14, Tag::query()->count());
    }

    public function test_banco_com_etiqueta_so_grava_a_marca_e_nao_duplica(): void
    {
        Tag::factory()->create(['name' => 'Minha']);

        $this->assertTrue(DefaultTags::install());

        $this->assertSame(1, Tag::query()->count());
        $this->assertTrue(DefaultTags::isLoaded());
        $this->assertFalse(DefaultTags::install());
        $this->assertSame(1, Tag::query()->count());
    }

    public function test_apagar_todas_depois_da_marca_nao_traz_nenhuma_de_volta(): void
    {
        DefaultTags::install();
        Tag::query()->delete();

        $this->assertFalse(DefaultTags::install());

        $this->assertSame(0, Tag::query()->count());
    }

    public function test_seeder_usa_o_mesmo_caminho_e_continua_idempotente(): void
    {
        $this->seed(TagSeeder::class);
        $this->seed(TagSeeder::class);

        $this->assertSame(15, Tag::query()->count());
    }

    public function test_agendamento_so_roda_enquanto_a_marca_nao_existe(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e): bool => $e->description === 'install-default-tags');

        $this->assertNotNull($event, 'O passo agendado install-default-tags não existe.');
        $this->assertTrue($event->filtersPass($this->app));

        DefaultTags::install();

        $this->assertFalse($event->filtersPass($this->app));
    }
}
