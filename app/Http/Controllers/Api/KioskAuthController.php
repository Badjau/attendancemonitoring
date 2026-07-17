<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\KioskAuthSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KioskAuthController extends Controller
{
    public function __construct(protected KioskAuthSyncService $kioskAuthSyncService) {}

    public function manifest(): JsonResponse
    {
        return response()->json($this->kioskAuthSyncService->manifest());
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since_revision' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json(
            $this->kioskAuthSyncService->incrementalPayload((int) ($validated['since_revision'] ?? 0))
        );
    }

    public function full(): JsonResponse
    {
        return response()->json($this->kioskAuthSyncService->fullPayload());
    }
}
