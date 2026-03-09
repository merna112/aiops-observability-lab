<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Throwable;
use Prometheus\CollectorRegistry;


class TelemetryMiddleware
{

    public function handle($request, Closure $next)
    {

        $start = microtime(true);

        $requestId = $request->header('X-Request-Id') ?? Str::uuid();

        try {
            $response = $next($request);
            $latency = (microtime(true) - $start) * 1000;

            $errorCategory = null;
            if ($latency > 4000) {
                $errorCategory = "TIMEOUT_ERROR";
            }

            // Update metrics
            if ($request->path() !== 'api/metrics') {
                $registry = app(CollectorRegistry::class);
                $requestsCounter = $registry->getCounter('http', 'requests_total');
                $requestsCounter->inc([
                    $request->method(),
                    $request->path(),
                    (string) $response->status()
                ]);

                if ($errorCategory) {
                    $errorsCounter = $registry->getCounter('http', 'errors_total');
                    $errorsCounter->inc([
                        $request->method(),
                        $request->path(),
                        $errorCategory
                    ]);
                }

                $histogram = $registry->getHistogram('http', 'request_duration_seconds');
                $histogram->observe($latency / 1000, [
                    $request->method(),
                    $request->path()
                ]);
            }

            Log::info("request_log", [
                "request_id" => $requestId,
                "method" => $request->method(),
                "path" => $request->path(),
                "status_code" => $response->status(),
                "latency_ms" => $latency,
                "client_ip" => $request->ip(),
                "user_agent" => $request->userAgent(),
                "query" => $request->getQueryString(),
                "payload_size_bytes" => strlen($request->getContent()),
                "response_size_bytes" => strlen($response->getContent()),
                "route_name" => $request->route()?->getName() ?? "unknown",
                "severity" => $errorCategory ? "error" : "info",
                "build_version" => env("BUILD_VERSION"),
                "host" => gethostname(),
                "error_category" => $errorCategory
            ]);

            $response->headers->set("X-Request-Id", $requestId);

            return $response;
        } catch (Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;

            $errorCategory = "UNKNOWN";
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $errorCategory = "VALIDATION_ERROR";
            } elseif ($e instanceof \Illuminate\Database\QueryException) {
                $errorCategory = "DATABASE_ERROR";
            } elseif ($e instanceof \Exception) {
                $errorCategory = "SYSTEM_ERROR";
            }

            // Update metrics
            if ($request->path() !== 'api/metrics') {
                $registry = app(CollectorRegistry::class);
                $requestsCounter = $registry->getCounter('http', 'requests_total');
                $requestsCounter->inc([
                    $request->method(),
                    $request->path(),
                    '500'
                ]);

                $errorsCounter = $registry->getCounter('http', 'errors_total');
                $errorsCounter->inc([
                    $request->method(),
                    $request->path(),
                    $errorCategory
                ]);

                $histogram = $registry->getHistogram('http', 'request_duration_seconds');
                $histogram->observe($latency / 1000, [
                    $request->method(),
                    $request->path()
                ]);
            }

            Log::error("request_log", [
                "request_id" => $requestId,
                "method" => $request->method(),
                "path" => $request->path(),
                "status_code" => 500,
                "latency_ms" => $latency,
                "client_ip" => $request->ip(),
                "user_agent" => $request->userAgent(),
                "query" => $request->getQueryString(),
                "payload_size_bytes" => strlen($request->getContent()),
                "response_size_bytes" => null,
                "route_name" => $request->route()?->getName() ?? "unknown",
                "severity" => "error",
                "build_version" => env("BUILD_VERSION"),
                "host" => gethostname(),
                "error_category" => $errorCategory,
                "error_message" => $e->getMessage()
            ]);

            throw $e;
        }
    }
}