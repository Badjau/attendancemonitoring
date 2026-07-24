<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_employee', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['branch_id', 'employee_id']);
            $table->index(['employee_id', 'is_primary']);
        });

        if (! Schema::hasColumn('employees', 'branch')) {
            return;
        }

        DB::table('employees')
            ->whereNotNull('branch')
            ->orderBy('id')
            ->get(['id', 'branch'])
            ->each(function (object $employee): void {
                $branchName = trim((string) $employee->branch);

                if ($branchName === '') {
                    return;
                }

                $branch = DB::table('branches')->where('name', $branchName)->first();

                if (! $branch) {
                    $branchId = DB::table('branches')->insertGetId([
                        'name' => $branchName,
                        'code' => strtoupper($branchName),
                        'latitude' => 0,
                        'longitude' => 0,
                        'radius_meters' => 150,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $branchId = $branch->id;
                }

                DB::table('branch_employee')->insertOrIgnore([
                    'branch_id' => $branchId,
                    'employee_id' => $employee->id,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_employee');
    }
};
