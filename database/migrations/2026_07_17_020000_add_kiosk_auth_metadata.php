<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'auth_revision')) {
                $table->unsignedBigInteger('auth_revision')->default(1)->after('role');
            }

            if (! Schema::hasColumn('employees', 'kiosk_pin_verifier')) {
                $table->string('kiosk_pin_verifier', 64)->nullable()->after('password');
            }
        });

        Schema::table('face_embeddings', function (Blueprint $table): void {
            if (! Schema::hasColumn('face_embeddings', 'embedding_revision')) {
                $table->unsignedBigInteger('embedding_revision')->default(1)->after('embedding');
            }
        });

        Schema::table('attendances', function (Blueprint $table): void {
            if (! Schema::hasColumn('attendances', 'auth_cache_revision')) {
                $table->unsignedBigInteger('auth_cache_revision')->nullable()->after('offline_id');
            }

            if (! Schema::hasColumn('attendances', 'cache_state_at_record_time')) {
                $table->string('cache_state_at_record_time', 24)->nullable()->after('auth_cache_revision');
            }

            if (! Schema::hasColumn('attendances', 'matched_auth_revision')) {
                $table->unsignedBigInteger('matched_auth_revision')->nullable()->after('cache_state_at_record_time');
            }

            if (! Schema::hasColumn('attendances', 'auth_metadata')) {
                $table->json('auth_metadata')->nullable()->after('matched_auth_revision');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            foreach (['auth_metadata', 'matched_auth_revision', 'cache_state_at_record_time', 'auth_cache_revision'] as $column) {
                if (Schema::hasColumn('attendances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('face_embeddings', function (Blueprint $table): void {
            if (Schema::hasColumn('face_embeddings', 'embedding_revision')) {
                $table->dropColumn('embedding_revision');
            }
        });

        Schema::table('employees', function (Blueprint $table): void {
            foreach (['kiosk_pin_verifier', 'auth_revision'] as $column) {
                if (Schema::hasColumn('employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
