<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\User;
use App\Services\Media\AvatarStorage;
use App\Services\Media\ContactPhotoProxy;
use Symfony\Component\HttpFoundation\Response;

/** Foto de usuário (do disco) e de contato (repassada do CDN, sem gravar). Só depois do login; a versão (`v`) só serve de cache. */
class AvatarController extends Controller
{
    public function __invoke(string $type, int $id, AvatarStorage $storage, ContactPhotoProxy $proxy): Response
    {
        if ($type === 'contacts') {
            return $proxy->response(Contact::query()->findOrFail($id));
        }

        $user = User::query()->findOrFail($id);

        abort_if($user->avatar_path === null, 404);

        return $storage->response($user->avatar_path, public: false, versioned: request()->query('v') === $user->avatar_version);
    }
}
