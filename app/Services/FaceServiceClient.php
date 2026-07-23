<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class FaceServiceClient
{
    public function deleteEmployeeCache(string $employeeId): Response
    {
        return $this->client()
            ->delete('/api/employees/'.rawurlencode($employeeId))
            ->throw();
    }

    public function rebuildCache(): Response
    {
        return $this->client()
            ->post('/api/cache/rebuild')
            ->throw();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.face.url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withoutVerifying()
            ->timeout(60);
    }
}
