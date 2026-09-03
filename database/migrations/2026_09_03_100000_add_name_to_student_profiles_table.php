<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            // The admission record carries the student's name alongside the
            // father's name, so the profile reads as a whole document rather
            // than half a record that only makes sense joined to users.
            $table->string('name')->nullable()->after('user_id');
        });

        // Existing rows already have a name — on the account.
        DB::table('student_profiles')->update([
            'name' => DB::raw('(select name from users where users.id = student_profiles.user_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
