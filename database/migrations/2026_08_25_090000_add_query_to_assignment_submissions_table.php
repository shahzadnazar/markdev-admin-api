<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Students ask the instructor a question about the assignment alongside the
 * work they hand in. Kept separate from `content` so the query is never
 * confused with the submission body the instructor grades.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->text('query')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->dropColumn('query');
        });
    }
};
