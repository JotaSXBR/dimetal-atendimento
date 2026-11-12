<?php

namespace App\Http\Controllers;

use App\Services\Backup\Restore\RestoreProgress;
use Illuminate\Contracts\View\View;

/** A página de andamento da restauração: abre sem login e sem tocar no banco (ele troca durante o processo). */
class RestorePageController extends Controller
{
    public function __invoke(RestoreProgress $progress): View
    {
        return view('restore.progress', ['state' => $progress->get()]);
    }
}
