<?php

namespace Tests\Feature\Components;

use App\Models\Setting;
use App\Models\User;
use App\Services\Media\AvatarImage;
use App\Settings\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarComponentTest extends TestCase
{
    use RefreshDatabase;

    private function logo(): void
    {
        Storage::fake(config()->string('media.disk'));
        $image = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($image);

        CompanySettings::setLogo((new AvatarImage)->fromBinary((string) ob_get_clean(), 64));
    }

    public function test_com_src_renderiza_img_e_um_nome_acessivel_so(): void
    {
        $this->blade('<x-avatar src="/foto.webp" name="Maria Souza" />')
            ->assertSee('role="img"', false)
            ->assertSee('aria-label="Maria Souza"', false)
            ->assertSee('<img', false)
            ->assertSee('src="/foto.webp"', false)
            ->assertSee('loading="lazy"', false);
    }

    public function test_sem_src_renderiza_monograma_com_iniciais_e_nome_acessivel(): void
    {
        $this->blade('<x-avatar name="Maria Souza" />')
            ->assertDontSee('<img', false)
            ->assertSee('MS')
            ->assertSee('aria-label="Maria Souza"', false);
    }

    public function test_round_usa_circulo_e_rounded_nao(): void
    {
        $this->blade('<x-avatar name="Maria" shape="round" />')->assertSee('data-circle="true"', false);
        $this->blade('<x-avatar name="Maria" shape="rounded" />')->assertDontSee('data-circle', false);
    }

    public function test_img_tem_tratamento_de_erro_que_cai_no_monograma(): void
    {
        $this->blade('<x-avatar src="/foto.webp" name="Maria" />')
            ->assertSee('x-on:error="failed = true"', false)
            ->assertSee('x-show="! failed"', false);
    }

    public function test_single_usa_so_a_inicial(): void
    {
        $this->blade('<x-avatar name="Zeta Corp" single />')->assertSee('select-none">Z<', false);
    }

    public function test_decorativo_some_para_o_leitor_de_tela(): void
    {
        $this->blade('<x-avatar name="Zeta" decorative />')
            ->assertSee('aria-hidden="true"', false)
            ->assertDontSee('aria-label', false);
    }

    public function test_menu_mostra_monograma_sem_logo_e_o_logo_quando_existe(): void
    {
        Setting::put('company.name', 'Zeta Ltda');
        $user = User::factory()->create();

        $this->actingAs($user)->get('/inbox')->assertOk()->assertSee('select-none">Z<', false)->assertDontSee('/marca/logo', false);

        $this->logo();

        $this->actingAs($user)->get('/inbox')->assertOk()->assertSee('/marca/logo', false);
    }

    public function test_login_mostra_o_logo_da_empresa(): void
    {
        $this->logo();

        $this->get('/login')->assertOk()->assertSee('/marca/logo', false);
    }

    public function test_menu_do_usuario_usa_a_foto_dele(): void
    {
        Storage::fake(config()->string('media.disk'));
        $image = imagecreatetruecolor(40, 40);
        ob_start();
        imagepng($image);
        $user = User::factory()->create();
        $user->setAvatar((new AvatarImage)->fromBinary((string) ob_get_clean(), 64));

        $this->actingAs($user)->get('/inbox')->assertOk()->assertSee('/avatares/users/'.$user->id, false);
    }

    public function test_nome_vazio_nao_gera_rotulo_vazio_e_some_para_o_leitor_de_tela(): void
    {
        $this->blade('<x-avatar name="" />')
            ->assertSee('aria-hidden="true"', false)
            ->assertDontSee('aria-label', false);
    }

    public function test_accent_usa_o_amarelo_da_marca_e_o_padrao_usa_cor_automatica(): void
    {
        $this->blade('<x-avatar name="Zeta" accent />')
            ->assertSee('bg-accent', false)
            ->assertSee('text-accent-foreground', false)
            ->assertDontSee('bg-amber-200', false);

        $this->blade('<x-avatar name="Zeta" />')->assertDontSee('bg-accent', false);
    }

    public function test_monograma_da_marca_no_menu_e_no_login_e_amarelo(): void
    {
        $this->get('/login')->assertOk()->assertSee('bg-accent', false);
        $this->actingAs(User::factory()->create())->get('/inbox')->assertOk()->assertSee('bg-accent', false);
    }
}
