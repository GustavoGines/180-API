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
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null; // Dejar que Laravel maneje errores no-API (vistas, etc)
            }

            $statusCode = 500;
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                $statusCode = $e->getStatusCode();
            }

            $response = [
                'message' => $e->getMessage(),
            ];

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $statusCode = 422;
                $response['errors'] = $e->errors();
            }

            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                $statusCode = 401;
                $response['message'] = 'No autenticado.';
            }

            // Debug info en local
            if (config('app.debug') && $statusCode >= 500) {
                $response['trace'] = $e->getTrace();
            }

            return response()->json($response, $statusCode);
        });
    })->create();
