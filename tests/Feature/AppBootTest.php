<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppBootTest extends TestCase
{
    public function test_login_responde_e_o_app_grava_em_utc_e_exibe_em_sao_paulo(): void
    {
        $this->get('/login')->assertOk();

        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', now()->timezoneName);
        $this->assertSame('America/Sao_Paulo', config('app.display_timezone'));
    }
}
