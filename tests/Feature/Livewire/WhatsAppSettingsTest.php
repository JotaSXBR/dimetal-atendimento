<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Settings\WhatsApp;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WhatsAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_supervisor_nao_entra_na_tela(): void
    {
        $user = User::factory()->create(['role' => UserRole::SUPERVISOR]);

        $this->actingAs($user)->get('/configuracoes/whatsapp')->assertForbidden();
    }

    public function test_admin_salva_e_testa_a_conexao(): void
    {
        Http::fake(['*/api/sessions*' => Http::response([], 200)]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($admin)->test(WhatsApp::class)
            ->set('baseUrl', 'http://waha:3000')
            ->set('sessionName', 'main')
            ->set('apiKey', 'chave')
            ->set('webhookSecret', 'segredo')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('http://waha:3000', Setting::get('waha.base_url'));
        $this->assertSame('chave', Setting::get('waha.api_key'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/sessions'));
    }

    public function test_url_invalida_nao_e_salva(): void
    {
        Http::fake();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($admin)->test(WhatsApp::class)
            ->set('baseUrl', 'nao-e-url')
            ->set('sessionName', 'main')
            ->set('apiKey', 'chave')
            ->set('webhookSecret', 'segredo')
            ->call('save')
            ->assertHasErrors('baseUrl');

        $this->assertNull(Setting::get('waha.base_url'));
        Http::assertNothingSent();
    }

    public function test_waha_fora_do_ar_nao_grava(): void
    {
        Http::fake(['*/api/sessions*' => Http::response('erro', 500)]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($admin)->test(WhatsApp::class)
            ->set('baseUrl', 'http://waha:3000')
            ->set('sessionName', 'main')
            ->set('apiKey', 'chave')
            ->set('webhookSecret', 'segredo')
            ->call('save')
            ->assertSet('errorMessage', fn ($message) => str_contains($message, 'WAHA'));

        $this->assertNull(Setting::get('waha.api_key'));
    }
}
