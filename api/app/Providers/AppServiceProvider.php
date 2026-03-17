<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Support\ErrorCategorizer;
use App\Support\PrometheusMetricsStore;
use App\Services\PrometheusClient;
use App\Services\BaselineComputer;
use App\Services\AnomalyDetector;
use App\Services\EventCorrelator;
use App\Services\IncidentManager;
use App\Services\AlertManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ErrorCategorizer::class, fn () => new ErrorCategorizer());
        $this->app->singleton(PrometheusMetricsStore::class, fn () => new PrometheusMetricsStore());

        // AIOps Detection Engine services
        $this->app->singleton(PrometheusClient::class, fn () => new PrometheusClient());
        $this->app->singleton(BaselineComputer::class, fn ($app) => new BaselineComputer($app->make(PrometheusClient::class)));
        $this->app->singleton(AnomalyDetector::class, fn () => new AnomalyDetector());
        $this->app->singleton(EventCorrelator::class, fn () => new EventCorrelator());
        $this->app->singleton(IncidentManager::class, fn () => new IncidentManager());
        $this->app->singleton(AlertManager::class, fn () => new AlertManager());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
