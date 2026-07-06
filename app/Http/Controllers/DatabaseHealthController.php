<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $startedAt = microtime(true);
        $connectionName = config('database.default');

        try {
            $connection = DB::connection();
            $connection->select('select 1 as health_check');

            return response()->json([
                'status' => 'ok',
                'database' => [
                    'connected' => true,
                    'connection' => $connectionName,
                    'driver' => $connection->getDriverName(),
                    'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                ],
                'checked_at' => now()->toISOString(),
            ]);
        } catch (Throwable $exception) {
            return response()->json([
                'status' => 'error',
                'database' => [
                    'connected' => false,
                    'connection' => $connectionName,
                    'driver' => config("database.connections.{$connectionName}.driver"),
                    'latency_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                    'error' => config('app.debug') ? $exception->getMessage() : 'Database connection failed.',
                ],
                'checked_at' => now()->toISOString(),
            ], 503);
        }
    }
}
