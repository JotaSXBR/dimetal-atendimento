<?php

namespace Tests\Feature\Console;

use App\Services\Backup\Restore\AdminDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Postgres de verdade, com bancos descartáveis derivados do nome do banco de teste (a suíte paralela dá um a cada processo). */
class PurgeOldDatabasesCommandTest extends TestCase
{
    private AdminDatabase $admin;

    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.display_timezone' => 'America/Sao_Paulo']);
        $this->admin = new AdminDatabase;
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $name) {
            DB::connection('pgsql_admin')->statement('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
        }

        parent::tearDown();
    }

    private function make(string $name): string
    {
        $this->admin->create($name);

        return $this->created[] = $name;
    }

    public function test_apaga_so_os_antes_com_mais_de_7_dias(): void
    {
        $active = $this->admin->active();
        $old = $this->make(AdminDatabase::archiveName($active, CarbonImmutable::now()->subDays(8)));
        $older = $this->make(AdminDatabase::archiveName($active, CarbonImmutable::now()->subDays(30)));
        $recent = $this->make(AdminDatabase::archiveName($active, CarbonImmutable::now()->subDays(6)));

        $this->artisan('app:purge-old-databases')->expectsOutputToContain('2')->assertSuccessful();

        $this->assertFalse($this->admin->exists($old));
        $this->assertFalse($this->admin->exists($older));
        $this->assertTrue($this->admin->exists($recent));
    }

    public function test_nunca_apaga_o_banco_ativo_nem_banco_de_nome_diferente(): void
    {
        $active = $this->admin->active();
        $staging = $this->make(AdminDatabase::stagingName($active, CarbonImmutable::now()->subHour()));
        $strange = $this->make($active.'_antes_abc');
        $other = $this->make($active.'_outro_202001010000');

        $this->artisan('app:purge-old-databases')->assertSuccessful();

        $this->assertTrue($this->admin->exists($active));
        $this->assertTrue($this->admin->exists($staging));
        $this->assertTrue($this->admin->exists($strange));
        $this->assertTrue($this->admin->exists($other));
    }

    public function test_sem_nada_a_apagar_sai_com_sucesso(): void
    {
        $this->artisan('app:purge-old-databases')->expectsOutputToContain('0')->assertSuccessful();
    }

    public function test_apaga_banco_de_restauracao_orfao_com_mais_de_1_dia_e_mantem_o_recente(): void
    {
        $active = $this->admin->active();
        $orphan = $this->make(AdminDatabase::stagingName($active, CarbonImmutable::now()->subDays(2)));
        $recent = $this->make(AdminDatabase::stagingName($active, CarbonImmutable::now()->subHours(2)));

        $this->artisan('app:purge-old-databases')->assertSuccessful();

        $this->assertFalse($this->admin->exists($orphan));
        $this->assertTrue($this->admin->exists($recent));
    }

    public function test_apaga_arquivo_enviado_e_abandonado_ha_mais_de_1_dia(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('restauracao/velho.dump', 'x');
        Storage::disk('backups')->put('restauracao/novo.dump', 'x');
        touch(Storage::disk('backups')->path('restauracao/velho.dump'), time() - 2 * 86400);

        $this->artisan('app:purge-old-databases')->assertSuccessful();

        Storage::disk('backups')->assertMissing('restauracao/velho.dump');
        Storage::disk('backups')->assertExists('restauracao/novo.dump');
    }
}
