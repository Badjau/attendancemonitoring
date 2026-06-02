<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add indexes to attendances table for frequently queried columns
        Schema::table('attendances', function (Blueprint $table) {
            $table->index('employee_id');
            $table->index('attendance_date');
            $table->index(['employee_id', 'attendance_date']); // Compound index for common queries
            $table->index('recorded_by');
            $table->index('zone_id');
        });

        // Add indexes to employees table
        Schema::table('employees', function (Blueprint $table) {
            $table->index('employee_id'); // For lookups by employee_id
            $table->index('department_id'); // For joins
        });

        // Add indexes to zone_employee pivot table
        Schema::table('zone_employee', function (Blueprint $table) {
            $table->index('employee_id');
            $table->index('zone_id');
            $table->index(['zone_id', 'employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex(['employee_id']);
            $table->dropIndex(['attendance_date']);
            $table->dropIndex(['employee_id', 'attendance_date']);
            $table->dropIndex(['recorded_by']);
            $table->dropIndex(['zone_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['employee_id']);
            $table->dropIndex(['department_id']);
        });

        Schema::table('zone_employee', function (Blueprint $table) {
            $table->dropIndex(['employee_id']);
            $table->dropIndex(['zone_id']);
            $table->dropIndex(['zone_id', 'employee_id']);
        });
    }
};
