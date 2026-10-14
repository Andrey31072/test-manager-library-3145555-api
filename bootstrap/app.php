<?php

use App\Http\Middleware\ApiAuth;
use App\Http\Middleware\ApiCors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [ApiCors::class]);
        $middleware->alias(['api.auth' => ApiAuth::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->is('health')) {
                return null;
            }

            report($e);

            return response()->json([
                'code' => 'VALIDATION_ERROR',
                'message' => 'No se pudo procesar la solicitud.',
                'details' => [],
            ], 500);
        });
    })->create();
