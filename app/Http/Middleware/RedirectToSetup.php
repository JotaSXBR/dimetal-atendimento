<?php

namespace App\Http\Middleware;

use App\Services\Setup\SetupState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Instalação em configuração: leva ao /instalar (sem admin) ou ao /primeiros-passos (admin logado). */
class RedirectToSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        // Rotas liberadas primeiro: a restauração não pode consultar o banco que está sendo restaurado.
        if ($request->routeIs('instalar', 'primeiros-passos', 'logout', '*livewire.*', 'webhooks.waha', 'logo', 'restauracao*')
            || $request->is('broadcasting/auth')
            || SetupState::concluded()) {
            return $next($request);
        }

        if (! SetupState::hasAdmin()) {
            return redirect()->route('instalar');
        }

        if ($request->user()?->isAdmin()) {
            return redirect()->route('primeiros-passos');
        }

        return $next($request);
    }
}
