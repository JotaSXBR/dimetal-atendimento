<?php

namespace Tests\Feature\Restore;

use App\Services\Backup\Restore\RestoreProgress;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestoreStatusTest extends TestCase
{
    private function running(): RestoreProgress
    {
        $progress = new RestoreProgress;
        $progress->begin('Restaurando o banco', ['inspect', 'safety', 'swap']);
        $progress->running('inspect');
        $progress->done('inspect');
        $progress->running('safety');

        return $progress;
    }

    public function test_estado_devolve_os_passos_e_nenhum_dado_sensivel(): void
    {
        $this->running();

        $response = $this->getJson('/restauracao/estado')->assertOk();

        $response->assertJson([
            'status' => 'running',
            'title' => 'Restaurando o banco',
            'steps' => [
                ['key' => 'inspect', 'label' => 'Conferindo o arquivo', 'state' => 'done'],
                ['key' => 'safety', 'label' => 'Backup de segurança', 'state' => 'running'],
                ['key' => 'swap', 'label' => 'Trocando o banco', 'state' => 'pending'],
            ],
        ]);
        $this->assertSame(['message', 'status', 'steps', 'title'], collect($response->json())->keys()->sort()->values()->all());
    }

    public function test_estado_idle_sem_restauracao(): void
    {
        $this->getJson('/restauracao/estado')->assertOk()->assertJson(['status' => 'idle', 'steps' => []]);
    }

    public function test_json_nao_e_cacheado(): void
    {
        $this->getJson('/restauracao/estado')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_pagina_de_andamento_abre_sem_login_e_mostra_os_passos(): void
    {
        $this->running();

        $this->get('/restauracao')
            ->assertOk()
            ->assertSee('Restaurando o banco')
            ->assertSee('Conferindo o arquivo')
            ->assertSee('Backup de segurança')
            ->assertSee('data-state="running"', false)
            ->assertSee(route('restauracao.estado'), false);
    }

    public function test_pagina_de_andamento_com_falha_mostra_a_mensagem_e_o_caminho_de_volta(): void
    {
        $this->running()->fail('O arquivo está vazio.');

        $this->get('/restauracao')
            ->assertOk()
            ->assertSee('O arquivo está vazio.')
            ->assertSee(route('configuracoes.backups'), false);
    }

    public function test_pagina_sem_restauracao_manda_de_volta_para_o_backup(): void
    {
        $this->get('/restauracao')->assertOk()->assertSee('Nenhuma restauração em andamento')->assertSee(route('configuracoes.backups'), false);
    }

    public function test_503_de_manutencao_fala_da_restauracao_e_recarrega(): void
    {
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->running();
        $this->artisan('down')->assertSuccessful();

        $this->get('/login')
            ->assertStatus(503)
            ->assertSee('Restauração do banco em andamento')
            ->assertSee('<meta http-equiv="refresh" content="10">', false);
    }

    public function test_503_de_manutencao_comum_nao_fala_de_restauracao(): void
    {
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->artisan('down')->assertSuccessful();

        $this->get('/login')->assertStatus(503)->assertSee('em manutenção')->assertDontSee('Restauração do banco');
    }

    public function test_as_paginas_de_andamento_nao_consultam_o_banco(): void
    {
        // O banco troca durante a restauração: estas duas rotas não podem depender dele.
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get('/restauracao')->assertOk();
        $this->getJson('/restauracao/estado')->assertOk();

        $this->assertSame(0, $queries);
    }
}
