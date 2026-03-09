<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use App\Support\ErrorCategorizer;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->api([
            \App\Http\Middleware\TelemetryMiddleware::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            $categorizer = app(ErrorCategorizer::class);
            $errorCategory = $categorizer->fromException($e);

            $statusCode = 500;
            if ($e instanceof ValidationException) {
                $statusCode = 422;
            } elseif ($e instanceof HttpExceptionInterface) {
                $statusCode = $e->getStatusCode();
            } elseif ($e instanceof QueryException) {
                $statusCode = 500;
            }

            $payload = [
                'message' => $e->getMessage(),
                'error_category' => $errorCategory,
            ];

            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            }

            return response()
                ->json($payload, $statusCode)
                ->header('X-Error-Category', $errorCategory)
                ->header('X-Error-Message', $e->getMessage());
        });
    })->create();
