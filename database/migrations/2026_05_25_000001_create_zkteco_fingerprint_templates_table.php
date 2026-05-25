<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zkteco_fingerprint_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('finger_index')->default(1);
            $table->longText('template_base64');
            $table->string('template_format')->default('zkteco-v10');
            $table->string('device_serial')->nullable();
            $table->unsignedSmallInteger('template_size')->nullable();
            $table->longText('fingerprint_image_base64')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'finger_index']);
            $table->index('device_serial');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zkteco_fingerprint_templates');
    }
};
