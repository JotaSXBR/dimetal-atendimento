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

        $path = User::query()->findOrFail($id)->avatar_path;

        abort_if($path === null, 404);

        return $storage->response($path, public: false);
    }
}
