<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppBootTest extends TestCase
{
    public function test_login_responde_e_o_app_roda_no_fuso_de_sao_paulo(): void
    {
        $this->get('/login')->assertOk();

        $this->assertSame('America/Sao_Paulo', config('app.timezone'));
        $this->assertSame('America/Sao_Paulo', now()->timezoneName);
    }
}
