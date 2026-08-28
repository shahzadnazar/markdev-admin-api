<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_slots', function (Blueprint $table) {
            $table->id();
            // Admin-chosen, e.g. "Morning" — nothing about slots is fixed:
            // there is no set number of them and no reserved names.
            $table->string('name', 80);
            // A time of day, never a date. A slot is a recurring daily
            // schedule; the day always comes from the punch that is being
            // judged against it.
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('late_after_minutes')->default(15);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            // Soft deleted so a removed slot doesn't take its students'
            // assignment history down with it.
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_slots');
    }
};
