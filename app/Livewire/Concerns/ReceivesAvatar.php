<?php

namespace App\Livewire\Concerns;

use App\Services\Media\AvatarImage;
use App\Services\Media\InvalidImage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Recebe o recorte enviado por `<x-avatar-uploader>` (o navegador já reduz e enquadra) e o entrega como WebP quadrado.
 * O componente que usa a trait decide o que fazer com o resultado (foto do usuário, logo...).
 */
trait ReceivesAvatar
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $photo = null;

    /** @throws ValidationException */
    protected function avatarWebp(int $size): string
    {
        $this->validate(
            ['photo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:1024']],
            [
                'photo.required' => 'Escolha uma imagem.',
                'photo.mimes' => 'Use uma imagem PNG, JPG ou WebP.',
                'photo.max' => 'A imagem pode ter até 1 MB.',
            ],
        );

        assert($this->photo instanceof TemporaryUploadedFile);

        try {
            return app(AvatarImage::class)->fromUpload($this->photo, $size);
        } catch (InvalidImage $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        } finally {
            $this->photo = null;
        }
    }
}
