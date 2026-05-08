<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $employees = [
            ['employee_id' => '0000000001', 'first_name' => 'James', 'last_name' => 'Santos', 'middle_name' => 'R', 'date_of_birth' => '1990-01-15', 'position' => 'Sales Manager'],
            ['employee_id' => '0000000002', 'first_name' => 'Maria', 'last_name' => 'Reyes', 'middle_name' => 'C', 'date_of_birth' => '1988-03-22', 'position' => 'Accounting Officer'],
            ['employee_id' => '0000000003', 'first_name' => 'John', 'last_name' => 'Dela Cruz', 'middle_name' => 'B', 'date_of_birth' => '1992-07-10', 'position' => 'Sales Representative'],
            ['employee_id' => '0000000004', 'first_name' => 'Angela', 'last_name' => 'Mendoza', 'middle_name' => 'L', 'date_of_birth' => '1995-11-05', 'position' => 'HR Officer'],
            ['employee_id' => '0000000005', 'first_name' => 'Roberto', 'last_name' => 'Garcia', 'middle_name' => 'T', 'date_of_birth' => '1985-06-18', 'position' => 'Operations Manager'],
            ['employee_id' => '0000000006', 'first_name' => 'Stephanie', 'last_name' => 'Villanueva', 'middle_name' => 'A', 'date_of_birth' => '1993-09-30', 'position' => 'Marketing Officer'],
            ['employee_id' => '0000000007', 'first_name' => 'Mark', 'last_name' => 'Bautista', 'middle_name' => 'D', 'date_of_birth' => '1991-04-25', 'position' => 'IT Support'],
            ['employee_id' => '0000000008', 'first_name' => 'Christine', 'last_name' => 'Aquino', 'middle_name' => 'G', 'date_of_birth' => '1989-12-08', 'position' => 'Admin Assistant'],
            ['employee_id' => '0000000009', 'first_name' => 'Ronald', 'last_name' => 'Pascual', 'middle_name' => 'E', 'date_of_birth' => '1987-02-14', 'position' => 'Warehouse Supervisor'],
            ['employee_id' => '0000000010', 'first_name' => 'Josephine', 'last_name' => 'Castillo', 'middle_name' => 'M', 'date_of_birth' => '1994-08-20', 'position' => 'Customer Service'],
            ['employee_id' => '0003097269', 'first_name' => 'James Philip', 'last_name' => 'Gomera', 'middle_name' => 'A', 'date_of_birth' => '1996-05-12', 'position' => 'Junior Developer'],
        ];

        foreach ($employees as $employee) {
            DB::table('employees')->updateOrInsert(['employee_id' => $employee['employee_id']], $employee);
        }
    }
}
