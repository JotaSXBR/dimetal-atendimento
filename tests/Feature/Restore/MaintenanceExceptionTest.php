<?php

namespace Tests\Feature\Restore;

use Tests\TestCase;

class MaintenanceExceptionTest extends TestCase
{
    public function test_a_pagina_de_andamento_responde_em_manutencao_e_o_resto_nao(): void
    {
        // Driver `cache` com store `array`: a manutenção fica na memória deste teste (o de arquivo valeria para a suíte paralela inteira).
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->artisan('down')->assertSuccessful();

        $this->get('/login')->assertStatus(503);
        $this->getJson('/restauracao/estado')->assertOk();
        $this->get('/restauracao')->assertOk();

        $this->artisan('up')->assertSuccessful();
    }
}
