<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Human program length ("3 months", "12 weeks") — what the academy
            // sells, as opposed to duration_minutes (total content length).
            $table->string('duration_label', 50)->nullable()->after('duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('duration_label');
        });
    }
};
