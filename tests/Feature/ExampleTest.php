<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_a_raiz_redireciona_para_o_atendimento(): void
    {
        $this->get(route('home'))->assertRedirect('/inbox');
    }
}
