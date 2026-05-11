<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'rfid_uid')) {
                $table->string('rfid_uid')->nullable()->unique()->after('employee_id');
            }
        });

        Schema::table('timeclock_authorized_users', function (Blueprint $table) {
            if (! Schema::hasColumn('timeclock_authorized_users', 'employee_id')) {
                $table->foreignId('employee_id')->nullable()->unique()->after('id')->constrained('employees')->cascadeOnDelete();
            }

            if (Schema::hasColumn('timeclock_authorized_users', 'name')) {
                $table->dropColumn('name');
            }

            if (Schema::hasColumn('timeclock_authorized_users', 'rfid_uid')) {
                $table->dropUnique(['rfid_uid']);
                $table->dropColumn('rfid_uid');
            }

            if (Schema::hasColumn('timeclock_authorized_users', 'password')) {
                $table->dropColumn('password');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timeclock_authorized_users', function (Blueprint $table) {
            if (! Schema::hasColumn('timeclock_authorized_users', 'name')) {
                $table->string('name')->nullable()->after('id');
            }

            if (! Schema::hasColumn('timeclock_authorized_users', 'rfid_uid')) {
                $table->string('rfid_uid')->nullable()->unique()->after('name');
            }

            if (! Schema::hasColumn('timeclock_authorized_users', 'password')) {
                $table->string('password')->nullable()->after('rfid_uid');
            }

            if (Schema::hasColumn('timeclock_authorized_users', 'employee_id')) {
                $table->dropConstrainedForeignId('employee_id');
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'rfid_uid')) {
                $table->dropUnique(['rfid_uid']);
                $table->dropColumn('rfid_uid');
            }
        });
    }
};
