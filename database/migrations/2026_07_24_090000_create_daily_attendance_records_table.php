<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 10); // present | late | absent | leave
            $table->string('remarks', 500)->nullable();
            $table->string('source', 12)->default('manual'); // manual | biometric

            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at');

            // Post-marking corrections are PIN-gated and always carry a reason.
            $table->foreignId('last_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('last_update_reason', 500)->nullable();
            $table->timestamp('last_updated_at')->nullable();

            $table->timestamps();
            $table->unique(['user_id', 'date']);
            $table->index(['date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_attendance_records');
    }
};
