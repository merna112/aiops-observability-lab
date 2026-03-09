<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Prometheus\Counter;
use Prometheus\Histogram;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CollectorRegistry::class, function () {
            $registry = new CollectorRegistry(new InMemory());
            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $registry = app(CollectorRegistry::class);

        // Counter for total requests
        $registry->registerCounter(
            'http',
            'requests_total',
            'Total HTTP requests',
            ['method', 'path', 'status']
        );

        // Counter for errors
        $registry->registerCounter(
            'http',
            'errors_total',
            'Total HTTP errors',
            ['method', 'path', 'error_category']
        );

        // Histogram for request duration
        $registry->registerHistogram(
            'http',
            'request_duration_seconds',
            'HTTP request duration in seconds',
            ['method', 'path'],
            [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, INF]
        );
    }
}
