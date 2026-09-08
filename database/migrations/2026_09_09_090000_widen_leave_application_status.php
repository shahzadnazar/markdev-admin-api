<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens `leave_applications.status` so a partial approval can be saved.
 *
 * The column was created at 12 characters for `pending | approved | rejected`.
 * 369155f then added `partially_approved`, which is 18, and reviewing a
 * three-day request as two-approved-one-declined failed on MySQL with
 * "Data too long for column 'status'". The whole review rolled back, so no
 * application was left half-decided — but no partial approval could be saved
 * at all.
 *
 * It went unnoticed because SQLite, which the test suite runs on, does not
 * enforce VARCHAR length: every test wrote the 18-character value happily.
 * A static guard now checks each status constant against the width declared
 * for its column, which fails on any engine — see LeaveApplicationTest.
 *
 * 32 rather than 18: the point of a length here is to stop nonsense, not to
 * fit today's longest word exactly, and the next status to be added should
 * not need another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_applications', function (Blueprint $table) {
            $table->string('status', 32)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Not narrowed back: any row holding `partially_approved` would be
        // truncated or refused, and that is a worse state than a wide column.
    }
};
