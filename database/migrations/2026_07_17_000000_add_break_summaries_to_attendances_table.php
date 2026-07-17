<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->unsignedInteger('break_count')->default(0)->after('total_hours');
            $table->unsignedInteger('break_minutes')->default(0)->after('break_count');
            $table->unsignedInteger('break_exceeded_minutes')->default(0)->after('break_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn([
                'break_count',
                'break_minutes',
                'break_exceeded_minutes',
            ]);
        });
    }
};
