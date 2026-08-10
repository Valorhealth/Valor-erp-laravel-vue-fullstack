<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $startedAt = hrtime(true);

        try {
            DB::selectOne('SELECT 1 AS healthy');

            return response()->json([
                'status' => 'ok',
                'database' => 'connected',
                'database_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'database' => 'unavailable',
            ], 503);
        }
    }
}
