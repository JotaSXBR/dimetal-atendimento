<?php

namespace Tests\Unit;

use App\Services\Media\AvatarImage;
use App\Services\Media\InvalidImage;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AvatarImageTest extends TestCase
{
    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    public function test_png_retangular_vira_webp_quadrado_do_tamanho_pedido(): void
    {
        $out = (new AvatarImage)->fromBinary($this->png(600, 300), 256);

        $info = getimagesizefromstring($out);
        $this->assertSame(256, $info[0]);
        $this->assertSame(256, $info[1]);
        $this->assertSame('image/webp', $info['mime']);
    }

    /** @return array<string, array{string}> */
    public static function invalidContents(): array
    {
        return [
            'gif' => ["GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"],
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>'],
            'texto' => ['oi, sou texto'],
            'vazio' => [''],
        ];
    }

    #[DataProvider('invalidContents')]
    public function test_gif_svg_texto_e_vazio_sao_recusados(string $content): void
    {
        $this->expectException(InvalidImage::class);

        (new AvatarImage)->fromBinary($content, 256);
    }

    public function test_arquivo_acima_do_limite_e_recusado(): void
    {
        $this->expectException(InvalidImage::class);

        (new AvatarImage)->fromBinary($this->png(600, 300), 256, maxBytes: 100);
    }

    public function test_imagem_gigante_em_pixels_e_recusada_antes_de_decodificar(): void
    {
        $this->expectException(InvalidImage::class);

        (new AvatarImage)->fromBinary($this->png(5000, 5000), 256, maxBytes: 5_000_000);
    }

    public function test_extensao_mente_mas_o_conteudo_vale(): void
    {
        $file = UploadedFile::fake()->createWithContent('foto.png', $this->jpeg(300, 200));

        $out = (new AvatarImage)->fromUpload($file, AvatarImage::SIZE_PERSON);

        $this->assertSame('image/webp', getimagesizefromstring($out)['mime']);
    }
}
