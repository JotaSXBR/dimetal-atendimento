<?php

namespace Tests\Feature\Livewire;

use App\Enums\ChannelStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\ChannelCard;
use App\Livewire\Settings\Company;
use App\Models\Channel;
use App\Models\User;
use App\Services\Media\AvatarImage;
use App\Settings\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config()->string('media.disk'));
    }

    private function page(UserRole $role = UserRole::ADMIN): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => $role]))->test(Company::class);
    }

    private function seedLogo(): void
    {
        $image = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($image);
        CompanySettings::setLogo((new AvatarImage)->fromBinary((string) ob_get_clean(), 64));
    }

    public function test_admin_envia_logo_e_ele_vira_256_webp_no_disco(): void
    {
        $this->page()
            ->set('photo', UploadedFile::fake()->image('logo.png', 800, 500))
            ->call('saveLogo')
            ->assertHasNoErrors();

        $path = CompanySettings::logoPath();
        $this->assertNotNull($path);
        $this->assertStringStartsWith('branding/', (string) $path);
        $info = getimagesizefromstring((string) Storage::disk(config()->string('media.disk'))->get((string) $path));
        $this->assertSame([256, 256, 'image/webp'], [$info[0], $info[1], $info['mime']]);
        $this->assertNotNull(CompanySettings::logoUrl());
    }

    public function test_remover_logo_volta_ao_monograma_e_apaga_o_objeto(): void
    {
        $this->seedLogo();
        $path = (string) CompanySettings::logoPath();

        $this->page()->call('removeLogo');

        $this->assertNull(CompanySettings::logoUrl());
        Storage::disk(config()->string('media.disk'))->assertMissing($path);
    }

    public function test_trocar_o_logo_apaga_o_antigo(): void
    {
        $this->seedLogo();
        $old = (string) CompanySettings::logoPath();

        $this->page()->set('photo', UploadedFile::fake()->image('novo.png', 300, 300))->call('saveLogo');

        Storage::disk(config()->string('media.disk'))->assertMissing($old);
        $this->assertNotSame($old, CompanySettings::logoPath());
    }

    public function test_nao_admin_recebe_403_ao_enviar_e_ao_remover(): void
    {
        foreach ([UserRole::SUPERVISOR, UserRole::ATENDENTE] as $role) {
            $this->seedLogo();

            Livewire::actingAs(User::factory()->create(['role' => $role]))->test(Company::class)->assertForbidden();
        }

        $this->assertNotNull(CompanySettings::logoPath());
    }

    public function test_imagem_invalida_mostra_erro_e_nao_grava(): void
    {
        $this->page()
            ->set('photo', UploadedFile::fake()->createWithContent('logo.png', 'nao sou imagem'))
            ->call('saveLogo')
            ->assertHasErrors('photo');

        $this->assertNull(CompanySettings::logoPath());
    }

    public function test_qr_do_canal_recebe_o_logo_da_empresa_ou_nada(): void
    {
        Http::fake(fn ($request) => str_contains($request->url(), '/auth/qr')
            ? Http::response(['value' => 'abc,def'], 200)
            : Http::response(['status' => 'SCAN_QR_CODE'], 200));
        $channel = Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::SCAN_QR_CODE]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($admin)->test(ChannelCard::class, ['channel' => $channel])
            ->call('refreshStatus')
            ->assertSee('channelQr($wire', false)
            ->assertDontSee('/marca/logo', false)
            ->assertDontSee('qr-logo.svg', false);

        $this->seedLogo();

        Livewire::actingAs($admin)->test(ChannelCard::class, ['channel' => $channel])
            ->call('refreshStatus')
            ->assertSee('marca\/logo', false);
    }
}
