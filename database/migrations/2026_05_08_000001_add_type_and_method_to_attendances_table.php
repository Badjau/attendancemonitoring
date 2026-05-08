<?php

use App\Enums\Attendance\Type;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->string('attendance_type')
                ->default(Type::TimeIn->value)
                ->after('rfid_uid');
            $table->string('attendance_method')
                ->nullable()
                ->after('attendance_type');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['attendance_type', 'attendance_method']);
        });
    }
};
