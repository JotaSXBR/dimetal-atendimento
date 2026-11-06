<?php

namespace Tests\Feature\Layout;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    private function page(): string
    {
        return (string) $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get(route('inbox'))->assertOk()->getContent();
    }

    public function test_o_head_aplica_o_tema_antes_do_primeiro_paint(): void
    {
        $html = $this->page();
        $apply = strpos($html, 'window.Flux.applyAppearance');
        $assets = strpos($html, '<link rel="stylesheet"');

        $this->assertNotFalse($apply);
        $this->assertNotFalse($assets);
        $this->assertLessThan($assets, $apply);
    }
}
