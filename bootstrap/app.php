<?php

use App\Exceptions\ApiErrorRenderer;
use App\Exceptions\ApiException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateApiClient;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.api' => AuthenticateApiClient::class,
        ]);

        $middleware->api(prepend: [AssignRequestId::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errores de negocio esperados: no se registran como incidencias
        $exceptions->dontReport([ApiException::class]);

        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (Throwable $e, Request $request) {
            return $request->is('api/*') ? ApiErrorRenderer::render($e) : null;
        });
    })->create();
