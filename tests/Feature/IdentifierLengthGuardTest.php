<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordingBlueprint;
use Tests\TestCase;

/**
 * No migration generates an identifier MySQL cannot store.
 *
 * WHY. MySQL and MariaDB cap an identifier at 64 characters and refuse anything
 * longer with SQLSTATE[42000] 1059. Laravel names an unnamed index after the
 * table, then every column, then the type, so a long table with two long
 * columns crosses 64 without anybody writing a long name anywhere:
 *
 *   $table->index(['team_leave_application_id', 'status']);
 *
 * on team_leave_application_days is 66 characters, and it never worked. It was
 * reported from a real install, not found here.
 *
 * THE TESTS CANNOT CATCH THIS WITHOUT THIS GUARD. The suite runs on SQLite,
 * which has no identifier-length limit at all, so 1337 tests passed against a
 * migration that could not run in production. Exactly the MySQL-versus-SQLite
 * divergence DateColumnGuardTest exists for, one layer down: there the driver
 * disagrees about a value, here it disagrees about whether the schema is legal.
 *
 * Not theoretical. Run against MariaDB 10.11 the unfixed migration stops with
 *
 *   SQLSTATE[42000]: 1059 Identifier name
 *   'team_leave_application_days_team_leave_application_id_status_index'
 *   is too long
 *
 * and the fixed one carries all 83 migrations through to 90 tables.
 *
 * DERIVED, NOT LISTED. The guard runs every migration and asks the Blueprint
 * what it would name things, so a migration added next month is covered without
 * anybody remembering this file exists. A list of known-long names would have to
 * be maintained by the same memory that let this through.
 *
 * @see RecordingBlueprint for how the names are captured.
 * @see DateColumnGuardTest for the other half of the divergence.
 */
class IdentifierLengthGuardTest extends TestCase
{
    /**
     * MySQL's and MariaDB's limit, in characters.
     *
     * Not a style preference and not negotiable by raising it: the server
     * refuses to create the object.
     *
     * @see https://dev.mysql.com/doc/refman/8.4/en/identifier-length.html
     */
    protected const MAX_IDENTIFIER_LENGTH = 64;

    /**
     * Below this the capture is assumed broken rather than the schema clean.
     *
     * A guard that silently records nothing passes forever. The schema has
     * dozens of indexes and foreign keys, so a run that finds a handful has lost
     * its hook, not found a tidy database.
     */
    protected const MINIMUM_EXPECTED_IDENTIFIERS = 50;

    /**
     * The connection the migrations are replayed onto.
     *
     * Its own name so nothing in this file can be mistaken for a test against
     * the application's own database.
     */
    protected const THROWAWAY_CONNECTION = 'identifier_length_guard';

    /**
     * Every index, unique, full-text and foreign key the migrations create.
     *
     * The hook is a CONTAINER BINDING, not Schema::blueprintResolver. The
     * resolver would be the obvious choice and it does not work: the Schema
     * facade is not cached, so every `Schema::create` in every migration resolves
     * a brand-new builder and a resolver set on one of them is thrown away with
     * it. Builder::createBlueprint resolves Blueprint out of the container when
     * no resolver is set, so binding it there reaches every builder there will
     * ever be. The guard's own floor on the number of identifiers is what caught
     * this: the first version captured nothing and would otherwise have passed.
     *
     * IT MIGRATES ITS OWN THROWAWAY DATABASE, not the suite's. Two reasons. The
     * migrator only generates a name for a migration it actually runs, so
     * pointing this at a database something else has already migrated captures
     * nothing and the floor above turns the guard red for the wrong reason --
     * which is exactly what happens against a persistent test database, where
     * the tables survive between runs. And the driver is beside the point: the
     * name comes out of Blueprint::createIndexName from the table and the
     * columns, with no grammar involved, so it is the same name whatever this
     * connects to. An in-memory SQLite that lives for the length of this method
     * is the one target guaranteed to be empty.
     */
    protected function identifiers(): array
    {
        RecordingBlueprint::reset();

        $this->app->bind(Blueprint::class, fn ($app, array $parameters) => new RecordingBlueprint(
            $parameters['connection'],
            $parameters['table'],
            $parameters['callback'] ?? null,
        ));

        $original = config('database.default');

        config(['database.connections.'.self::THROWAWAY_CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // Matches the mysql connection, so a prefixed install generates the
            // same names here as it would there.
            'prefix_indexes' => true,
            'foreign_key_constraints' => true,
        ]]);

        try {
            $this->artisan('migrate', [
                '--database' => self::THROWAWAY_CONNECTION,
                '--force' => true,
            ])->assertExitCode(0);
        } finally {
            // migrate --database swaps the DEFAULT connection for the run.
            DB::setDefaultConnection($original);
        }

        return RecordingBlueprint::$identifiers;
    }

    public function test_every_generated_identifier_fits_in_a_mysql_identifier(): void
    {
        $identifiers = $this->identifiers();

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_EXPECTED_IDENTIFIERS,
            count($identifiers),
            'Only '.count($identifiers).' identifiers were captured, which means the Blueprint hook stopped working '
            .'rather than that the schema got tidier. Fix the capture before trusting a pass.',
        );

        $tooLong = array_filter(
            $identifiers,
            fn (array $identifier) => strlen($identifier['name']) > self::MAX_IDENTIFIER_LENGTH,
        );

        $this->assertSame([], array_values(array_map(
            fn (array $identifier) => sprintf(
                '%s: the %s on `%s` is named `%s` — %d characters, %d over the limit. '
                .'Pass an explicit short name as the last argument: $table->%s([%s], \'a_shorter_name\').',
                $identifier['file'],
                $identifier['type'],
                $identifier['table'],
                $identifier['name'],
                strlen($identifier['name']),
                strlen($identifier['name']) - self::MAX_IDENTIFIER_LENGTH,
                $identifier['type'],
                implode(', ', array_map(fn ($column) => "'".$column."'", $identifier['columns'])),
            ),
            $tooLong,
        )), 'MySQL and MariaDB refuse an identifier over '.self::MAX_IDENTIFIER_LENGTH.' characters with SQLSTATE[42000] 1059.');
    }

    /**
     * AN EXPLICIT NAME IS CHECKED, NOT SKIPPED.
     *
     * 2026_07_16_130000_create_biometric_tables.php passes
     * 'biometric_punches_dedupe' as the second argument, and it is right to: the
     * generated name would have been 68 characters, so that migration could not
     * have run either. A guard that treated "has an explicit name" as "must be
     * fine" would let a long explicit name through, and one that flagged this
     * line would be switched off within the week. Length is the only question
     * asked, whoever chose the name — so the name has to reach the check at all,
     * which is what this pins.
     */
    public function test_an_explicitly_named_index_is_checked_rather_than_skipped(): void
    {
        $names = array_column($this->identifiers(), 'name');

        $this->assertContains(
            'biometric_punches_dedupe',
            $names,
            'The explicitly named unique on biometric_punches never reached the check, so a long explicit name would not either.',
        );
    }

    /**
     * No two of them share a name.
     *
     * A second divergence, and a quieter one: MySQL requires a FOREIGN KEY name
     * to be unique across the whole DATABASE, not just its table, and refuses a
     * collision with errno 121 where SQLite does not care. Generated names carry
     * the table name so they cannot collide on their own; two explicit names that
     * match can, which is what this catches. Every type is checked rather than
     * just foreign keys -- stricter than MySQL needs for an index, and free,
     * because the generated names are distinct anyway. Nothing collides today.
     */
    public function test_no_two_identifiers_share_a_name(): void
    {
        $byName = [];

        foreach ($this->identifiers() as $identifier) {
            if (str_starts_with($identifier['type'], 'drop')) {
                continue;
            }

            $byName[$identifier['name']][] = $identifier['type'].' on `'.$identifier['table'].'` in '.$identifier['file'];
        }

        $collisions = array_filter($byName, fn (array $places) => count($places) > 1);

        $this->assertSame([], array_map(
            fn (array $places, string $name) => $name.': '.implode(' AND ', $places),
            $collisions,
            array_keys($collisions),
        ), 'MySQL scopes foreign key names to the database, not the table.');
    }

    /** Table names are capped by the same rule, and a long one is what pushes an index over. */
    public function test_every_table_name_fits_in_a_mysql_identifier(): void
    {
        $tables = array_unique(array_column($this->identifiers(), 'table'));

        foreach ($tables as $table) {
            $this->assertLessThanOrEqual(
                self::MAX_IDENTIFIER_LENGTH,
                strlen($table),
                'The table `'.$table.'` is '.strlen($table).' characters, which MySQL will not create.',
            );
        }
    }
}
