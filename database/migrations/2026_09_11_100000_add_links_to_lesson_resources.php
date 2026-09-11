<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A lesson resource becomes a file OR a link.
 *
 * A discriminator rather than "just a nullable url", and the reason is that
 * `file_path` has to become nullable either way — a link has no file. Once
 * both columns are nullable, "exactly one of these is set" is an invariant
 * with nothing enforcing it: a row with both, or neither, is representable,
 * and every read site has to infer the type by checking which column happens
 * to be null. `kind` makes that invariant explicit, greppable and checkable in
 * the validator, and lets the UI choose download-versus-open without sniffing
 * columns.
 *
 * YouTube links are not a third kind. They are links whose host happens to be
 * YouTube, which the model derives for an icon; storing that would be storing
 * something already in the url.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->string('kind', 10)->default('file')->after('name');
            $table->string('url', 2000)->nullable()->after('kind');
        });

        // Every row that exists is a file — the table had no other option.
        DB::table('lesson_resources')->update(['kind' => 'file']);

        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->string('file_path')->nullable()->change();
            $table->string('file_type', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Links cannot be represented once the columns go, so they are removed
        // rather than left as rows with no file and no url.
        DB::table('lesson_resources')->where('kind', 'link')->delete();

        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->dropColumn(['kind', 'url']);
        });

        Schema::table('lesson_resources', function (Blueprint $table) {
            $table->string('file_path')->nullable(false)->change();
            $table->string('file_type', 50)->nullable(false)->change();
        });
    }
};
