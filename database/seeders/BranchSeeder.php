<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Esquivel', 'Apo', 'Cebu'] as $branch) {
            Branch::query()->updateOrCreate(
                ['name' => $branch],
                [
                    'code' => strtoupper($branch),
                    'latitude' => 0,
                    'longitude' => 0,
                    'radius_meters' => 150,
                    'is_active' => true,
                ],
            );
        }
    }
}
