<?php

namespace App\Http\Middleware;

use App\Support\ErrorCategorizer;
use App\Support\PrometheusMetricsStore;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;


class TelemetryMiddleware
{
    public function __construct(
        private readonly ErrorCategorizer $categorizer,
        private readonly PrometheusMetricsStore $metricsStore
    ) {
    }

    public function handle($request, Closure $next)
    {
        $start = microtime(true);
        $isMetricsEndpoint = $request->is('api/metrics');
        $requestId = (string) ($request->header('X-Request-Id') ?? Str::uuid());
        $request->headers->set('X-Request-Id', $requestId);

        $response = $next($request);
        $latencyMs = (microtime(true) - $start) * 1000;
        $statusCode = $response->getStatusCode();

        $responseCategory = $response->headers->get('X-Error-Category');
        $errorCategory = $this->categorizer->fromResponse($latencyMs, $statusCode, $responseCategory);
        $severity = $errorCategory ? 'error' : 'info';

        if (!$isMetricsEndpoint) {
            $this->metricsStore->observeRequest(
                $request->method(),
                $request->path(),
                (string) $statusCode,
                $errorCategory,
                $latencyMs / 1000
            );
        }

        $response->headers->set('X-Request-Id', $requestId);

        if ($isMetricsEndpoint) {
            return $response;
        }

        $responseContent = method_exists($response, 'getContent') ? $response->getContent() : null;
        $responseSize = is_string($responseContent) ? strlen($responseContent) : null;
        $errorMessage = $response->headers->get('X-Error-Message');

        $logRecord = [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'status_code' => $statusCode,
            'latency_ms' => round($latencyMs, 3),
            'client_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'query' => $request->getQueryString(),
            'payload_size_bytes' => strlen($request->getContent()),
            'response_size_bytes' => $responseSize,
            'route_name' => $request->route()?->getName() ?? 'unknown',
            'severity' => $severity,
            'build_version' => env('BUILD_VERSION'),
            'host' => gethostname() ?: null,
            'error_category' => $errorCategory,
            'error_message' => $errorMessage,
        ];

        if ($severity === 'error') {
            Log::error('request_log', $logRecord);
        } else {
            Log::info('request_log', $logRecord);
        }

        return $response;
    }
}
