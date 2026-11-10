<?php

namespace Tests\Feature\Components;

use App\Livewire\Concerns\ReceivesAvatar;
use App\Services\Media\AvatarImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

/** Componente só de teste: expõe o que a trait entrega. */
class AvatarReceiver extends Component
{
    use ReceivesAvatar;

    public int $side = 0;

    public function process(): void
    {
        $info = getimagesizefromstring($this->avatarWebp(AvatarImage::SIZE_PERSON));
        $this->side = $info[0];
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

class AvatarUploaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploader_renderiza_com_o_editor_e_os_botoes_de_zoom_e_mover(): void
    {
        $this->blade('<x-avatar-uploader model="photo" name="Maria Souza" shape="round" />')
            ->assertSee('x-data="avatarCropper(', false)
            ->assertSee('Escolher imagem')
            ->assertSee('Aproximar')
            ->assertSee('Afastar')
            ->assertSee('Mover para a esquerda')
            ->assertSee('Usar esta foto')
            ->assertSee('Cancelar');
    }

    public function test_uploader_mostra_a_foto_atual_ou_o_monograma(): void
    {
        $this->blade('<x-avatar-uploader model="photo" name="Maria Souza" current="/avatares/users/1" />')->assertSee('/avatares/users/1', false);
        $this->blade('<x-avatar-uploader model="photo" name="Maria Souza" />')->assertSee('MS');
    }

    public function test_trait_devolve_webp_quadrado_do_tamanho_pedido(): void
    {
        Livewire::test(AvatarReceiver::class)
            ->set('photo', UploadedFile::fake()->image('a.png', 800, 500))
            ->call('process')
            ->assertHasNoErrors()
            ->assertSet('side', 256);
    }

    public function test_trait_recusa_arquivo_acima_de_1mb(): void
    {
        Livewire::test(AvatarReceiver::class)
            ->set('photo', UploadedFile::fake()->create('grande.png', 1500, 'image/png'))
            ->call('process')
            ->assertHasErrors('photo');
    }

    public function test_trait_recusa_texto_renomeado_para_png(): void
    {
        Livewire::test(AvatarReceiver::class)
            ->set('photo', UploadedFile::fake()->createWithContent('a.png', 'isto nao e uma imagem'))
            ->call('process')
            ->assertHasErrors('photo');
    }

    public function test_trait_exige_a_foto(): void
    {
        Livewire::test(AvatarReceiver::class)->call('process')->assertHasErrors('photo');
    }
}
