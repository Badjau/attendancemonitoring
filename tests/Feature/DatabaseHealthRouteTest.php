<?php

namespace Tests\Feature;

use Tests\TestCase;

class DatabaseHealthRouteTest extends TestCase
{
    public function test_database_health_route_reports_successful_connectivity(): void
    {
        $this->getJson('/health/database')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database.connected', true)
            ->assertJsonPath('database.connection', 'sqlite')
            ->assertJsonPath('database.driver', 'sqlite')
            ->assertJsonStructure([
                'database' => [
                    'latency_ms',
                ],
                'checked_at',
            ]);
    }
}
