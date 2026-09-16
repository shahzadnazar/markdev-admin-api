<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records in the schema itself that enrollments.progress_percent is derived.
 *
 * It used to BE the progress figure — completed lessons over total lessons,
 * written once and read everywhere. It is now a cache of a four-component
 * calculation done on read, and a column that looks authoritative but is not is
 * exactly the kind of thing someone reads straight off in a report six months
 * from now. The comment travels with the database rather than only with the
 * code, so a DBA looking at the table sees it too.
 *
 * Comments only; no data changes and no column type changes. SQLite has no
 * column comments at all, so this is a no-op there — which is why it is guarded
 * rather than written with ->change(), and why the tests, which run on SQLite,
 * are unaffected either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->comment(
            'DERIVED CACHE of CourseProgressCalculator — rewritten on component events. '
            .'Never read for a single student; compute live from the raw records instead.'
        );
    }

    public function down(): void
    {
        $this->comment('');
    }

    protected function comment(string $text): void
    {
        if (! Schema::hasTable('enrollments')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(sprintf(
                "alter table `enrollments` modify `progress_percent` decimal(5,2) not null default 0 comment %s",
                DB::connection()->getPdo()->quote($text),
            ));

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(sprintf(
                'comment on column enrollments.progress_percent is %s',
                DB::connection()->getPdo()->quote($text),
            ));
        }

        // SQLite: nothing to do. The model's docblock is the record there.
    }
};
