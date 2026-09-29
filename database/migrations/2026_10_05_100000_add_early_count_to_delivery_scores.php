<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The count of EARLY stints, beside the other four.
 *
 * ## Why this column exists
 *
 * The `better` early mode cannot separate two people who both read 100: the
 * score is capped at full marks, so somebody early on everything and somebody
 * on time on everything land on the same number. The dial's real effect is to
 * offset a late stint, and its label now says so.
 *
 * What actually distinguishes those two people is HOW MANY stints were early,
 * and the component drew four counts without that one. A list screen — the
 * scoreboard somebody would rank people from — reads this table rather than
 * recomputing, so the figure has to be stored here or it is simply not
 * available where it is needed.
 *
 * Existing rows come back as 0, which is wrong for anybody who has ever been
 * early. `delivery:recache` rebuilds every row from the raw stints and is safe
 * to run twice; it is the same catch-up the table needed when it was created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_scores', function (Blueprint $table) {
            // Beside late_count, which it is the counterpart of.
            $table->unsignedInteger('early_count')->default(0)->after('stints_completed');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_scores', function (Blueprint $table) {
            $table->dropColumn('early_count');
        });
    }
};
