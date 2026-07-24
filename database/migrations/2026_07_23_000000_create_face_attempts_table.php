<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->string('candidate_employee_identifier')->nullable()->index();
            $table->string('decision')->index();
            $table->string('reason_code')->nullable()->index();
            $table->decimal('match_score', 6, 4)->nullable();
            $table->decimal('liveness_score', 6, 4)->nullable();
            $table->decimal('quality_score', 6, 4)->nullable();
            $table->decimal('risk_score', 6, 4)->nullable();
            $table->unsignedInteger('frame_count')->default(0);
            $table->unsignedInteger('usable_frame_count')->default(0);
            $table->unsignedInteger('matched_frame_count')->default(0);
            $table->boolean('fallback_used')->default(false)->index();
            $table->boolean('suspicious')->default(false)->index();
            $table->string('device_id')->nullable();
            $table->string('session_id')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('attempted_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_attempts');
    }
};
