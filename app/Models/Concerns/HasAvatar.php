<?php

namespace App\Models\Concerns;

use App\Services\Media\AvatarStorage;

/**
 * Foto guardada no disco de mídia (`avatar_path`, `avatar_version`). O modelo informa o prefixo da chave em `avatarPrefix()`.
 *
 * @property string|null $avatar_path
 * @property string|null $avatar_version
 */
trait HasAvatar
{
    abstract protected function avatarPrefix(): string;

    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null ? null : route('avatar', [$this->getTable(), $this->getKey(), 'v' => $this->avatar_version]);
    }

    /** Grava o objeto novo, aponta as colunas para ele e só então apaga o antigo. */
    public function setAvatar(string $webp): void
    {
        $storage = app(AvatarStorage::class);
        $old = $this->avatar_path;
        $stored = $storage->store($this->avatarPrefix(), $webp);

        $this->forceFill(['avatar_path' => $stored['path'], 'avatar_version' => $stored['version']])->save();
        $storage->delete($old);
    }

    public function clearAvatar(): void
    {
        $old = $this->avatar_path;

        $this->forceFill(['avatar_path' => null, 'avatar_version' => null])->save();
        app(AvatarStorage::class)->delete($old);
    }
}
