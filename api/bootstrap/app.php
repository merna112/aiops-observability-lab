<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->api([
            \App\Http\Middleware\TelemetryMiddleware::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->report(function (Throwable $e) {

            $category = "UNKNOWN";

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $category = "VALIDATION_ERROR";
            } elseif ($e instanceof \Illuminate\Database\QueryException) {
                $category = "DATABASE_ERROR";
            } elseif ($e instanceof \Exception) {
                $category = "SYSTEM_ERROR";
            }

            \Log::error("application_error", [
                "error_category" => $category,
                "message" => $e->getMessage()
            ]);

        });

    })->create();
