<?php

namespace Tests\Feature\Backup\Restore;

use App\Services\Backup\Restore\AdminDatabase;
use App\Services\Backup\Restore\SwapFailed;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDO;
use PDOException;
use Tests\TestCase;

/** Postgres de verdade: os bancos criados aqui são descartáveis e têm sufixo aleatório (a suíte roda em paralelo). */
class AdminDatabaseTest extends TestCase
{
    /** @var list<string> */
    private array $created = [];

    private AdminDatabase $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'America/Sao_Paulo']);
        $this->admin = new AdminDatabase;
    }

    protected function tearDown(): void
    {
        foreach (array_unique($this->created) as $name) {
            DB::connection('pgsql_admin')->statement('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
        }

        parent::tearDown();
    }

    private function make(string $name): string
    {
        $this->admin->create($name);
        $this->created[] = $name;

        return $name;
    }

    private function oid(string $name): ?int
    {
        $row = $this->admin->connection()->selectOne('select oid from pg_database where datname = ?', [$name]);

        return $row === null ? null : (int) $row->oid;
    }

    private function suffix(): string
    {
        return Str::lower(Str::random(6));
    }

    public function test_nomes_validos_e_invalidos(): void
    {
        AdminDatabase::assertName('dimetal');
        AdminDatabase::assertName('dimetal_antes_202611121000');

        foreach (['a; drop database x', 'Dimetal', '1abc', '', str_repeat('a', 64), 'a-b', 'a b', 'a"b'] as $bad) {
            try {
                AdminDatabase::assertName($bad);
                $this->fail('devia recusar '.$bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_nome_de_arquivo_e_de_staging_usam_o_fuso_de_exibicao(): void
    {
        $at = CarbonImmutable::parse('2026-11-13 05:15:00', 'UTC');

        $this->assertSame('dimetal_antes_202611130215', AdminDatabase::archiveName('dimetal', $at));
        $this->assertSame('dimetal_restaurando_202611130215', AdminDatabase::stagingName('dimetal', $at));
    }

    public function test_cria_e_apaga_um_banco(): void
    {
        $name = AdminDatabase::stagingName($this->admin->active(), CarbonImmutable::now()->addDays(400));
        $this->created[] = $name;

        $this->assertFalse($this->admin->exists($name));
        $this->admin->create($name);
        $this->assertTrue($this->admin->exists($name));

        $this->admin->drop($name);
        $this->assertFalse($this->admin->exists($name));
    }

    public function test_drop_recusa_o_banco_ativo_e_nomes_fora_do_padrao(): void
    {
        $active = $this->admin->active();

        foreach ([$active, 'postgres', 'template1', $active.'_outro', $active.'_antes_abc', 'a; drop database x'] as $name) {
            try {
                $this->admin->drop($name);
                $this->fail('devia recusar '.$name);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertTrue($this->admin->exists($active));
    }

    public function test_swap_troca_os_bancos_pelo_oid_e_guarda_o_antigo(): void
    {
        $a = $this->make($this->admin->active().'_t'.$this->suffix());
        $b = $this->make($this->admin->active().'_t'.$this->suffix());
        $archive = AdminDatabase::archiveName($a, CarbonImmutable::now());
        $this->created[] = $archive;
        [$oidA, $oidB] = [$this->oid($a), $this->oid($b)];

        $open = $this->openPdo($a);

        $this->admin->swap($a, $b, $archive);

        $this->assertSame($oidB, $this->oid($a), 'o novo passou a ter o nome ativo');
        $this->assertSame($oidA, $this->oid($archive), 'o antigo ficou guardado');
        $this->assertNull($this->oid($b));
        $this->assertTrue((bool) $this->admin->connection()->selectOne('select datallowconn from pg_database where datname = ?', [$a])->datallowconn);

        try {
            $open->query('select 1');
            $this->fail('a conexão aberta no banco antigo devia ter sido derrubada');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_swap_derruba_tambem_as_conexoes_abertas_no_banco_novo(): void
    {
        // Achado na prova real: a conexão que o `verify` deixa aberta no banco novo impedia o renome dele.
        $a = $this->make($this->admin->active().'_t'.$this->suffix());
        $b = $this->make($this->admin->active().'_t'.$this->suffix());
        $archive = AdminDatabase::archiveName($a, CarbonImmutable::now());
        $this->created[] = $archive;
        $oidB = $this->oid($b);

        $openOnNew = $this->openPdo($b);

        $this->admin->swap($a, $b, $archive);

        $this->assertSame($oidB, $this->oid($a));

        try {
            $openOnNew->query('select 1');
            $this->fail('a conexão aberta no banco novo devia ter sido derrubada');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_swap_que_falha_no_primeiro_renome_nao_muda_nada(): void
    {
        $a = $this->make($this->admin->active().'_t'.$this->suffix());
        $b = $this->make($this->admin->active().'_t'.$this->suffix());
        $archive = $this->make($this->admin->active().'_t'.$this->suffix()); // o nome de arquivo já existe
        [$oidA, $oidB] = [$this->oid($a), $this->oid($b)];

        Exceptions::fake();

        try {
            $this->admin->swap($a, $b, $archive);
            $this->fail('devia lançar SwapFailed');
        } catch (SwapFailed) {
            $this->addToAssertionCount(1);
        }

        // A causa real (erro do Postgres) vai para o log: a mensagem da tela é genérica.
        Exceptions::assertReported(QueryException::class);
        $this->assertSame($oidA, $this->oid($a));
        $this->assertSame($oidB, $this->oid($b));
        $this->assertTrue((bool) $this->admin->connection()->selectOne('select datallowconn from pg_database where datname = ?', [$a])->datallowconn);
    }

    public function test_swap_que_falha_no_segundo_renome_volta_ao_estado_anterior(): void
    {
        $a = $this->make($this->admin->active().'_t'.$this->suffix());
        $archive = AdminDatabase::archiveName($a, CarbonImmutable::now());
        $this->created[] = $archive;
        $oidA = $this->oid($a);

        try {
            $this->admin->swap($a, $this->admin->active().'_t_inexistente', $archive);
            $this->fail('devia lançar SwapFailed');
        } catch (SwapFailed) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($oidA, $this->oid($a), 'o ativo voltou a ser o ativo');
        $this->assertNull($this->oid($archive));
        $this->assertTrue((bool) $this->admin->connection()->selectOne('select datallowconn from pg_database where datname = ?', [$a])->datallowconn);
    }

    public function test_stored_lista_so_os_antes_do_banco_ativo_com_data_e_tamanho(): void
    {
        $active = $this->admin->active();
        $old = $this->make($active.'_antes_202611121000');
        $new = $this->make($active.'_antes_202611131000');
        $this->make($active.'_antes_abc'); // fora do padrão
        $this->make($active.'_restaurando_202611131000'); // outro tipo

        $stored = $this->admin->stored();

        $this->assertSame([$new, $old], array_map(fn ($db) => $db->name, $stored));
        $this->assertSame('2026-11-13 10:00', $stored[0]->createdAt->format('Y-m-d H:i'));
        $this->assertSame('America/Sao_Paulo', $stored[0]->createdAt->timezoneName);
        $this->assertGreaterThan(0, $stored[0]->bytes);
    }

    public function test_can_create_em_postgres_de_teste(): void
    {
        $this->assertTrue($this->admin->canCreate());
    }

    private function openPdo(string $database): PDO
    {
        $config = DB::connection()->getConfig();

        return new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $database),
            (string) $config['username'],
            (string) $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }
}
