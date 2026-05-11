<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timeclock_unlock_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timeclock_authorized_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method');
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('unlocked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeclock_unlock_logs');
    }
};
