<?php

namespace App\Http\Controllers;

use App\Services\Media\AvatarStorage;
use App\Settings\CompanySettings;
use Symfony\Component\HttpFoundation\Response;

/** O logo da empresa é público porque aparece na tela de login. */
class LogoController extends Controller
{
    public function __invoke(AvatarStorage $storage): Response
    {
        $path = CompanySettings::logoPath();

        abort_if($path === null, 404);

        return $storage->response($path, public: true);
    }
}
