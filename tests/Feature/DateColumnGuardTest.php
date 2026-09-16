<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * No date-cast column is ever compared with equality, anywhere.
 *
 * WHY. A `date`-cast attribute serialises through the connection's datetime
 * format, so the same row stores "2026-09-11 00:00:00" on SQLite and
 * "2026-09-11" on MySQL, whose DATE column truncates it. An equality lookup
 * therefore matches on one driver and misses on the other — and the tests run
 * on the driver where it silently passes. TEN incidents so far: a wrong
 * absence fine, duplicate register rows, a test that passed while the bug it
 * covered was live, a security hole no test could reach, a crashed seeding
 * script, and a bulk mark-present that threw a unique violation.
 *
 * Every one of those was caught by a person remembering the rule. This is the
 * layer that does not depend on anyone remembering.
 *
 * THE COLUMNS ARE DERIVED, NOT LISTED. The guard reads the models' own casts,
 * so a new date-cast column is covered the moment it is added and nobody has
 * to update a list here. It also means the guard cannot silently drift out of
 * step with what the application actually casts.
 *
 * SCOPE. app/, database/ (seeders and migrations — the ninth incident was in a
 * seeding script) and tests/ (a test that plants the bug proves nothing if the
 * guard ignores it).
 *
 * @see \App\Models\Concerns\ScopesToDay for the one sanctioned form.
 */
class DateColumnGuardTest extends TestCase
{
    /**
     * Files allowed to contain a banned form, and why.
     *
     * Deliberately tiny. An entry here is a promise that the file demonstrates
     * the bug on purpose — not a place to park one that is inconvenient to fix.
     */
    protected const ALLOWED = [
        // Proves the old updateOrCreate still misses its own row when handed a
        // Carbon. It has to contain the bad form to show it failing.
        'tests/Feature/Admin/BulkPresentPendingRowTest.php',
    ];

    /**
     * Every attribute any model casts to a date.
     *
     * @return array<int, string>
     */
    protected function dateCastColumns(): array
    {
        $columns = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            try {
                $model = new $class;
            } catch (\Throwable) {
                continue;
            }

            foreach ($model->getCasts() as $attribute => $cast) {
                if ($cast === 'date' || str_starts_with((string) $cast, 'date:')) {
                    $columns[$attribute] = true;
                }
            }
        }

        ksort($columns);

        return array_keys($columns);
    }

    /**
     * Code only.
     *
     * This codebase has tripped THREE guards on their own docblock explaining
     * the rule they enforce, and a guard that fires on its own explanation is
     * a guard people delete. The trait, this file and the README all spell the
     * banned forms out in prose on purpose.
     */
    protected function stripComments(string $code): string
    {
        return preg_replace(
            ['#/\*[\s\S]*?\*/#', '#^\s*//.*$#m'],
            ' ',
            $code,
        );
    }

    /**
     * The banned forms, as name => regex.
     *
     * @return array<string, string>
     */
    protected function bannedForms(string $columnAlternation): array
    {
        $col = "['\"](?:{$columnAlternation})['\"]";

        // Anything that is NOT a comparison operator in the second argument —
        // i.e. a two-argument where, which Laravel reads as equality. Ordered
        // longest-first so '>=' is not half-matched by '>'.
        $notAnOperator = "(?!\\s*['\"](?:<=|>=|<>|!=|<|>|like|not like)['\"]\\s*,)";

        return [
            // (?:->|::) because Model::where('date', ...) is the commonest
            // form of all, and a guard that only saw ->where() would have been
            // blind to it — which is exactly what the first draft of this did.
            'where() equality on a date column' =>
                "/(?:->|::)where\\(\\s*{$col}\\s*,\\s*{$notAnOperator}/",
            "where() with an explicit '='" =>
                "/(?:->|::)where\\(\\s*{$col}\\s*,\\s*['\"]=['\"]/",
            'whereDate() on a date column' =>
                "/(?:->|::)whereDate\\(\\s*{$col}/",
            'whereIn() on a date column' =>
                "/(?:->|::)whereIn\\(\\s*{$col}/",
            'a date column in a firstOrCreate/updateOrCreate/upsert key' =>
                "/(?:firstOrCreate|updateOrCreate|firstOrNew|upsert)\\(\\s*\\[[^\\]]{0,300}?{$col}\\s*=>/s",
        ];
    }

    /** @return array<int, string> every .php file under the guarded roots */
    protected function guardedFiles(): array
    {
        $files = [];

        foreach ([app_path(), base_path('database'), base_path('tests')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    public function test_the_guard_knows_which_columns_are_date_cast(): void
    {
        $columns = $this->dateCastColumns();

        // A sanity floor: if the derivation breaks, the guard would quietly
        // scan for nothing and pass forever.
        $this->assertGreaterThanOrEqual(5, count($columns), 'the cast derivation found almost nothing');

        foreach (['date', 'from_date', 'to_date', 'date_of_joining'] as $expected) {
            $this->assertContains($expected, $columns);
        }
    }

    public function test_the_guard_scans_the_whole_codebase_not_just_app(): void
    {
        $files = $this->guardedFiles();
        $relative = array_map(fn (string $f) => str_replace(base_path().'/', '', $f), $files);

        $this->assertGreaterThan(200, count($files));

        foreach (['app/', 'database/migrations/', 'database/seeders/', 'tests/'] as $root) {
            $this->assertNotEmpty(
                array_filter($relative, fn (string $f) => str_starts_with($f, $root)),
                "the guard is not looking in {$root} — the ninth incident was in a seeding script",
            );
        }
    }

    public function test_no_date_cast_column_is_compared_with_equality_anywhere(): void
    {
        $columns = $this->dateCastColumns();
        $alternation = implode('|', array_map('preg_quote', $columns));
        $forms = $this->bannedForms($alternation);

        $offences = [];

        foreach ($this->guardedFiles() as $path) {
            $relative = str_replace(base_path().'/', '', $path);

            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            $code = $this->stripComments(file_get_contents($path));

            foreach ($forms as $name => $pattern) {
                if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as [$_, $offset]) {
                        $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                        $offences[] = "{$relative}:{$line} — {$name}";
                    }
                }
            }
        }

        sort($offences);

        $this->assertSame(
            [],
            $offences,
            "A date-cast column is being compared with equality. This has caused TEN bugs in this project, "
                ."because the same row stores '2026-09-11 00:00:00' on SQLite and '2026-09-11' on MySQL, so the "
                ."lookup matches on one driver and misses on the other — and these tests run on the one where it "
                ."silently passes.\n\n"
                ."Use App\\Models\\Concerns\\ScopesToDay instead:\n"
                ."    ->onDate(\$day)                     one day\n"
                ."    ->onDates([\$a, \$b])                several days, instead of whereIn\n"
                ."    ->betweenDates(\$from, \$to)         an inclusive range\n"
                ."    ->fromDate(\$day) / ->untilDate(\$day) / ->beforeDate(\$day)\n"
                ."    Model::forDay(\$match, \$day, \$values)  instead of firstOrCreate/updateOrCreate\n"
                ."    Model::dayKey(\$day)                 the canonical 'Y-m-d' to WRITE\n\n"
                ."Offending:\n  ".implode("\n  ", $offences)."\n",
        );
    }
}
