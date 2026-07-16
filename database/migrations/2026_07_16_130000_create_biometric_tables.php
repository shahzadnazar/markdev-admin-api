<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The device-side user id (enrolled fingerprint/face template id).
        Schema::table('users', function (Blueprint $table) {
            $table->string('biometric_id', 64)->nullable()->unique()->after('avatar_path');
        });

        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('vendor')->nullable();
            $table->string('serial_number')->unique();
            $table->string('location')->nullable();
            /** Punches from this device mark attendance for this course. */
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->string('api_key', 64)->unique();
            /** Session start used to derive present vs late. */
            $table->time('session_start')->nullable();
            $table->unsignedInteger('late_after_minutes')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Where an attendance row came from.
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('notes');
            $table->foreignId('biometric_device_id')->nullable()->after('source')
                ->constrained('biometric_devices')->nullOnDelete();
        });

        Schema::create('biometric_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biometric_device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->string('biometric_id', 64)->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('punched_at')->index();
            $table->string('direction', 10)->nullable();
            $table->string('status', 20)->default('pending'); // pending|processed|unmatched|skipped
            $table->foreignId('attendance_record_id')->nullable()->constrained('attendance_records')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['biometric_device_id', 'biometric_id', 'punched_at'], 'biometric_punches_dedupe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_punches');

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('biometric_device_id');
            $table->dropColumn('source');
        });

        Schema::dropIfExists('biometric_devices');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('biometric_id');
        });
    }
};
