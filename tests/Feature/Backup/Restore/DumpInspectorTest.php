<?php

namespace Tests\Feature\Backup\Restore;

use App\Services\Backup\Restore\DumpInspector;
use App\Services\Backup\Restore\InvalidDump;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DumpInspectorTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/dimetal-inspect-'.Str::random(10).'.dump';
        file_put_contents($this->path, 'conteudo-do-dump');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    private function fixture(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/restore/toc-esquema-atual.txt'));
    }

    private function fakeToc(string $toc, int $exit = 0, string $stderr = ''): void
    {
        Process::fake(fn (PendingProcess $process) => Process::result($toc, $stderr, $exit));
    }

    private function inspect(): mixed
    {
        return (new DumpInspector)->inspect($this->path, 'dimetal-2026-11-12-1000-manual.dump');
    }

    public function test_le_o_cabecalho_do_dump(): void
    {
        $this->fakeToc($this->fixture());

        $info = $this->inspect();

        $this->assertSame('dimetal-2026-11-12-1000-manual.dump', $info->name);
        $this->assertSame('2026-11-12 19:07:11', $info->createdAt->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('dimetal_test', $info->database);
        $this->assertSame('18.6', $info->serverVersion);
        $this->assertSame('18.6', $info->dumpVersion);
        $this->assertSame('zstd', $info->compression);
        $this->assertSame(269, $info->entries);
        $this->assertSame(strlen('conteudo-do-dump'), $info->size);
        Process::assertRan(fn (PendingProcess $process): bool => (array) $process->command === ['pg_restore', '--list', $this->path]);
    }

    public function test_o_dump_do_esquema_atual_passa_na_lista_de_permissao(): void
    {
        // Guarda: se uma migração criar função, gatilho, visão ou outra extensão, este teste (com o fixture regenerado) avisa
        // que a lista de permissão da restauração precisa ser revista de propósito.
        DumpInspector::assertSafe($this->fixture());

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function estranhos(): array
    {
        return [
            'função' => ['9001; 1255 99999 FUNCTION public evil() dimetal'],
            'gatilho' => ['9002; 2620 99999 TRIGGER public users trg dimetal'],
            'extensão que não é pg_trgm' => ['9003; 3079 99999 EXTENSION - pgcrypto '],
            'visão' => ['9004; 1259 99999 VIEW public v dimetal'],
            'regra' => ['9005; 2618 99999 RULE public r dimetal'],
            'linguagem' => ['9006; 2612 99999 PROCEDURAL LANGUAGE - plpythonu dimetal'],
            'tabela de outro esquema' => ['9007; 1259 99999 TABLE pg_catalog x dimetal'],
            'anexo de partição' => ['9008; 1259 99999 TABLE ATTACH public x dimetal'],
            'comentário solto' => ['9009; 0 0 COMMENT public TABLE users dimetal'],
            'linha desconhecida' => ['9010; 0 0 ALGO ESTRANHO'],
        ];
    }

    /** @dataProvider estranhos */
    #[DataProvider('estranhos')]
    public function test_recusa_objetos_fora_da_lista(string $line): void
    {
        $this->fakeToc($this->fixture().$line."\n");

        try {
            $this->inspect();
            $this->fail('devia lançar InvalidDump');
        } catch (InvalidDump $e) {
            $this->assertStringContainsString('não é um backup deste sistema', $e->getMessage());
        }
    }

    public function test_pg_restore_que_falha_vira_invalid_dump_sem_a_linha_de_comando(): void
    {
        $this->fakeToc('', 1, 'pg_restore: error: input file does not appear to be a valid archive');

        try {
            $this->inspect();
            $this->fail('devia lançar InvalidDump');
        } catch (InvalidDump $e) {
            $this->assertStringContainsString('não parece um backup do PostgreSQL', $e->getMessage());
            $this->assertStringNotContainsString('pg_restore', $e->getMessage());
        }
    }

    public function test_arquivo_vazio_e_recusado(): void
    {
        file_put_contents($this->path, '');
        $this->fakeToc($this->fixture());

        $this->expectException(InvalidDump::class);
        $this->expectExceptionMessage('vazio');

        $this->inspect();
    }

    public function test_toc_sem_cabecalho_legivel_e_recusada(): void
    {
        $this->fakeToc("; sem cabecalho\n1; 1259 1 TABLE public a dimetal\n");

        $this->expectException(InvalidDump::class);

        $this->inspect();
    }
}
