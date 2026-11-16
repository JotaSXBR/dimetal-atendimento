<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\BackupCatalog;
use App\Services\Backup\BackupKind;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\TestCase;

class BackupCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        config(['app.display_timezone' => 'America/Sao_Paulo']);
    }

    private function catalog(): BackupCatalog
    {
        return new BackupCatalog;
    }

    /** Grava um arquivo no bucket com o nome do catálogo, `$daysAgo` dias antes de um dia fixo. */
    private function stored(BackupKind $kind, int $daysAgo, string $content = 'x'): string
    {
        $name = $this->catalog()->name($kind, CarbonImmutable::parse('2026-11-13 05:15:00', 'UTC')->subDays($daysAgo));
        Storage::disk('backups')->put($name, $content);

        return $name;
    }

    public function test_nome_usa_o_fuso_de_exibicao(): void
    {
        $at = CarbonImmutable::parse('2026-11-13 05:15:00', 'UTC');

        $this->assertSame('fluxie-2026-11-13-0215-diario.dump', $this->catalog()->name(BackupKind::Diario, $at));
        $this->assertSame('fluxie-2026-11-13-0215-semanal.dump', $this->catalog()->name(BackupKind::Semanal, $at));
        $this->assertSame('fluxie-2026-11-13-0215-manual.dump', $this->catalog()->name(BackupKind::Manual, $at));
    }

    public function test_lista_do_mais_novo_ao_mais_velho_e_ignora_arquivo_estranho(): void
    {
        $old = $this->stored(BackupKind::Diario, 2, 'aaa');
        $new = $this->stored(BackupKind::Manual, 0, 'bbbbb');
        Storage::disk('backups')->put('lixo.txt', 'x');
        Storage::disk('backups')->put('dimetal-x.dump', 'x');

        $all = $this->catalog()->all();

        $this->assertSame([$new, $old], array_map(fn ($file) => $file->name, $all));
        $this->assertSame(BackupKind::Manual, $all[0]->kind);
        $this->assertSame(5, $all[0]->size);
        $this->assertSame('2026-11-13 02:15', $all[0]->createdAt->format('Y-m-d H:i'));
        $this->assertSame(8, $this->catalog()->totalBytes());
    }

    public function test_lista_com_o_tamanho_da_propria_listagem_sem_size_por_arquivo(): void
    {
        $root = storage_path('framework/testing/disks/backups-sem-size-'.uniqid());
        $adapter = new LocalFilesystemAdapter($root);
        $disk = new class(new Flysystem($adapter), $adapter, ['root' => $root]) extends FilesystemAdapter
        {
            public function size($path): int
            {
                throw new \LogicException('size() por arquivo não deveria ser chamado');
            }
        };
        Storage::set('backups', $disk);

        try {
            $name = $this->stored(BackupKind::Diario, 0, 'abcde');

            $this->assertSame([[$name, 5]], array_map(fn ($file) => [$file->name, $file->size], $this->catalog()->all()));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_find_so_acha_nome_da_lista(): void
    {
        $name = $this->stored(BackupKind::Diario, 0);

        $this->assertSame($name, $this->catalog()->find($name)?->name);
        $this->assertNull($this->catalog()->find('../.env'));
        $this->assertNull($this->catalog()->find('dimetal-2026-01-01-0000-diario.dump'));
    }

    public function test_prune_mantem_7_diarios_2_semanais_3_manuais(): void
    {
        foreach (range(0, 8) as $i) {
            $this->stored(BackupKind::Diario, $i);
        }
        foreach (range(0, 3) as $i) {
            $this->stored(BackupKind::Semanal, $i * 7);
        }
        foreach (range(0, 4) as $i) {
            $this->stored(BackupKind::Manual, $i);
        }

        $this->assertSame(2 + 2 + 2, $this->catalog()->prune());

        $left = collect($this->catalog()->all());
        $this->assertCount(7, $left->where('kind', BackupKind::Diario));
        $this->assertCount(2, $left->where('kind', BackupKind::Semanal));
        $this->assertCount(3, $left->where('kind', BackupKind::Manual));
        $this->assertNotNull($this->catalog()->find($this->catalog()->name(BackupKind::Diario, CarbonImmutable::parse('2026-11-13 05:15:00', 'UTC'))));
        $this->assertNull($this->catalog()->find($this->catalog()->name(BackupKind::Diario, CarbonImmutable::parse('2026-11-13 05:15:00', 'UTC')->subDays(8))));
    }

    public function test_prune_com_poucos_nao_apaga_nada(): void
    {
        foreach (range(0, 2) as $i) {
            $this->stored(BackupKind::Diario, $i);
        }

        $this->assertSame(0, $this->catalog()->prune());
        $this->assertCount(3, $this->catalog()->all());
    }

    public function test_manual_nao_conta_nos_diarios_e_arquivo_estranho_nao_e_apagado(): void
    {
        foreach (range(0, 6) as $i) {
            $this->stored(BackupKind::Diario, $i);
        }
        foreach (range(0, 2) as $i) {
            $this->stored(BackupKind::Manual, $i);
        }
        Storage::disk('backups')->put('lixo.txt', 'x');

        $this->assertSame(0, $this->catalog()->prune());
        Storage::disk('backups')->assertExists('lixo.txt');
    }

    public function test_nome_do_tipo_antes(): void
    {
        $at = CarbonImmutable::parse('2026-11-13 05:15:00', 'UTC');

        $this->assertSame('fluxie-2026-11-13-0215-antes.dump', $this->catalog()->name(BackupKind::Antes, $at));
        $this->assertSame('Antes de restaurar', BackupKind::Antes->label());
    }

    public function test_lista_reconhece_o_tipo_antes(): void
    {
        $name = $this->stored(BackupKind::Antes, 0);

        $this->assertSame(BackupKind::Antes, $this->catalog()->find($name)?->kind);
    }

    public function test_prune_guarda_os_2_ultimos_antes_sem_mexer_nos_outros_tipos(): void
    {
        foreach (range(0, 3) as $i) {
            $this->stored(BackupKind::Antes, $i);
        }
        foreach (range(0, 2) as $i) {
            $this->stored(BackupKind::Manual, $i);
        }

        $this->assertSame(2, $this->catalog()->prune());

        $left = collect($this->catalog()->all());
        $this->assertCount(2, $left->where('kind', BackupKind::Antes));
        $this->assertCount(3, $left->where('kind', BackupKind::Manual));
    }

    public function test_nome_novo_usa_o_prefixo_fluxie(): void
    {
        $at = CarbonImmutable::parse('2026-11-16 15:30', 'America/Sao_Paulo');

        $this->assertSame('fluxie-2026-11-16-1530-manual.dump', $this->catalog()->name(BackupKind::Manual, $at));
    }

    public function test_lista_backups_antigos_dimetal_junto_com_os_novos_por_data(): void
    {
        Storage::disk('backups')->put('dimetal-2026-11-10-0300-diario.dump', 'x');
        Storage::disk('backups')->put('fluxie-2026-11-16-0300-diario.dump', 'x');

        $this->assertSame(
            ['fluxie-2026-11-16-0300-diario.dump', 'dimetal-2026-11-10-0300-diario.dump'],
            array_map(fn ($file) => $file->name, $this->catalog()->all()),
        );
    }

    public function test_find_acha_backup_antigo_dimetal(): void
    {
        Storage::disk('backups')->put('dimetal-2026-11-10-0300-diario.dump', 'x');

        $this->assertNotNull($this->catalog()->find('dimetal-2026-11-10-0300-diario.dump'));
    }

    public function test_prune_conta_os_dois_prefixos_no_mesmo_tipo(): void
    {
        config(['backup.keep.diario' => 1]);
        Storage::disk('backups')->put('dimetal-2026-11-10-0300-diario.dump', 'x');
        Storage::disk('backups')->put('fluxie-2026-11-16-0300-diario.dump', 'x');

        $this->assertSame(1, $this->catalog()->prune());
        Storage::disk('backups')->assertMissing('dimetal-2026-11-10-0300-diario.dump');
        Storage::disk('backups')->assertExists('fluxie-2026-11-16-0300-diario.dump');
    }

    public function test_nomes_fora_do_padrao_continuam_fora(): void
    {
        foreach (['fluxiex-2026-11-16-0300-diario.dump', 'dimetal-2026-11-10-0300-outro.dump', 'fluxie-2026-11-16-0300-diario.dump.tmp'] as $name) {
            Storage::disk('backups')->put($name, 'x');
        }

        $this->assertSame([], $this->catalog()->all());
    }
}
