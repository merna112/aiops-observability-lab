<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;


class TelemetryMiddleware
{

    public function handle($request, Closure $next)
    {

        $start = microtime(true);

        $requestId = $request->header('X-Request-Id') ?? Str::uuid();

        $response = $next($request);

        $latency = (microtime(true) - $start) * 1000;

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
            "severity" => "info",
            "build_version" => env("BUILD_VERSION"),
            "host" => gethostname()
        ]);

        $response->headers->set("X-Request-Id", $requestId);

        return $response;
    }
}