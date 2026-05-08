<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('attendances', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('location');
            }

            if (! Schema::hasColumn('attendances', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }

            if (! Schema::hasColumn('attendances', 'location_status')) {
                $table->enum('location_status', ['inside', 'outside'])->nullable()->after('longitude');
            }

            if (! Schema::hasColumn('attendances', 'zone_id')) {
                $table->foreignId('zone_id')->nullable()->after('location_status')->constrained('zones')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasColumn('attendances', 'zone_id')) {
                $table->dropConstrainedForeignId('zone_id');
            }

            if (Schema::hasColumn('attendances', 'location_status')) {
                $table->dropColumn('location_status');
            }
        });
    }
};
