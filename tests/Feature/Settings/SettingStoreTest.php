<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Settings\WahaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_e_le_tipos_simples(): void
    {
        Setting::put('app.retention_days', 90, 'int');
        Setting::put('app.auto_close', true, 'bool');
        Setting::put('app.hours', ['mon' => '08:00-18:00'], 'json');

        $this->assertSame(90, Setting::get('app.retention_days'));
        $this->assertTrue(Setting::get('app.auto_close'));
        $this->assertSame(['mon' => '08:00-18:00'], Setting::get('app.hours'));
    }

    public function test_segredo_nao_fica_em_texto_puro_no_banco(): void
    {
        Setting::put('waha.api_key', 'chave-super-secreta', 'secret');

        $raw = DB::table('settings')->where('key', 'waha.api_key')->value('value');

        $this->assertNotSame('chave-super-secreta', $raw);
        $this->assertSame('chave-super-secreta', Setting::get('waha.api_key'));
    }

    public function test_waha_settings_le_as_chaves_com_prefixo(): void
    {
        Setting::put('waha.base_url', 'http://waha:3000');
        Setting::put('waha.session_name', 'main');
        Setting::put('waha.api_key', 'chave', 'secret');
        Setting::put('waha.webhook_secret', 'segredo', 'secret');

        $this->assertSame('http://waha:3000', WahaSettings::baseUrl());
        $this->assertSame('main', WahaSettings::sessionName());
        $this->assertSame('chave', WahaSettings::apiKey());
        $this->assertSame('segredo', WahaSettings::webhookSecret());
        $this->assertTrue(WahaSettings::isConfigured());
    }
}
