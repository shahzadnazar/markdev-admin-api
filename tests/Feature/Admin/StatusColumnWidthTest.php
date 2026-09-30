<?php

namespace Tests\Feature\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\Concerns\ReplaysMigrations;
use Tests\Feature\DateColumnGuardTest;
use Tests\Feature\IdentifierLengthGuardTest;
use Tests\Support\RecordingBlueprint;
use Tests\TestCase;

/**
 * Every value a model can write must fit the column that stores it.
 *
 * `leave_applications.status` was 12 characters wide for
 * `pending | approved | rejected`. Adding `partially_approved` — 18 — made a
 * partial approval fail on MySQL with "Data too long for column 'status'",
 * and no test caught it because SQLite does not enforce VARCHAR length at all:
 * it will store 35 characters in a varchar(10) and say nothing. The suite wrote
 * the over-long value happily on every run.
 *
 * WIDTHS COME FROM THE MIGRATIONS, not from the database, and they have to.
 * Laravel's SQLite grammar compiles `string('status', 24)` to a bare `varchar`
 * with no length, so the number does not survive the migration — pragma
 * table_info reports "varchar" and nothing else. The Blueprint is the last place
 * the 24 exists, and it is the same 24 the MySQL grammar would have written, so
 * this check fails on either engine.
 *
 * ASKED OF THE BLUEPRINT, not scraped out of the source. Reading the files with
 * a regular expression was the first attempt and it silently missed
 * `task_statuses` and `project_statuses`, because their migration creates both
 * in a `foreach` over a variable table name — there is no `Schema::create(
 * 'task_statuses'` anywhere to match. Replaying the migrations and recording
 * what each Blueprint declared gets those, `->change()` and anything else a
 * migration can legally do, for free.
 *
 * THE COLUMNS ARE DERIVED, NOT LISTED. This was a hardcoded list of five columns
 * across four academy tables, and the list is what let the whole team portal slip
 * past it: seven columns with fixed value sets, none of them covered. A list also rots in
 * place — its entry for `daily_attendance_records.source` said
 * `['manual', 'biometric', 'auto']` while the code had grown `slot`, `academy`
 * and `class`. Adding the team columns to it would just have reset the clock.
 *
 * So the pairs are discovered from the models: a constant holding a fixed set
 * is matched to the column it feeds by name, and every token in it is measured
 * against that column's declared width. A model written next month is covered
 * without anybody remembering this file. Thirteen columns today, where the list
 * had five.
 *
 * ONE COLUMN THE LIST HAD IS GONE, and deliberately.
 * `daily_attendance_records.source` has no fixed set anywhere in PHP: its values
 * are literals in five files — 'manual' in the controller, 'biometric' in the
 * service, 'slot' or 'academy' in the API, and ClassAttendanceBackfill::SOURCE,
 * which is not a model at all. There is nothing for this to derive from, which is
 * the actual defect; the list's answer was to write the values out by hand, and
 * they had already drifted. Give that column a SOURCES constant and it is
 * covered the moment the constant exists.
 *
 * IT IS THE KEYS THAT ARE STORED, NOT THE LABELS. `ClientQuestion::STATUSES` is
 * `'closed' => 'Closed without an answer'`; the column holds `closed`, six
 * characters, and the 24-character label is never written anywhere. Getting that
 * backwards makes a perfectly sound column look like it overflows. Rather than
 * trusting keys-versus-values, a candidate has to be TOKEN-SHAPED —
 * lowercase, digits and underscores — which is what a stored token looks like
 * and what a human-readable label never is.
 *
 * @see IdentifierLengthGuardTest for the same divergence in the schema
 * @see DateColumnGuardTest for the same divergence in a value
 */
class StatusColumnWidthTest extends TestCase
{
    use ReplaysMigrations;

    /**
     * What a stored token looks like.
     *
     * `partially_approved`, `on_time`, `closed_abandoned` match. `In progress`,
     * `Closed without an answer` and `Awaiting a reply` do not, which is how the
     * labels in a key => label constant are kept out of the comparison without
     * this having to know which half of the array is which.
     */
    protected const TOKEN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    /**
     * Constants that name a column rather than holding a value.
     *
     * Eloquent's own timestamp constants. Their values are column names, so if
     * a table ever had a `created` column they would be measured against it.
     */
    protected const NAMES_NOT_VALUES = ['CREATED_AT', 'UPDATED_AT', 'DELETED_AT'];

    /**
     * Below this the discovery is assumed broken rather than the schema tidy.
     *
     * Thirteen pairs are found today. A run that finds three has lost its grip
     * on the models, and a guard that discovers nothing passes forever — which
     * is the one failure mode a derived guard has that a list does not.
     */
    protected const MINIMUM_EXPECTED_COLUMNS = 10;

    /* ------------------------------ The checks ------------------------------ */

    public function test_every_stored_value_fits_the_column_that_stores_it(): void
    {
        $discovered = $this->discover();

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_EXPECTED_COLUMNS,
            count($discovered['columns']),
            'Only '.count($discovered['columns']).' fixed-set columns were discovered, which means the discovery '
            .'stopped working rather than that the models got simpler.',
        );

        foreach ($discovered['columns'] as $target => $found) {
            foreach ($found['values'] as $value => $constant) {
                $this->assertLessThanOrEqual(
                    $found['width'],
                    strlen((string) $value),
                    "'{$value}' is ".strlen((string) $value)." characters but {$target} is {$found['width']}. "
                    ."It comes from {$constant}. MySQL refuses the write with error 1406; SQLite does not enforce "
                    .'VARCHAR length at all, so only this check sees it.',
                );
            }
        }
    }

    /**
     * The column the original incident was about is actually found.
     *
     * A named anchor, because the floor above only counts. If discovery quietly
     * stopped matching STATUSES to `status` this would say so, where a count of
     * thirteen made up of something else would not.
     */
    public function test_discovery_finds_the_column_that_started_this(): void
    {
        $columns = $this->discover()['columns'];

        $this->assertArrayHasKey('leave_applications.status', $columns);
        $this->assertSame(32, $columns['leave_applications.status']['width']);
        $this->assertArrayHasKey('partially_approved', $columns['leave_applications.status']['values']);
    }

    /** And the team-portal columns a hardcoded list had no reason to contain. */
    public function test_discovery_reaches_the_team_portal(): void
    {
        $columns = $this->discover()['columns'];

        foreach ([
            'team_leave_applications.status',
            'team_leave_application_days.status',
            'team_attendance_records.status',
            'task_assignments.outcome',
            'client_questions.status',
            'task_statuses.behaviour',
            'project_statuses.behaviour',
        ] as $target) {
            $this->assertArrayHasKey($target, $columns, $target.' is a fixed-set column and was not discovered.');
        }
    }

    /**
     * THE TRAP, PINNED. The key is measured and the label is not.
     *
     * `client_questions.status` is 20 characters and holds `closed`. Measuring
     * the label instead makes it look 24 characters over-long, which is exactly
     * the wrong conclusion somebody already reached once.
     */
    public function test_a_label_is_never_mistaken_for_a_stored_value(): void
    {
        $values = $this->discover()['columns']['client_questions.status']['values'];

        $this->assertArrayHasKey('closed', $values, 'The stored key has to be measured.');
        $this->assertArrayNotHasKey(
            'Closed without an answer',
            $values,
            'The human-readable label is never written to the column and must not be measured against it.',
        );
        $this->assertSame(['open', 'answered', 'closed'], array_keys($values));
    }

    /**
     * No token was found that could belong to more than one column.
     *
     * A bare `PENDING = 'pending'` says which value but not which column, so it
     * is attributed to the model's one fixed-set column. A model with two of
     * them makes that a guess, and a guard that guesses is a guard that either
     * misses something or cries wolf. It reports instead.
     */
    public function test_no_constant_is_left_unattributable(): void
    {
        $this->assertSame([], $this->discover()['ambiguous'], 'Name it with its column, e.g. STATUS_PENDING.');
    }

    /* ---------------------------- The discovery ----------------------------- */

    /**
     * Fixed-set columns and the tokens written to them, read out of the models.
     *
     * Three rules, in order, and the third depends on the first two:
     *
     *   A  an ARRAY constant whose singularised name is a sized string column
     *      on the model's table -- STATUSES to `status`, OUTCOMES to `outcome`,
     *      BEHAVIOURS to `behaviour`, CHANNELS to `channel`.
     *   B  a SCALAR constant named after a column, STATUS_PROCESSED to
     *      `status`. The longest matching prefix wins.
     *   C  a SCALAR constant whose name is its own value -- PENDING = 'pending'
     *      -- which says the value but not the column. Those go to the model's
     *      single fixed-set column, and are reported rather than guessed at when
     *      there is more than one. DailyAttendance::PENDING and HOLIDAY are the
     *      reason this rule exists: both are deliberately outside STATUSES and
     *      both are written to `status`.
     *
     * @return array{columns: array<string, array{width: int, values: array<string, string>}>, ambiguous: array<int, string>}
     */
    protected function discover(): array
    {
        $widths = $this->declaredStringWidths();
        $columns = [];
        $ambiguous = [];

        foreach ($this->models() as $class => $model) {
            $table = $model->getTable();
            $tableWidths = $widths[$table] ?? [];
            $constants = (new ReflectionClass($class))->getConstants();
            $found = [];

            foreach ($constants as $name => $value) {
                if (in_array($name, self::NAMES_NOT_VALUES, true)) {
                    continue;
                }

                // A plural name for an array, a prefix for either: STATUSES
                // and STATUS_SET both name `status`, and STATUS_PROCESSED does
                // too. Whichever way somebody names the constant, the column it
                // feeds is found.
                $column = (is_array($value) ? $this->columnNamedByPlural($name, $tableWidths) : null)
                    ?? $this->columnNamedByPrefix($name, $tableWidths);

                if ($column === null) {
                    continue;
                }

                foreach ($this->tokensIn($value) as $token) {
                    $found[$column][$token] = $class.'::'.$name;
                }
            }

            // Rule C, once the columns this model writes to are known.
            foreach ($constants as $name => $value) {
                if (in_array($name, self::NAMES_NOT_VALUES, true) || ! is_string($value)) {
                    continue;
                }

                if (strtolower($name) !== $value || ! preg_match(self::TOKEN, $value)) {
                    continue;
                }

                if (count($found) === 1) {
                    $found[array_key_first($found)][$value] = $class.'::'.$name;
                } elseif (count($found) > 1) {
                    $ambiguous[] = $class.'::'.$name." = '{$value}' could belong to any of "
                        .implode(', ', array_keys($found));
                }
            }

            foreach ($found as $column => $values) {
                $target = $table.'.'.$column;
                $columns[$target]['width'] = $tableWidths[$column];
                $columns[$target]['values'] = array_merge($columns[$target]['values'] ?? [], $values);
            }
        }

        ksort($columns);

        return ['columns' => $columns, 'ambiguous' => $ambiguous];
    }

    /** STATUSES => `status`, but only if the table really has that column. */
    protected function columnNamedByPlural(string $constant, array $tableWidths): ?string
    {
        $column = Str::singular(strtolower($constant));

        return array_key_exists($column, $tableWidths) ? $column : null;
    }

    /** STATUS_PROCESSED => `status`. Longest prefix that names a column wins. */
    protected function columnNamedByPrefix(string $constant, array $tableWidths): ?string
    {
        $parts = explode('_', strtolower($constant));

        for ($take = count($parts) - 1; $take >= 1; $take--) {
            $column = implode('_', array_slice($parts, 0, $take));

            if (array_key_exists($column, $tableWidths)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * The token-shaped strings in a constant, whichever half of an array they are.
     *
     * @return array<int, string>
     */
    protected function tokensIn(mixed $value): array
    {
        $candidates = is_array($value)
            ? array_merge(array_keys($value), array_values($value))
            : [$value];

        return array_values(array_unique(array_filter(
            $candidates,
            fn ($candidate) => is_string($candidate) && preg_match(self::TOKEN, $candidate),
        )));
    }

    /** @return array<class-string, Model> */
    protected function models(): array
    {
        $models = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            try {
                $models[$class] = new $class;
            } catch (\Throwable) {
                continue;
            }
        }

        return $models;
    }

    /**
     * table => column => width, folded from the replayed migrations in order.
     *
     * The last declaration wins, so a later `->change()` that widens a column is
     * what counts rather than the original `Schema::create`. Only `string` and
     * `char` carry a width worth measuring; `enum` and `set` are deliberately
     * left out, because MySQL constrains their values itself, which is stronger
     * than a width, so measuring one would be a false alarm waiting to happen.
     * A dropped column cancels its own declaration.
     *
     * @return array<string, array<string, int>>
     */
    protected function declaredStringWidths(): array
    {
        $this->replayMigrations();

        $widths = [];

        foreach (RecordingBlueprint::$declarations as $declaration) {
            [$table, $column] = [$declaration['table'], $declaration['name']];

            if (in_array($declaration['type'], ['string', 'char'], true) && $declaration['length'] !== null) {
                $widths[$table][$column] = $declaration['length'];

                continue;
            }

            // Retyped to something without a width, or dropped outright.
            unset($widths[$table][$column]);
        }

        return $widths;
    }
}
