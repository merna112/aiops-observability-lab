<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Support\ErrorCategorizer;
use App\Support\PrometheusMetricsStore;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ErrorCategorizer::class, fn () => new ErrorCategorizer());
        $this->app->singleton(PrometheusMetricsStore::class, fn () => new PrometheusMetricsStore());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
