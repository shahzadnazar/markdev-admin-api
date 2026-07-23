<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('reg_no')->unique();

            // Personal information (per the MarkDev paper registration form).
            $table->string('father_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('cnic', 20)->nullable()->unique();
            $table->string('guardian_contact', 30)->nullable();
            $table->string('current_qualification')->nullable();
            $table->string('applied_course')->nullable();

            // Emergency contact details.
            $table->string('emergency_name')->nullable();
            $table->string('emergency_contact', 30)->nullable();
            $table->string('emergency_relation', 50)->nullable();
            $table->string('emergency_residence')->nullable();

            // Office use only.
            $table->date('date_of_joining')->nullable();
            $table->string('reference')->nullable();
            $table->decimal('total_fee', 10, 2)->nullable();
            $table->decimal('submitted_fee', 10, 2)->nullable();
            $table->decimal('registration_fee', 10, 2)->nullable();

            // Documents (public disk paths; each capped at 1 MB on upload).
            $table->string('photo_path')->nullable();
            $table->string('cnic_doc_path')->nullable();
            $table->string('degree_doc_path')->nullable();

            $table->timestamp('terms_accepted_at')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
