<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $departments = [
            [
                'name' => 'IT',
            ],
            [
                'name' => 'HRAD',
            ],
            [
                'name' => 'Customer Service',
            ],
            [
                'name' => 'Warehouse',
            ],
            [
                'name' => 'Logistics',
            ],
            [
                'name' => 'Demand Planning',
            ],
            [
                'name' => 'Quality Assurance',
            ],
            [
                'name' => 'Sales-Retail',
            ],
            [
                'name' => 'Sales-FSy',
            ],
            [
                'name' => 'Sales-Non Food',
            ],
            [
                'name' => 'Sales-Beverage',
            ],
            [
                'name' => 'Sales-Frozen',
            ],
            [
                'name' => 'Accounting',
            ],
            [
                'name' => 'Treasury',
            ],
            [
                'name' => 'Credit and Collections',
            ],
            [
                'name' => 'Finance',
            ],
            [
                'name' => 'Purchasing',
            ],
            [
                'name' => 'Business Dev/Office of the President',
            ],
            [
                'name' => 'Culinary Solutions',
            ],
            [
                'name' => 'General Admin Services & Production',
            ],
            [
                'name' => 'Retail-Marketing',
            ],
            [
                'name' => 'Technical Services',
            ],
        ];

        foreach ($departments as $department) {
            DB::table('departments')->updateOrInsert(['name' => $department['name']], $department);
        }
    }
}
