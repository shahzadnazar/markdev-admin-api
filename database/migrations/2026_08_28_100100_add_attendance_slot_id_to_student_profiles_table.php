<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            // Which daily slot this student attends. Nullable: students
            // admitted before slots existed keep falling back to the
            // academy-wide day start.
            $table->foreignId('attendance_slot_id')
                ->nullable()
                ->after('batch_no')
                ->constrained('attendance_slots')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_slot_id');
        });
    }
};
