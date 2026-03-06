<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*', // Excluye todas las rutas de la API de la protección CSRF
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request, Throwable $e) {
            if ($request->is('api/*')) {
                return true;
            }

            return $request->expectsJson();
        });

        $exceptions->render(function (Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $statusCode = 500;
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    $statusCode = $e->getStatusCode();
                }

                $response = [
                    'message' => $e->getMessage(),
                ];

                // 🔥 Interceptar explícitamente errores de validación
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $statusCode = 422;
                    $response['message'] = 'Los datos enviados no son válidos.';
                    $response['errors'] = $e->errors();

                    return response()->json($response, $statusCode);
                }

                if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    return response()->json(['message' => 'No autenticado.'], 401);
                }

                if (config('app.debug') && $statusCode >= 500) {
                    $response['trace'] = $e->getTrace();
                }

                return response()->json($response, $statusCode);
            }

            // Para entorno no-API, dejar comportamiento por defecto
            return null;
        });
    })->create();
