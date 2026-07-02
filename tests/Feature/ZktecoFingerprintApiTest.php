<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ZktecoFingerprintTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ZktecoFingerprintApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.zkteco.scanner_token', 'scanner-token');
    }

    public function test_it_returns_a_fingerprint_manifest_revision_for_the_local_agent(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'EMP-001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01',
            'position' => 'Analyst',
            'branch' => 'Cebu',
        ]);

        ZktecoFingerprintTemplate::query()->create([
            'employee_id' => $employee->id,
            'finger_index' => 1,
            'template_base64' => base64_encode('template-one'),
            'template_format' => 'zkteco-v10',
            'enrolled_at' => now(),
        ]);

        $this
            ->withToken('scanner-token')
            ->getJson('/api/zkteco/fingerprints/manifest')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonStructure([
                'revision',
                'count',
                'last_updated_at',
            ]);
    }

    public function test_it_returns_paginated_fingerprint_fields_needed_for_local_cache_rebuilds(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'EMP-002',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'date_of_birth' => '1990-01-01',
            'position' => 'Engineer',
            'branch' => 'Apo',
        ]);
        $templateBase64 = base64_encode('template-two');

        ZktecoFingerprintTemplate::query()->create([
            'employee_id' => $employee->id,
            'finger_index' => 2,
            'template_base64' => $templateBase64,
            'template_format' => 'zkteco-v10',
            'template_size' => strlen($templateBase64),
            'enrolled_at' => now(),
        ]);

        $this
            ->withToken('scanner-token')
            ->getJson('/api/zkteco/fingerprints')
            ->assertOk()
            ->assertJsonPath('data.0.employee_id', $employee->id)
            ->assertJsonPath('data.0.employee_code', 'EMP-002')
            ->assertJsonPath('data.0.template_hash', hash('sha256', $templateBase64))
            ->assertJsonPath('data.0.employee.employee_id', 'EMP-002')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'employee_id',
                    'employee_code',
                    'finger_index',
                    'template_base64',
                    'template_hash',
                    'enrolled_at',
                    'updated_at',
                ]],
                'pagination',
            ]);
    }
}
