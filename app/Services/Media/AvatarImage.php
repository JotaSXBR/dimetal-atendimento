<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Intervention\Image\Laravel\Facades\Image;
use Throwable;

/**
 * Transforma qualquer imagem aceita em um WebP quadrado: recorte central cobrindo o quadrado e redução. Serve a foto do usuário
 * e o logo da empresa (o enquadramento fino é feito no navegador; aqui fica a revalidação e a rede de segurança).
 */
class AvatarImage
{
    public const SIZE_PERSON = 256;

    public const SIZE_LOGO = 256;

    /** Teto por lado: a checagem vem do cabeçalho, antes de decodificar, para uma imagem gigante não estourar a memória. */
    private const MAX_SIDE = 4096;

    private const TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function fromUpload(UploadedFile $file, int $size): string
    {
        return $this->fromBinary((string) $file->get(), $size);
    }

    /** @throws InvalidImage */
    public function fromBinary(string $bytes, int $size, int $maxBytes = 1_048_576): string
    {
        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            throw new InvalidImage('A imagem é maior que o limite de '.round($maxBytes / 1_048_576, 1).' MB.');
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info['mime'], self::TYPES, true)) {
            throw new InvalidImage('Use uma imagem PNG, JPG ou WebP.');
        }

        if ($info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            throw new InvalidImage('A imagem é grande demais (máximo de '.self::MAX_SIDE.' pixels por lado).');
        }

        try {
            return Image::decodeBinary($bytes)->cover($size, $size)->encodeUsingMediaType('image/webp', quality: 82)->toString();
        } catch (Throwable) {
            throw new InvalidImage('Não foi possível ler a imagem. Tente outro arquivo.');
        }
    }
}
