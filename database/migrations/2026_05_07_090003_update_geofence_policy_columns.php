<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zones', function (Blueprint $table) {
            if (! Schema::hasColumn('zones', 'policy')) {
                $table->enum('policy', ['strict', 'relaxed'])->default('relaxed')->after('radius_meters');
            }

            if (! Schema::hasColumn('zones', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('policy');
            }
        });

        Schema::table('zone_employee', function (Blueprint $table) {
            if (! Schema::hasColumn('zone_employee', 'is_temporary')) {
                $table->boolean('is_temporary')->default(false)->after('employee_id');
            }

            if (! Schema::hasColumn('zone_employee', 'effective_date')) {
                $table->date('effective_date')->nullable()->after('is_temporary');
            }

            if (! Schema::hasColumn('zone_employee', 'expiry_date')) {
                $table->date('expiry_date')->nullable()->after('effective_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('zone_employee', function (Blueprint $table) {
            foreach (['expiry_date', 'effective_date', 'is_temporary'] as $column) {
                if (Schema::hasColumn('zone_employee', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('zones', function (Blueprint $table) {
            foreach (['is_active', 'policy'] as $column) {
                if (Schema::hasColumn('zones', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
