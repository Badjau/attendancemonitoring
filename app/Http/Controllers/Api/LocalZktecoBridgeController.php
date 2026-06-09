<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LocalZktecoBridgeController extends Controller
{
    private const ALLOWED_ENDPOINTS = [
        'status',
        'enroll',
        'commit-enrollment',
        'attendance',
        'finalize-attendance',
        'unlock',
    ];

    public function handle(Request $request, string $endpoint): JsonResponse
    {
        if (! in_array($endpoint, self::ALLOWED_ENDPOINTS, true)) {
            return response()->json(['message' => 'Endpoint not found.'], 404);
        }

        if ($endpoint === 'status' && ! $request->isMethod('GET')) {
            return response()->json(['message' => 'Method not allowed.'], 405);
        }

        if ($endpoint !== 'status' && ! $request->isMethod('POST')) {
            return response()->json(['message' => 'Method not allowed.'], 405);
        }

        try {
            $bridgeUrl = rtrim(config('services.zkteco.local_bridge_url'), '/').'/'.$endpoint;
            $response = $endpoint === 'status'
                ? Http::timeout(8)->acceptJson()->get($bridgeUrl)
                : Http::timeout(15)->acceptJson()->asJson()->post($bridgeUrl, $request->all());

            return response()->json(
                $response->json() ?? ['message' => $response->body()],
                $response->status(),
            );
        } catch (ConnectionException) {
            return response()->json([
                'message' => 'Fingerprint scanner bridge is not connected on this server.',
            ], 503);
        }
    }
}
