<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->json('embedding');
            $table->string('image_hash', 64)->index();
            $table->string('pose_label')->nullable();
            $table->string('model_name')->default('SFace');
            $table->string('detector_backend')->default('yunet');
            $table->json('quality')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_embeddings');
    }
};
