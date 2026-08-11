<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Per-student read receipts for lesson resources (study materials). */
    public function up(): void
    {
        Schema::create('material_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_resource_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['user_id', 'lesson_resource_id']);
        });
    }

    public function down(): void
    {
        Schema::drop('material_reads');
    }
};
