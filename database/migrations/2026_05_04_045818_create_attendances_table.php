<?php

use App\Enums\Attendance\Status;
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
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // RFID
            $table->string('rfid_uid')->nullable();         // raw scanned RFID UID

            // Time tracking
            $table->date('attendance_date');                 // 2026-05-04
            $table->timestamp('time_in')->nullable();        // 2026-05-04 08:00:00
            $table->timestamp('time_out')->nullable();       // 2026-05-04 17:00:00
            $table->decimal('total_hours', 5, 2)->nullable(); // 9.00

            // Status
            $table->string('status')->default(Status::Present->value);
            // present, absent, late, half_day, on_leave, holiday

            // Late / undertime
            $table->boolean('is_late')->default(false);
            $table->integer('late_minutes')->default(0);     // minutes late from shift start
            $table->boolean('is_undertime')->default(false);
            $table->integer('undertime_minutes')->default(0);// minutes short from shift end

            // Overtime
            $table->boolean('is_overtime')->default(false);
            $table->integer('overtime_minutes')->default(0);
            $table->string('overtime_status')->nullable();
            // pending, approved, rejected

            // Location (if needed)
            $table->string('location')->nullable();          // office, remote, field
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            // Remarks
            $table->text('remarks')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
