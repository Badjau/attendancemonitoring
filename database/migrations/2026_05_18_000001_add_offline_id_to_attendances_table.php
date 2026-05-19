<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('attendances', 'offline_id')) {
                $table->string('offline_id')->nullable()->unique()->after('attendance_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasColumn('attendances', 'offline_id')) {
                $table->dropUnique(['offline_id']);
                $table->dropColumn('offline_id');
            }
        });
    }
};
