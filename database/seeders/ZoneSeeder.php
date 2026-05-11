<?php

namespace Database\Seeders;

use App\Models\Zone;
use Illuminate\Database\Seeder;

class ZoneSeeder extends Seeder
{
    public function run(): void
    {
        Zone::updateOrCreate(
            ['name' => 'Main Office'],
            [
                // Update these placeholder coordinates to the actual office location before going live.
                'latitude' => 0.0000000,
                'longitude' => 0.0000000,
                'radius_meters' => 10,
                'policy' => 'strict',
                'is_active' => true,
            ],
        );
    }
}
