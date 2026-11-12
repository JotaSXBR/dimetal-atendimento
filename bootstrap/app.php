<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // O Coolify expõe o app atrás do proxy/Traefik dele. Sem confiar no proxy, o Laravel
        // acha que o request chegou por HTTP: gera URLs http://, marca cookie como inseguro e
        // o redirect pós-login volta para http. Só as faixas privadas da rede Docker, onde fica o Traefik, são
        // confiáveis: quem chegar direto ao container não falsifica o próprio IP, que alimenta o limite do login.
        // Literal aqui porque a configuração ainda não existe quando este callback roda.
        $middleware->trustProxies(at: ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7']);

        // Global (não só `web`): as respostas de erro e as rotas fora do grupo `web` também levam os cabeçalhos.
        $middleware->append(SecurityHeaders::class);

        $middleware->validateCsrfTokens(except: [
            'webhooks/waha',
        ]);

        // A restauração do banco põe o sistema em manutenção por alguns segundos; a página de andamento e o JSON do estado
        // continuam no ar para o admin acompanhar (ver `RestorePageController`).
        $middleware->preventRequestsDuringMaintenance(except: ['restauracao', 'restauracao/estado']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
