<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/** A página de andamento da restauração. Provisória: a Task 7 liga a tela. */
class RestorePageController extends Controller
{
    public function __invoke(): Response
    {
        return response('Restauração do banco');
    }
}
