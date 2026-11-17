<?php

namespace Tests\Feature\Setup;

use App\Models\Sector;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Bot\DefaultWorkflow;
use App\Services\Bot\InvalidFlowException;
use App\Services\Bot\WorkflowPublisher;
use App\Services\Setup\ApplySegment;
use App\Services\Setup\DefaultSectors;
use App\Services\Setup\DefaultTags;
use App\Services\Setup\SetupState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ApplySegmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_distribuidora_cria_setores_etiquetas_e_atendimento_padrao_publicado(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        app(ApplySegment::class)(ApplySegment::DISTRIBUIDORA, $admin);

        $this->assertSame(5, Sector::query()->count());
        $this->assertSame(15, Tag::query()->count());
        $workflow = Workflow::query()->where('name', DefaultWorkflow::NAME)->sole();
        $this->assertSame(1, $workflow->versions()->count());
        $this->assertSame('distribuidora', SetupState::segment());
        $this->assertSame('canal', SetupState::step());
    }

    public function test_em_branco_nao_cria_nada_e_grava_as_marcas(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        app(ApplySegment::class)(ApplySegment::EM_BRANCO, $admin);

        $this->assertSame(0, Sector::query()->count());
        $this->assertSame(0, Tag::query()->count());
        $this->assertSame(0, Workflow::query()->count());
        $this->assertTrue(Setting::get(DefaultSectors::MARK));
        $this->assertTrue(Setting::get(DefaultTags::MARK));
        $this->assertSame('em_branco', SetupState::segment());
        $this->assertSame('canal', SetupState::step());
    }

    public function test_segunda_chamada_nao_duplica(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        app(ApplySegment::class)(ApplySegment::DISTRIBUIDORA, $admin);
        app(ApplySegment::class)(ApplySegment::DISTRIBUIDORA, $admin);

        $this->assertSame(5, Sector::query()->count());
        $this->assertSame(15, Tag::query()->count());
        $this->assertSame(1, Workflow::query()->count());
        $this->assertSame(1, Workflow::query()->firstOrFail()->versions()->count());
    }

    public function test_falha_no_meio_desfaz_tudo(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $real = app(WorkflowPublisher::class);
        $this->mock(WorkflowPublisher::class, function ($mock) use ($real): void {
            $mock->shouldReceive('create')->andReturnUsing(fn (...$args) => $real->create(...$args));
            $mock->shouldReceive('publish')->andThrow(new InvalidFlowException(['x']));
        });

        try {
            app(ApplySegment::class)(ApplySegment::DISTRIBUIDORA, $admin);
            $this->fail('Esperava InvalidFlowException.');
        } catch (InvalidFlowException) {
        }

        $this->assertSame(0, Sector::query()->count());
        $this->assertSame(0, Tag::query()->count());
        $this->assertSame(0, Workflow::query()->count());
        $this->assertNull(SetupState::segment());
        $this->assertFalse(DefaultSectors::isLoaded());
    }

    public function test_segmento_desconhecido_e_recusado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ApplySegment::class)('outro', User::factory()->create(['role' => 'admin']));
    }
}
