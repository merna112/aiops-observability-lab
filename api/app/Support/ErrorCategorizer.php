<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

class ErrorCategorizer
{
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const DATABASE_ERROR = 'DATABASE_ERROR';
    public const TIMEOUT_ERROR = 'TIMEOUT_ERROR';
    public const SYSTEM_ERROR = 'SYSTEM_ERROR';
    public const UNKNOWN = 'UNKNOWN';

    public function timeoutThresholdMs(): float
    {
        return (float) env('TIMEOUT_THRESHOLD_MS', 4000);
    }

    public function fromException(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return self::VALIDATION_ERROR;
        }

        if ($exception instanceof QueryException) {
            return self::DATABASE_ERROR;
        }

        return self::SYSTEM_ERROR;
    }

    public function fromResponse(float $latencyMs, int $statusCode, ?string $responseCategory): ?string
    {
        if ($latencyMs > $this->timeoutThresholdMs()) {
            return self::TIMEOUT_ERROR;
        }

        if ($responseCategory) {
            return $responseCategory;
        }

        if ($statusCode >= 400) {
            return self::UNKNOWN;
        }

        return null;
    }
}
