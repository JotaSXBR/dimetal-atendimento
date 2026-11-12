<?php

namespace App\Http\Controllers;

use App\Services\Backup\Restore\RestoreProgress;
use Illuminate\Http\JsonResponse;

/**
 * O estado da restauração em andamento (passos e mensagem, sem usuário, arquivo nem segredo). Fica fora da manutenção e não
 * consulta o banco, que troca durante a restauração: o estado vem do Redis.
 */
class RestoreStatusController extends Controller
{
    public function __invoke(RestoreProgress $progress): JsonResponse
    {
        return response()->json($progress->get())->header('Cache-Control', 'no-store, private');
    }
}
