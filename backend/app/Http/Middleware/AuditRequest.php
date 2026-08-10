<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class AuditRequest
{
    public function __construct(private readonly AuditLogger $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->logger->shouldLog($request)) {
            return $next($request);
        }

        $startedAt = microtime(true);
        $actorBefore = $request->user();
        // Capture upload metadata before controllers move or delete temporary files.
        $requestData = $this->logger->sanitizedRequestData($request);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $status = $exception instanceof ValidationException
                ? $exception->status
                : (method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500);
            $response = response()->json(['message' => $exception->getMessage()], $status);
            $this->logger->record($request, $response, $startedAt, $actorBefore, $requestData);
            throw $exception;
        }

        $this->logger->record($request, $response, $startedAt, $actorBefore, $requestData);

        return $response;
    }
}
