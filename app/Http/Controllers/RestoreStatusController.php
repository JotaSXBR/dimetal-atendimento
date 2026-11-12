<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/** O estado da restauração em andamento. Provisório: a Task 7 liga o `RestoreProgress`. */
class RestoreStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([])->header('Cache-Control', 'no-store');
    }
}
